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
    public const LIMITE_IP = 'limite_ip';
    public const TOKEN_EMITIDO = 'token_emitido';
    public const LOGOUT = 'logout';

    public const UPDATED_AT = null;
    public const CREATED_AT = 'creado_en';

    protected $table = 'eventos_auditoria';

    protected $fillable = ['user_id', 'sistema_id', 'evento', 'email', 'ip', 'user_agent', 'detalle'];

    protected function casts(): array
    {
        return ['detalle' => 'array', 'creado_en' => 'datetime'];
    }
}
