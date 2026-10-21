<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventoAuditoria;
use App\Models\Matricula;
use App\Models\Rol;
use App\Models\Usuario;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Matrícula = usuario + sistema + rol. Sin una matrícula activa el usuario no entra a ese sistema,
 * aunque su contraseña sea correcta. Los cambios valen desde el siguiente token (máx. 15 minutos).
 */
class MatriculaController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'usuario_id' => ['required', 'integer', Rule::exists('usuarios', 'id')->whereNull('eliminado_en')],
            'rol_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'vigente_hasta' => ['nullable', 'date', 'after:now'],
        ], ['vigente_hasta.after' => 'La fecha de vencimiento debe ser futura.']);

        $rol = Rol::with('sistema')->findOrFail($datos['rol_id']);
        $matricula = Matricula::updateOrCreate(
            ['usuario_id' => $datos['usuario_id'], 'sistema_id' => $rol->sistema_id, 'rol_id' => $rol->id],
            [
                'estado' => Matricula::ACTIVA,
                'vigente_desde' => now(),
                'vigente_hasta' => $datos['vigente_hasta'] ?? null,
                'creado_por' => $request->attributes->get('admin')->id,
            ],
        );

        Auditoria::registrar(EventoAuditoria::MATRICULA_ASIGNADA, Usuario::find($datos['usuario_id']), $rol->sistema,
            actor: $request->attributes->get('admin'), detalle: ['rol' => $rol->codigo]);

        return response()->json(['data' => $matricula->load('sistema:id,codigo,nombre', 'rol:id,codigo,nombre')], 201);
    }

    public function update(Request $request, Matricula $matricula): JsonResponse
    {
        $datos = $request->validate([
            'estado' => ['sometimes', Rule::in([Matricula::ACTIVA, Matricula::SUSPENDIDA, Matricula::RETIRADA])],
            'vigente_hasta' => ['sometimes', 'nullable', 'date', 'after:now'],
        ], ['vigente_hasta.after' => 'La fecha de vencimiento debe ser futura.']);
        $admin = $request->attributes->get('admin');
        $matricula->load('sistema', 'rol', 'usuario');

        $quitaSuPropioPanel = $matricula->usuario_id === $admin->id
            && $matricula->sistema->codigo === config('identidad.sistema_panel')
            && $matricula->rol->codigo === 'ADMIN'
            && ($datos['estado'] ?? Matricula::ACTIVA) !== Matricula::ACTIVA;
        if ($quitaSuPropioPanel) {
            return response()->json(['message' => 'No puedes quitarte tu propio acceso de administrador al panel.'], 422);
        }

        $matricula->fill($datos)->save();
        Auditoria::registrar(EventoAuditoria::MATRICULA_ACTUALIZADA, $matricula->usuario, $matricula->sistema,
            actor: $admin, detalle: ['rol' => $matricula->rol->codigo] + $datos);

        return response()->json(['data' => $matricula->load('sistema:id,codigo,nombre', 'rol:id,codigo,nombre')]);
    }
}
