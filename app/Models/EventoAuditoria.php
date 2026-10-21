<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventoAuditoria extends Model
{
    public const LOGIN_EXITOSO = 'login_exitoso';

    public const LOGIN_FALLIDO = 'login_fallido';

    public const INGRESO_CUENTA_BLOQUEADA = 'ingreso_cuenta_bloqueada';

    public const CUENTA_BLOQUEADA = 'cuenta_bloqueada';

    public const CUENTA_DESBLOQUEADA = 'cuenta_desbloqueada';

    public const ACCESO_DENEGADO = 'acceso_denegado';

    public const ACCESO_SISTEMA = 'acceso_sistema';

    public const DISPOSITIVO_NUEVO = 'dispositivo_nuevo';

    public const LIMITE_IP = 'limite_ip';

    public const TOKEN_EMITIDO = 'token_emitido';

    public const TOKEN_RENOVADO = 'token_renovado';

    public const LOGOUT = 'logout';

    public const CODIGO_ENVIADO = 'codigo_enviado';

    public const CODIGO_INCORRECTO = 'codigo_incorrecto';

    // Acciones de administración desde el panel (llevan actor_id).
    public const USUARIO_CREADO = 'usuario_creado';

    public const USUARIO_ACTUALIZADO = 'usuario_actualizado';

    public const CONTRASENA_RESTABLECIDA = 'contrasena_restablecida';

    public const SISTEMA_CREADO = 'sistema_creado';

    public const SISTEMA_ACTUALIZADO = 'sistema_actualizado';

    public const SECRETO_REGENERADO = 'secreto_regenerado';

    public const ROL_CREADO = 'rol_creado';

    public const ROL_ELIMINADO = 'rol_eliminado';

    public const MATRICULA_ASIGNADA = 'matricula_asignada';

    public const MATRICULA_ACTUALIZADA = 'matricula_actualizada';

    public const EXITO = 'exito';

    public const FALLO = 'fallo';

    public const DENEGADO = 'denegado';

    public const INFO = 'info';

    /** Resultado de cada evento, para filtrar rápido en el panel. */
    public const RESULTADOS = [
        self::LOGIN_EXITOSO => self::EXITO,
        self::LOGIN_FALLIDO => self::FALLO,
        self::INGRESO_CUENTA_BLOQUEADA => self::DENEGADO,
        self::CUENTA_BLOQUEADA => self::DENEGADO,
        self::CUENTA_DESBLOQUEADA => self::INFO,
        self::ACCESO_DENEGADO => self::DENEGADO,
        self::ACCESO_SISTEMA => self::EXITO,
        self::DISPOSITIVO_NUEVO => self::INFO,
        self::LIMITE_IP => self::DENEGADO,
        self::TOKEN_EMITIDO => self::EXITO,
        self::TOKEN_RENOVADO => self::EXITO,
        self::LOGOUT => self::INFO,
        self::CODIGO_ENVIADO => self::INFO,
        self::CODIGO_INCORRECTO => self::FALLO,
        self::USUARIO_CREADO => self::INFO,
        self::USUARIO_ACTUALIZADO => self::INFO,
        self::CONTRASENA_RESTABLECIDA => self::INFO,
        self::SISTEMA_CREADO => self::INFO,
        self::SISTEMA_ACTUALIZADO => self::INFO,
        self::SECRETO_REGENERADO => self::INFO,
        self::ROL_CREADO => self::INFO,
        self::ROL_ELIMINADO => self::INFO,
        self::MATRICULA_ASIGNADA => self::INFO,
        self::MATRICULA_ACTUALIZADA => self::INFO,
    ];

    public const UPDATED_AT = null;

    public const CREATED_AT = 'creado_en';

    protected $table = 'eventos_auditoria';

    protected $fillable = [
        'evento', 'resultado', 'usuario_id', 'sistema_id', 'sesion_id', 'dispositivo_id', 'actor_id',
        'correo_intentado', 'ip', 'ip_reenviada', 'navegador', 'sistema_operativo', 'tipo_dispositivo',
        'user_agent', 'metodo_ruta', 'detalle',
    ];

    protected function casts(): array
    {
        return ['detalle' => 'array', 'creado_en' => 'datetime'];
    }
}
