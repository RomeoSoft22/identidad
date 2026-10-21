<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\EventoAuditoria;
use App\Models\SesionSso;
use App\Models\Sistema;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResumenController extends Controller
{
    /** Cifras de la portada del panel y el usuario que está administrando. */
    public function resumen(Request $request): JsonResponse
    {
        $admin = $request->attributes->get('admin');

        return response()->json([
            'admin' => ['nombre' => $admin->nombreCompleto(), 'correo' => $admin->correo],
            'usuarios' => Usuario::count(),
            'bloqueados' => Usuario::where('estado', Usuario::BLOQUEADO)->count(),
            'sistemas' => Sistema::where('activo', true)->count(),
            'sesiones_abiertas' => SesionSso::abiertas()->where('expira_en', '>', now())->count(),
            'ingresos_hoy' => Acceso::where('creado_en', '>=', today())->count(),
            'fallidos_hoy' => EventoAuditoria::where('evento', EventoAuditoria::LOGIN_FALLIDO)->where('creado_en', '>=', today())->count(),
        ]);
    }

    /** Buscador de auditoría: por usuario, sistema, evento, resultado o IP. */
    public function auditoria(Request $request): JsonResponse
    {
        $eventos = EventoAuditoria::query()
            ->leftJoin('usuarios as u', 'u.id', '=', 'eventos_auditoria.usuario_id')
            ->leftJoin('usuarios as a', 'a.id', '=', 'eventos_auditoria.actor_id')
            ->leftJoin('sistemas as s', 's.id', '=', 'eventos_auditoria.sistema_id')
            ->when($request->query('usuario_id'), fn ($q, $v) => $q->where('eventos_auditoria.usuario_id', $v))
            ->when($request->query('sistema_id'), fn ($q, $v) => $q->where('eventos_auditoria.sistema_id', $v))
            ->when($request->query('evento'), fn ($q, $v) => $q->where('eventos_auditoria.evento', $v))
            ->when($request->query('resultado'), fn ($q, $v) => $q->where('eventos_auditoria.resultado', $v))
            ->when($request->query('ip'), fn ($q, $v) => $q->where('eventos_auditoria.ip', $v))
            ->when($request->query('q'), fn ($q, $v) => $q->where('eventos_auditoria.correo_intentado', 'like', "%{$v}%"))
            ->orderByDesc('eventos_auditoria.id')
            ->select('eventos_auditoria.*', 's.codigo as sistema_codigo', 'a.correo as actor_correo')
            ->paginate(50);

        return response()->json([
            'data' => collect($eventos->items())->map(fn (EventoAuditoria $e) => $e->only([
                'id', 'evento', 'resultado', 'correo_intentado', 'sistema_codigo', 'actor_correo', 'ip', 'ip_reenviada',
                'navegador', 'sistema_operativo', 'tipo_dispositivo', 'metodo_ruta', 'detalle', 'creado_en',
            ])),
            'meta' => ['total' => $eventos->total(), 'pagina' => $eventos->currentPage(), 'paginas' => $eventos->lastPage()],
            'eventos' => array_keys(EventoAuditoria::RESULTADOS),
        ]);
    }
}
