<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Matricula extends Model
{
    public const ACTIVA = 'activa';

    public const SUSPENDIDA = 'suspendida';

    public const RETIRADA = 'retirada';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $table = 'matriculas';

    protected $fillable = ['usuario_id', 'sistema_id', 'rol_id', 'estado', 'vigente_desde', 'vigente_hasta', 'creado_por'];

    protected function casts(): array
    {
        return ['vigente_desde' => 'datetime', 'vigente_hasta' => 'datetime', 'ultimo_acceso_en' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    public function scopeVigentes(Builder $query): void
    {
        $query->where('matriculas.estado', self::ACTIVA)
            ->where(fn (Builder $q) => $q->whereNull('matriculas.vigente_desde')->orWhere('matriculas.vigente_desde', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('matriculas.vigente_hasta')->orWhere('matriculas.vigente_hasta', '>', now()));
    }
}
