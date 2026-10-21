<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodigoVerificacion extends Model
{
    public const DESBLOQUEO = 'desbloqueo';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    protected $table = 'codigos_verificacion';

    protected $fillable = ['usuario_id', 'proposito', 'codigo_hash', 'ip', 'expira_en'];

    protected $hidden = ['codigo_hash'];

    protected function casts(): array
    {
        return ['expira_en' => 'datetime', 'usado_en' => 'datetime', 'creado_en' => 'datetime', 'intentos' => 'integer'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function vigente(): bool
    {
        return $this->usado_en === null
            && $this->expira_en->isFuture()
            && $this->intentos < (int) config('identidad.codigo_intentos');
    }
}
