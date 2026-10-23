<?php

namespace App\Support;

use App\Models\Acceso;
use App\Models\Dispositivo;
use App\Models\EventoAuditoria;
use App\Models\Matricula;
use App\Models\SesionSso;
use App\Models\Sistema;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registro de las sesiones SSO: desde qué dispositivo e IP se abrió cada una, cuándo tuvo
 * actividad, a qué sistemas entró y cómo terminó.
 */
class SesionesSso
{
    private const CLAVE = 'sesion_sso_id';

    /** Abre la sesión tras un ingreso con contraseña correcta. */
    public static function iniciar(Usuario $usuario, Request $request, ?string $zonaHoraria, ?string $idioma): SesionSso
    {
        $ctx = ContextoCliente::desde($request);
        $ahora = now();

        $dispositivo = Dispositivo::firstOrNew(['usuario_id' => $usuario->id, 'huella' => $ctx->huella($usuario->id)]);
        $esNuevo = ! $dispositivo->exists;
        $dispositivo->fill([
            'navegador' => $ctx->navegador,
            'navegador_version' => $ctx->navegadorVersion,
            'sistema_operativo' => $ctx->sistemaOperativo,
            'so_version' => $ctx->soVersion,
            'tipo' => $ctx->tipo,
            'user_agent' => $ctx->userAgent,
            'ultimo_uso_en' => $ahora,
        ])->save();

        $sesion = SesionSso::create([
            'sid' => 's_'.Str::random(40),
            'usuario_id' => $usuario->id,
            'dispositivo_id' => $dispositivo->id,
            'ip' => $ctx->ip,
            'zona_horaria' => $zonaHoraria,
            'idioma' => $idioma,
            'iniciada_en' => $ahora,
            'ultima_actividad_en' => $ahora,
            'expira_en' => $ahora->copy()->addMinutes((int) config('session.lifetime')),
        ]);
        $request->session()->put(self::CLAVE, $sesion->id);

        $usuario->forceFill(['ultimo_ingreso_en' => $ahora, 'ultima_ip' => $ctx->ip])->save();

        if ($esNuevo && $usuario->dispositivos()->count() > 1) {
            Auditoria::registrar(EventoAuditoria::DISPOSITIVO_NUEVO, $usuario, sesion: $sesion, detalle: [
                'navegador' => $dispositivo->navegador, 'sistema_operativo' => $dispositivo->sistema_operativo,
            ]);
        }

        return $sesion;
    }

    /**
     * Completa un ingreso ya verificado (contraseña, código de correo o Google): abre la sesión del
     * navegador y la sesión SSO, y lo registra en la auditoría con el método usado.
     */
    public static function ingresar(Usuario $usuario, Request $request, ?string $zonaHoraria, ?string $idioma, string $metodo = 'contrasena'): SesionSso
    {
        $usuario->forceFill(['intentos_fallidos' => 0])->save();
        Auth::login($usuario);
        $request->session()->regenerate();
        $sesion = self::iniciar($usuario, $request, $zonaHoraria, $idioma);
        Auditoria::registrar(EventoAuditoria::LOGIN_EXITOSO, $usuario, sesion: $sesion, detalle: ['metodo' => $metodo]);

        return $sesion;
    }

    /** Sesión SSO de la petición actual, si hay una abierta en esta cookie. */
    public static function actual(): ?SesionSso
    {
        $request = request();
        if (! $request->hasSession() || ! ($id = $request->session()->get(self::CLAVE))) {
            return null;
        }

        return SesionSso::find($id);
    }

    /**
     * Usuario autenticado solo si su cuenta está activa y su sesión SSO sigue vigente.
     * Si no, cierra la sesión del navegador para que vuelva a pedir contraseña.
     */
    public static function usuarioVigente(Request $request): ?Usuario
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();
        if (! $usuario) {
            return null;
        }

        $sesion = self::actual();
        $vigente = $usuario->estado === Usuario::ACTIVO && $sesion?->usuario_id === $usuario->id && $sesion->estaVigente();

        if (! $vigente) {
            if ($sesion && $sesion->cerrada_en === null) {
                self::cerrar($sesion, $usuario->estado === Usuario::ACTIVO ? SesionSso::CIERRE_EXPIRADA : SesionSso::CIERRE_BLOQUEO);
            }
            self::salirDelNavegador($request);

            return null;
        }

        if ($sesion->ultima_actividad_en === null || $sesion->ultima_actividad_en->lt(now()->subMinute())) {
            $sesion->forceFill(['ultima_actividad_en' => now()])->save();
        }

        return $usuario;
    }

    /** Anota que el usuario entró a un sistema desde esta sesión y actualiza su última visita. */
    public static function registrarAcceso(Usuario $usuario, Sistema $sistema, Request $request): void
    {
        $sesion = self::actual();
        if (! $sesion) {
            return;
        }

        $tipo = $sesion->accesos()->exists() ? Acceso::SSO : Acceso::LOGIN;
        Acceso::create([
            'sesion_id' => $sesion->id,
            'usuario_id' => $usuario->id,
            'sistema_id' => $sistema->id,
            'tipo' => $tipo,
            'ip' => $request->ip(),
        ]);
        self::marcarVisita($usuario, $sistema);
        Auditoria::registrar(EventoAuditoria::ACCESO_SISTEMA, $usuario, $sistema, ['tipo' => $tipo], sesion: $sesion);
    }

    public static function marcarVisita(Usuario $usuario, Sistema $sistema): void
    {
        Matricula::where('usuario_id', $usuario->id)->where('sistema_id', $sistema->id)
            ->update(['ultimo_acceso_en' => now()]);
    }

    public static function cerrar(SesionSso $sesion, string $motivo): void
    {
        $sesion->forceFill(['cerrada_en' => now(), 'motivo_cierre' => $motivo])->save();
    }

    /** Cierra todas las sesiones del usuario en todos los navegadores (bloqueo o revocación). */
    public static function cerrarTodas(Usuario $usuario, string $motivo): void
    {
        SesionSso::where('usuario_id', $usuario->id)->abiertas()->update(['cerrada_en' => now(), 'motivo_cierre' => $motivo]);

        if (config('session.driver') === 'database') {
            DB::table(config('session.table'))->where('user_id', $usuario->id)->delete();
        }
    }

    public static function salirDelNavegador(Request $request): void
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
