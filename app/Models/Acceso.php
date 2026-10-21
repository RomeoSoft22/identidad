<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Acceso extends Model
{
    public const LOGIN = 'login';

    public const SSO = 'sso';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    protected $table = 'accesos';

    protected $fillable = ['sesion_id', 'usuario_id', 'sistema_id', 'tipo', 'ip'];

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionSso::class, 'sesion_id');
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }
}
