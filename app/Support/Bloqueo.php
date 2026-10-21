<?php

namespace App\Support;

use App\Models\EventoAuditoria;
use App\Models\SesionSso;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

class Bloqueo
{
    public const MOTIVO_INTENTOS = 'intentos_fallidos';

    /**
     * Suma un fallo de contraseña. Devuelve true si con este fallo la cuenta quedó bloqueada.
     */
    public static function registrarFallo(Usuario $usuario): bool
    {
        $bloqueada = DB::transaction(function () use ($usuario) {
            $fila = Usuario::whereKey($usuario->id)->lockForUpdate()->first();
            if ($fila->estaBloqueado()) {
                return false;
            }

            $fila->intentos_fallidos++;
            $bloquear = $fila->intentos_fallidos >= config('identidad.max_intentos');
            if ($bloquear) {
                $fila->estado = Usuario::BLOQUEADO;
                $fila->bloqueado_en = now();
                $fila->bloqueado_motivo = self::MOTIVO_INTENTOS;
            }
            $fila->save();
            $usuario->setRawAttributes($fila->getAttributes(), true);

            return $bloquear;
        });

        Auditoria::registrar(EventoAuditoria::LOGIN_FALLIDO, $usuario, detalle: ['intentos' => $usuario->intentos_fallidos]);

        if ($bloqueada) {
            self::revocarTokens($usuario);
            SesionesSso::cerrarTodas($usuario, SesionSso::CIERRE_BLOQUEO);
            Auditoria::registrar(EventoAuditoria::CUENTA_BLOQUEADA, $usuario, detalle: ['intentos' => $usuario->intentos_fallidos]);
        }

        return $bloqueada;
    }

    /** $via: 'consola', 'panel' o 'codigo_correo' (el propio usuario con el código de 6 dígitos). */
    public static function desbloquear(Usuario $usuario, ?Usuario $admin = null, ?string $via = null): void
    {
        $usuario->forceFill([
            'estado' => Usuario::ACTIVO,
            'intentos_fallidos' => 0,
            'bloqueado_en' => null,
            'bloqueado_motivo' => null,
        ])->save();

        Auditoria::registrar(EventoAuditoria::CUENTA_DESBLOQUEADA, $usuario, actor: $admin, detalle: [
            'desbloqueado_por' => $admin?->correo ?? $via ?? 'consola',
        ]);
    }

    /**
     * Revoca todos los access y refresh tokens del usuario en todos los sistemas.
     */
    public static function revocarTokens(Usuario $usuario): void
    {
        $tokenIds = Passport::token()->newQuery()->where('user_id', $usuario->id)->pluck('id');

        Passport::token()->newQuery()->whereIn('id', $tokenIds)->update(['revoked' => true]);
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
    }
}
