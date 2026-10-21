<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SesionSso extends Model
{
    public const CIERRE_LOGOUT = 'logout';

    public const CIERRE_EXPIRADA = 'expirada';

    public const CIERRE_REVOCADA = 'revocada';

    public const CIERRE_BLOQUEO = 'bloqueo';

    public $timestamps = false;

    protected $table = 'sesiones_sso';

    protected $fillable = [
        'sid', 'usuario_id', 'dispositivo_id', 'ip', 'zona_horaria', 'idioma',
        'iniciada_en', 'ultima_actividad_en', 'expira_en', 'cerrada_en', 'motivo_cierre',
    ];

    protected function casts(): array
    {
        return [
            'iniciada_en' => 'datetime',
            'ultima_actividad_en' => 'datetime',
            'expira_en' => 'datetime',
            'cerrada_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    public function accesos(): HasMany
    {
        return $this->hasMany(Acceso::class, 'sesion_id');
    }

    public function scopeAbiertas(Builder $query): void
    {
        $query->whereNull('cerrada_en');
    }

    public function estaVigente(): bool
    {
        return $this->cerrada_en === null && ($this->expira_en === null || $this->expira_en->isFuture());
    }
}
