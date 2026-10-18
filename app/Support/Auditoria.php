<?php

namespace App\Support;

use App\Models\EventoAuditoria;
use App\Models\Sistema;
use App\Models\User;

class Auditoria
{
    public static function registrar(
        string $evento,
        ?User $user = null,
        ?Sistema $sistema = null,
        array $detalle = [],
        ?string $email = null,
    ): EventoAuditoria {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return EventoAuditoria::create([
            'evento' => $evento,
            'user_id' => $user?->id,
            'sistema_id' => $sistema?->id,
            'email' => $email ?? $user?->email,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 1000) : null,
            'detalle' => $detalle ?: null,
        ]);
    }
}
