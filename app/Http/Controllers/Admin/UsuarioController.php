<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AccesoAprobado;
use App\Models\EventoAuditoria;
use App\Models\Matricula;
use App\Models\Rol;
use App\Models\SesionSso;
use App\Models\Usuario;
use App\Support\Auditoria;
use App\Support\Bloqueo;
use App\Support\SesionesSso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Usuarios de todos los sistemas: alta, edición, baja, desbloqueo y cambio de contraseña.
 * El detalle muestra desde dónde y con qué equipo entró cada uno.
 */
class UsuarioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $usuarios = Usuario::query()
            ->with(['matriculas.sistema:id,codigo,nombre', 'matriculas.rol:id,codigo,nombre'])
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('correo', 'like', "%{$q}%")
                ->orWhere('nombres', 'like', "%{$q}%")
                ->orWhere('apellidos', 'like', "%{$q}%")))
            ->when($request->query('estado'), fn ($b, $estado) => $b->where('estado', $estado))
            ->orderBy('nombres')
            ->paginate(25);

        return response()->json([
            'data' => collect($usuarios->items())->map(fn (Usuario $u) => $this->datos($u)),
            'meta' => ['total' => $usuarios->total(), 'pagina' => $usuarios->currentPage(), 'paginas' => $usuarios->lastPage()],
        ]);
    }

    public function show(Usuario $usuario): JsonResponse
    {
        $usuario->load(['matriculas.sistema:id,codigo,nombre', 'matriculas.rol:id,codigo,nombre']);
        $sesiones = $usuario->sesiones()->with('dispositivo')->withCount('accesos')
            ->orderByDesc('iniciada_en')->limit(15)->get()
            ->map(fn (SesionSso $s) => [
                'id' => $s->id,
                'ip' => $s->ip,
                'zona_horaria' => $s->zona_horaria,
                'idioma' => $s->idioma,
                'iniciada_en' => $s->iniciada_en,
                'ultima_actividad_en' => $s->ultima_actividad_en,
                'cerrada_en' => $s->cerrada_en,
                'motivo_cierre' => $s->motivo_cierre,
                'vigente' => $s->estaVigente(),
                'accesos' => $s->accesos_count,
                'dispositivo' => $s->dispositivo?->only(['navegador', 'navegador_version', 'sistema_operativo', 'so_version', 'tipo']),
            ]);

        return response()->json(['data' => $this->datos($usuario) + ['sesiones' => $sesiones]]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombres' => ['required', 'string', 'max:120'],
            'apellidos' => ['nullable', 'string', 'max:120'],
            'correo' => ['required', 'email', 'max:255', Rule::unique('usuarios', 'correo')],
            'contrasena' => ['required', 'string', 'min:10', 'max:255'],
        ], $this->mensajes());
        $admin = $request->attributes->get('admin');

        $usuario = Usuario::create([
            'nombres' => $datos['nombres'],
            'apellidos' => $datos['apellidos'] ?? null,
            'correo' => Str::lower(trim($datos['correo'])),
            'contrasena' => $datos['contrasena'],
            'creado_por' => $admin->id,
        ]);
        Auditoria::registrar(EventoAuditoria::USUARIO_CREADO, $usuario, actor: $admin);

        return response()->json(['data' => $this->datos($usuario->load('matriculas'))], 201);
    }

    public function update(Request $request, Usuario $usuario): JsonResponse
    {
        $datos = $request->validate([
            'nombres' => ['sometimes', 'required', 'string', 'max:120'],
            'apellidos' => ['sometimes', 'nullable', 'string', 'max:120'],
            'correo' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('usuarios', 'correo')->ignore($usuario->id)],
            'estado' => ['sometimes', Rule::in([Usuario::ACTIVO, Usuario::BAJA])],
        ], $this->mensajes());
        $admin = $request->attributes->get('admin');

        if (isset($datos['estado']) && $usuario->estado === Usuario::PENDIENTE) {
            return response()->json(['message' => 'Es una solicitud pendiente: apruébala o recházala.'], 422);
        }
        if (($datos['estado'] ?? null) === Usuario::BAJA && $usuario->is($admin)) {
            return response()->json(['message' => 'No puedes darte de baja a ti mismo.'], 422);
        }
        if (isset($datos['correo'])) {
            $datos['correo'] = Str::lower(trim($datos['correo']));
        }

        $usuario->forceFill($datos)->save();
        if (($datos['estado'] ?? null) === Usuario::BAJA) {
            Bloqueo::revocarTokens($usuario);
            SesionesSso::cerrarTodas($usuario, SesionSso::CIERRE_REVOCADA);
        }
        Auditoria::registrar(EventoAuditoria::USUARIO_ACTUALIZADO, $usuario, actor: $admin, detalle: ['campos' => array_keys($datos)]);

        return response()->json(['data' => $this->datos($usuario->load('matriculas'))]);
    }

    /**
     * Aprueba una solicitud de acceso hecha con Google. Opcionalmente asigna de una vez los roles
     * (matrículas) y avisa por correo al usuario.
     */
    public function aprobar(Request $request, Usuario $usuario): JsonResponse
    {
        if ($usuario->estado !== Usuario::PENDIENTE) {
            return response()->json(['message' => 'Este usuario no tiene una solicitud pendiente.'], 422);
        }
        $datos = $request->validate([
            'roles' => ['sometimes', 'array', 'max:20'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')],
        ]);
        $admin = $request->attributes->get('admin');

        DB::transaction(function () use ($usuario, $datos, $admin) {
            $usuario->forceFill(['estado' => Usuario::ACTIVO, 'aprobado_por' => $admin->id, 'aprobado_en' => now()])->save();
            foreach (Rol::whereIn('id', $datos['roles'] ?? [])->get() as $rol) {
                Matricula::updateOrCreate(
                    ['usuario_id' => $usuario->id, 'sistema_id' => $rol->sistema_id, 'rol_id' => $rol->id],
                    ['estado' => Matricula::ACTIVA, 'vigente_desde' => null, 'vigente_hasta' => null, 'creado_por' => $admin->id],
                );
            }
        });
        Auditoria::registrar(EventoAuditoria::USUARIO_APROBADO, $usuario, actor: $admin, detalle: ['roles' => $datos['roles'] ?? []]);

        $usuario->load('matriculas.sistema:id,codigo,nombre', 'matriculas.rol:id,codigo,nombre');
        try {
            Mail::to($usuario->correo)->send(new AccesoAprobado(
                $usuario->nombres,
                $usuario->matriculas->where('estado', Matricula::ACTIVA)->map(fn (Matricula $m) => $m->sistema?->nombre)->filter()->unique()->values()->all(),
            ));
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar la aprobación: '.$e->getMessage());
        }

        return response()->json(['data' => $this->datos($usuario)]);
    }

    /** Rechaza una solicitud: la cuenta queda de baja y no puede volver a pedir acceso con Google. */
    public function rechazar(Request $request, Usuario $usuario): JsonResponse
    {
        if ($usuario->estado !== Usuario::PENDIENTE) {
            return response()->json(['message' => 'Este usuario no tiene una solicitud pendiente.'], 422);
        }
        $usuario->forceFill(['estado' => Usuario::BAJA])->save();
        Auditoria::registrar(EventoAuditoria::USUARIO_RECHAZADO, $usuario, actor: $request->attributes->get('admin'));

        return response()->json(['data' => $this->datos($usuario->load('matriculas'))]);
    }

    public function desbloquear(Request $request, Usuario $usuario): JsonResponse
    {
        if (! $usuario->estaBloqueado()) {
            return response()->json(['message' => 'La cuenta no está bloqueada.'], 422);
        }
        Bloqueo::desbloquear($usuario, $request->attributes->get('admin'));

        return response()->json(['data' => $this->datos($usuario->fresh('matriculas'))]);
    }

    /** Nueva contraseña definida por el administrador. Cierra las sesiones abiertas del usuario. */
    public function cambiarContrasena(Request $request, Usuario $usuario): JsonResponse
    {
        $datos = $request->validate([
            'contrasena' => ['required', 'string', 'min:10', 'max:255'],
        ], $this->mensajes());

        $usuario->forceFill(['contrasena' => $datos['contrasena'], 'contrasena_cambiada_en' => now()])->save();
        Bloqueo::revocarTokens($usuario);
        SesionesSso::cerrarTodas($usuario, SesionSso::CIERRE_REVOCADA);
        Auditoria::registrar(EventoAuditoria::CONTRASENA_RESTABLECIDA, $usuario, actor: $request->attributes->get('admin'));

        return response()->json(null, 204);
    }

    /** Cierra todas las sesiones del usuario en todos los navegadores y sistemas. */
    public function cerrarSesiones(Request $request, Usuario $usuario): JsonResponse
    {
        Bloqueo::revocarTokens($usuario);
        SesionesSso::cerrarTodas($usuario, SesionSso::CIERRE_REVOCADA);
        Auditoria::registrar(EventoAuditoria::USUARIO_ACTUALIZADO, $usuario, actor: $request->attributes->get('admin'), detalle: ['accion' => 'cerrar_sesiones']);

        return response()->json(null, 204);
    }

    private function datos(Usuario $u): array
    {
        return [
            'id' => $u->id,
            'uuid' => $u->uuid,
            'nombres' => $u->nombres,
            'apellidos' => $u->apellidos,
            'correo' => $u->correo,
            'estado' => $u->estado,
            'intentos_fallidos' => $u->intentos_fallidos,
            'bloqueado_en' => $u->bloqueado_en,
            'bloqueado_motivo' => $u->bloqueado_motivo,
            'ultimo_ingreso_en' => $u->ultimo_ingreso_en,
            'ultima_ip' => $u->ultima_ip,
            'creado_en' => $u->creado_en,
            'origen' => $u->origen,
            'con_google' => $u->google_sub !== null,
            'aprobado_en' => $u->aprobado_en,
            'matriculas' => $u->matriculas->map(fn (Matricula $m) => [
                'id' => $m->id,
                'sistema' => $m->sistema?->only(['id', 'codigo', 'nombre']),
                'rol' => $m->rol?->only(['id', 'codigo', 'nombre']),
                'estado' => $m->estado,
                'vigente_hasta' => $m->vigente_hasta,
                'ultimo_acceso_en' => $m->ultimo_acceso_en,
            ])->values(),
        ];
    }

    private function mensajes(): array
    {
        return [
            'correo.unique' => 'Ya existe un usuario con ese correo.',
            'correo.email' => 'Escribe un correo válido.',
            'contrasena.min' => 'La contraseña debe tener al menos 10 caracteres.',
        ];
    }
}
