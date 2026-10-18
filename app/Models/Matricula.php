<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Matricula extends Model
{
    public const ACTIVA = 'activa';
    public const SUSPENDIDA = 'suspendida';

    protected $table = 'matriculas';

    protected $fillable = ['user_id', 'sistema_id', 'rol_id', 'estado', 'vigente_hasta', 'creado_por'];

    protected function casts(): array
    {
        return ['vigente_hasta' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            ->where(fn (Builder $q) => $q->whereNull('matriculas.vigente_hasta')->orWhere('matriculas.vigente_hasta', '>', now()));
    }
}
