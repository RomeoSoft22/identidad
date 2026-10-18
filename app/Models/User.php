<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ACTIVO = 'activo';
    public const BLOQUEADO = 'bloqueado';
    public const BAJA = 'baja';

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'bloqueado_en' => 'datetime',
            'ultimo_ingreso' => 'datetime',
            'intentos_fallidos' => 'integer',
            'password' => 'hashed',
        ];
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    public function estaBloqueado(): bool
    {
        return $this->estado === self::BLOQUEADO;
    }

    /**
     * Códigos de rol con matrícula vigente en el sistema dado.
     *
     * @return list<string>
     */
    public function rolesEn(Sistema $sistema): array
    {
        return $this->matriculas()
            ->vigentes()
            ->where('matriculas.sistema_id', $sistema->id)
            ->join('roles', 'roles.id', '=', 'matriculas.rol_id')
            ->orderBy('roles.codigo')
            ->pluck('roles.codigo')
            ->unique()
            ->values()
            ->all();
    }
}
