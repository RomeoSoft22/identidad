<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispositivo extends Model
{
    public const CREATED_AT = 'primer_uso_en';

    public const UPDATED_AT = null;

    protected $table = 'dispositivos';

    protected $fillable = [
        'usuario_id', 'huella', 'navegador', 'navegador_version', 'sistema_operativo', 'so_version',
        'tipo', 'user_agent', 'confiable', 'ultimo_uso_en',
    ];

    protected function casts(): array
    {
        return ['confiable' => 'boolean', 'primer_uso_en' => 'datetime', 'ultimo_uso_en' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
