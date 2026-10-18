<?php

namespace App\Support;

use App\Models\EventoAuditoria;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

class Bloqueo
{
    /**
     * Suma un fallo de contraseña. Devuelve true si con este fallo la cuenta quedó bloqueada.
     */
    public static function registrarFallo(User $user): bool
    {
        $bloqueada = DB::transaction(function () use ($user) {
            $fila = User::whereKey($user->id)->lockForUpdate()->first();
            if ($fila->estaBloqueado()) {
                return false;
            }

            $fila->intentos_fallidos++;
            $bloquear = $fila->intentos_fallidos >= config('identidad.max_intentos');
            if ($bloquear) {
                $fila->estado = User::BLOQUEADO;
                $fila->bloqueado_en = now();
            }
            $fila->save();
            $user->setRawAttributes($fila->getAttributes(), true);

            return $bloquear;
        });

        Auditoria::registrar(EventoAuditoria::LOGIN_FALLIDO, $user, detalle: ['intentos' => $user->intentos_fallidos]);

        if ($bloqueada) {
            self::revocarTokens($user);
            Auditoria::registrar(EventoAuditoria::CUENTA_BLOQUEADA, $user, detalle: ['intentos' => $user->intentos_fallidos]);
        }

        return $bloqueada;
    }

    public static function desbloquear(User $user, ?User $admin = null): void
    {
        $user->forceFill([
            'estado' => User::ACTIVO,
            'intentos_fallidos' => 0,
            'bloqueado_en' => null,
        ])->save();

        Auditoria::registrar(EventoAuditoria::CUENTA_DESBLOQUEADA, $user, detalle: [
            'desbloqueado_por' => $admin?->email ?? 'consola',
        ]);
    }

    /**
     * Revoca todos los access y refresh tokens del usuario en todos los sistemas.
     */
    public static function revocarTokens(User $user): void
    {
        $tokenIds = Passport::token()->newQuery()->where('user_id', $user->id)->pluck('id');

        Passport::token()->newQuery()->whereIn('id', $tokenIds)->update(['revoked' => true]);
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
    }
}
