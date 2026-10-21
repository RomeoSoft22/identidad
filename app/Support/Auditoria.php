<?php

namespace App\Support;

use App\Models\EventoAuditoria;
use App\Models\SesionSso;
use App\Models\Sistema;
use App\Models\Usuario;

class Auditoria
{
    /**
     * Registra un evento con todo el contexto de seguridad de la petición actual:
     * IP, IP reenviada, navegador, sistema operativo, tipo de equipo, sesión y dispositivo.
     */
    public static function registrar(
        string $evento,
        ?Usuario $usuario = null,
        ?Sistema $sistema = null,
        array $detalle = [],
        ?string $correo = null,
        ?Usuario $actor = null,
        ?SesionSso $sesion = null,
    ): EventoAuditoria {
        $ctx = ContextoCliente::actual();
        $sesion ??= SesionesSso::actual();
        if ($sesion && $usuario && $sesion->usuario_id !== $usuario->id) {
            $sesion = null;
        }

        return EventoAuditoria::create([
            'evento' => $evento,
            'resultado' => EventoAuditoria::RESULTADOS[$evento] ?? EventoAuditoria::INFO,
            'usuario_id' => $usuario?->id,
            'sistema_id' => $sistema?->id,
            'sesion_id' => $sesion?->id,
            'dispositivo_id' => $sesion?->dispositivo_id,
            'actor_id' => $actor?->id,
            'correo_intentado' => $correo ?? $usuario?->correo,
            'ip' => $ctx->ip,
            'ip_reenviada' => $ctx->ipReenviada,
            'navegador' => trim($ctx->navegador.' '.$ctx->navegadorVersion) ?: null,
            'sistema_operativo' => trim($ctx->sistemaOperativo.' '.$ctx->soVersion) ?: null,
            'tipo_dispositivo' => $ctx->tipo,
            'user_agent' => $ctx->userAgent,
            'metodo_ruta' => $ctx->metodoRuta,
            'detalle' => $detalle ?: null,
        ]);
    }
}
