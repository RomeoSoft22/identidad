<?php

namespace App\Support;

use App\Mail\CodigoDesbloqueo;
use App\Models\CodigoVerificacion;
use App\Models\EventoAuditoria;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Segundo factor por correo para una cuenta bloqueada: quien ingresa la contraseña correcta
 * recibe un código de 6 dígitos; con él la cuenta se desbloquea y se completa el ingreso.
 * El código pendiente queda atado a la sesión del navegador que lo pidió.
 */
class VerificacionCorreo
{
    private const CLAVE_SESION = 'verificacion_pendiente';

    /** Envía un código nuevo. Devuelve null si se superó el límite de envíos por hora. */
    public static function enviar(Usuario $usuario, Request $request, array $contexto = []): ?CodigoVerificacion
    {
        $enviadosUltimaHora = CodigoVerificacion::where('usuario_id', $usuario->id)
            ->where('creado_en', '>', now()->subHour())->count();
        if ($enviadosUltimaHora >= config('identidad.codigo_por_hora')) {
            return null;
        }

        $codigo = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $minutos = (int) config('identidad.codigo_minutos');

        $registro = DB::transaction(function () use ($usuario, $request, $codigo, $minutos) {
            // Solo vale el último código enviado.
            CodigoVerificacion::where('usuario_id', $usuario->id)->whereNull('usado_en')
                ->where('expira_en', '>', now())->update(['expira_en' => now()]);

            return CodigoVerificacion::create([
                'usuario_id' => $usuario->id,
                'proposito' => CodigoVerificacion::DESBLOQUEO,
                'codigo_hash' => Hash::make($codigo),
                'ip' => $request->ip(),
                'expira_en' => now()->addMinutes($minutos),
            ]);
        });

        Mail::to($usuario->correo)->send(new CodigoDesbloqueo($codigo, $usuario->nombreCompleto(), $minutos, $request->ip()));

        $request->session()->put(self::CLAVE_SESION, ['codigo_id' => $registro->id, 'usuario_id' => $usuario->id] + $contexto);
        Auditoria::registrar(EventoAuditoria::CODIGO_ENVIADO, $usuario, detalle: ['proposito' => CodigoVerificacion::DESBLOQUEO]);

        return $registro;
    }

    /**
     * Comprueba el código del navegador que lo pidió.
     *
     * @return array{0: 'ok'|'sin_pendiente'|'incorrecto'|'vencido', 1: ?Usuario, 2: array}
     */
    public static function verificar(Request $request, string $codigo): array
    {
        $pendiente = $request->session()->get(self::CLAVE_SESION);
        $registro = $pendiente ? CodigoVerificacion::with('usuario')->find($pendiente['codigo_id']) : null;
        if (! $registro || $registro->usuario_id !== ($pendiente['usuario_id'] ?? null) || ! $registro->usuario) {
            return ['sin_pendiente', null, []];
        }

        $usuario = $registro->usuario;
        if (! $registro->vigente()) {
            return ['vencido', $usuario, []];
        }

        if (! Hash::check($codigo, $registro->codigo_hash)) {
            $registro->increment('intentos');
            Auditoria::registrar(EventoAuditoria::CODIGO_INCORRECTO, $usuario, detalle: ['intentos' => $registro->intentos]);

            return [$registro->fresh()->vigente() ? 'incorrecto' : 'vencido', $usuario, []];
        }

        $registro->forceFill(['usado_en' => now()])->save();
        $request->session()->forget(self::CLAVE_SESION);

        return ['ok', $usuario, collect($pendiente)->except(['codigo_id', 'usuario_id'])->all()];
    }

    /** j***z@gmail.com: confirma a quién se envió sin exponer el correo completo. */
    public static function enmascarar(string $correo): string
    {
        [$local, $dominio] = array_pad(explode('@', $correo, 2), 2, '');
        $visible = Str::length($local) <= 2 ? Str::substr($local, 0, 1) : Str::substr($local, 0, 1).'***'.Str::substr($local, -1);

        return $visible.'@'.$dominio;
    }
}
