<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventoAuditoria;
use App\Models\Matricula;
use App\Models\Rol;
use App\Models\Sistema;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Passport\ClientRepository;

/**
 * Sistemas cliente (ISTQB, FIRMA, PANEL...) y sus roles. Cada sistema es un cliente OAuth:
 * el secreto solo se muestra al crearlo o regenerarlo.
 */
class SistemaController extends Controller
{
    public function index(): JsonResponse
    {
        $sistemas = Sistema::with(['cliente', 'roles' => fn ($q) => $q->withCount('matriculas')->orderBy('codigo')])
            ->withCount(['matriculas as usuarios' => fn ($q) => $q->where('estado', Matricula::ACTIVA)])
            ->orderBy('codigo')
            ->get();

        return response()->json(['data' => $sistemas->map(fn (Sistema $s) => $this->datos($s))]);
    }

    public function store(Request $request, ClientRepository $clientes): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_]{1,39}$/', Rule::unique('sistemas', 'codigo')],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'url_base' => ['nullable', 'url:http,https', 'max:255'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => ['required', 'url:http,https', 'max:255', 'distinct'],
            'confidencial' => ['boolean'],
        ], $this->mensajes());
        $datos['codigo'] = Str::upper($datos['codigo']);
        $confidencial = $request->boolean('confidencial', true);

        [$sistema, $cliente] = DB::transaction(function () use ($datos, $clientes, $confidencial) {
            $cliente = $clientes->createAuthorizationCodeGrantClient($datos['nombre'], $datos['redirect_uris'], confidential: $confidencial);
            $sistema = Sistema::create([
                'codigo' => $datos['codigo'],
                'nombre' => $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
                'url_base' => $datos['url_base'] ?? null,
                'oauth_client_id' => $cliente->id,
            ]);

            return [$sistema, $cliente];
        });

        Auditoria::registrar(EventoAuditoria::SISTEMA_CREADO, sistema: $sistema, actor: $request->attributes->get('admin'), detalle: [
            'codigo' => $sistema->codigo, 'confidencial' => $confidencial,
        ]);

        return response()->json([
            'data' => $this->datos($sistema->load('cliente', 'roles')),
            'client_secret' => $cliente->plainSecret, // solo esta vez
        ], 201);
    }

    public function update(Request $request, Sistema $sistema): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:255'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'url_base' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'redirect_uris' => ['sometimes', 'required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => ['required', 'url:http,https', 'max:255', 'distinct'],
            'activo' => ['sometimes', 'boolean'],
        ], $this->mensajes());

        if (array_key_exists('activo', $datos) && ! $datos['activo'] && $sistema->codigo === config('identidad.sistema_panel')) {
            return response()->json(['message' => 'El panel no se puede desactivar: perderías el acceso a la administración.'], 422);
        }

        DB::transaction(function () use ($sistema, $datos) {
            $sistema->fill(collect($datos)->only(['nombre', 'descripcion', 'url_base', 'activo'])->all())->save();
            $cambiosCliente = array_filter([
                'name' => $datos['nombre'] ?? null,
                'redirect_uris' => $datos['redirect_uris'] ?? null,
            ], fn ($v) => $v !== null);
            if ($cambiosCliente) {
                $sistema->cliente->forceFill($cambiosCliente)->save();
            }
        });

        Auditoria::registrar(EventoAuditoria::SISTEMA_ACTUALIZADO, sistema: $sistema, actor: $request->attributes->get('admin'), detalle: [
            'campos' => array_keys($datos),
        ]);

        return response()->json(['data' => $this->datos($sistema->fresh(['cliente', 'roles']))]);
    }

    public function regenerarSecreto(Request $request, Sistema $sistema, ClientRepository $clientes): JsonResponse
    {
        $cliente = $sistema->cliente;
        if (! $cliente->confidential()) {
            return response()->json(['message' => 'Este sistema es público (PKCE sin secreto): no tiene secreto que regenerar.'], 422);
        }

        $clientes->regenerateSecret($cliente);
        Auditoria::registrar(EventoAuditoria::SECRETO_REGENERADO, sistema: $sistema, actor: $request->attributes->get('admin'));

        return response()->json(['client_secret' => $cliente->plainSecret]);
    }

    public function crearRol(Request $request, Sistema $sistema): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_]{1,39}$/',
                Rule::unique('roles', 'codigo')->where('sistema_id', $sistema->id)],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ], $this->mensajes());

        $rol = $sistema->roles()->create(['codigo' => Str::upper($datos['codigo'])] + $datos);
        Auditoria::registrar(EventoAuditoria::ROL_CREADO, sistema: $sistema, actor: $request->attributes->get('admin'), detalle: ['rol' => $rol->codigo]);

        return response()->json(['data' => $rol], 201);
    }

    public function eliminarRol(Request $request, Rol $rol): JsonResponse
    {
        if ($rol->matriculas()->exists()) {
            return response()->json(['message' => 'El rol tiene usuarios matriculados. Retíralos antes de eliminarlo.'], 422);
        }

        $rol->delete();
        Auditoria::registrar(EventoAuditoria::ROL_ELIMINADO, sistema: $rol->sistema, actor: $request->attributes->get('admin'), detalle: ['rol' => $rol->codigo]);

        return response()->json(null, 204);
    }

    private function datos(Sistema $s): array
    {
        return [
            'id' => $s->id,
            'codigo' => $s->codigo,
            'nombre' => $s->nombre,
            'descripcion' => $s->descripcion,
            'url_base' => $s->url_base,
            'activo' => $s->activo,
            'client_id' => $s->oauth_client_id,
            'confidencial' => $s->cliente?->confidential() ?? true,
            'redirect_uris' => $s->cliente?->redirect_uris ?? [],
            'usuarios' => $s->usuarios ?? null,
            'roles' => $s->roles->map(fn (Rol $r) => [
                'id' => $r->id, 'codigo' => $r->codigo, 'nombre' => $r->nombre, 'descripcion' => $r->descripcion,
                'matriculas' => $r->matriculas_count ?? null,
            ])->values(),
            'creado_en' => $s->creado_en,
        ];
    }

    private function mensajes(): array
    {
        return [
            'codigo.regex' => 'El código empieza con una letra y solo lleva letras, números o guion bajo (ej. FIRMA).',
            'codigo.unique' => 'Ya existe uno con ese código.',
            'redirect_uris.required' => 'Indica al menos una URL de retorno (callback).',
            'redirect_uris.*.url' => 'Cada URL de retorno debe ser una dirección http(s) completa.',
            'url_base.url' => 'La URL base debe ser una dirección http(s) completa.',
        ];
    }
}
