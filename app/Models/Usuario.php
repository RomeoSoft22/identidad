<?php

namespace App\Models;

use Database\Factories\UsuarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['nombres', 'apellidos', 'correo', 'contrasena', 'creado_por'])]
#[Hidden(['contrasena', 'token_recordar'])]
class Usuario extends Authenticatable
{
    /** @use HasFactory<UsuarioFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public const ACTIVO = 'activo';
    public const BLOQUEADO = 'bloqueado';
    public const BAJA = 'baja';

    public const CREATED_AT = 'creado_en';
    public const UPDATED_AT = 'actualizado_en';
    public const DELETED_AT = 'eliminado_en';

    protected $table = 'usuarios';

    // Columnas en español que Laravel usa para autenticar.
    protected $authPasswordName = 'contrasena';
    protected $rememberTokenName = 'token_recordar';

    protected static function booted(): void
    {
        static::creating(function (Usuario $usuario) {
            $usuario->uuid ??= (string) Str::uuid();
            $usuario->contrasena_cambiada_en ??= now();
        });
    }

    protected static function newFactory(): UsuarioFactory
    {
        return UsuarioFactory::new();
    }

    protected function casts(): array
    {
        return [
            'correo_verificado_en' => 'datetime',
            'bloqueado_en' => 'datetime',
            'contrasena_cambiada_en' => 'datetime',
            'ultimo_ingreso_en' => 'datetime',
            'intentos_fallidos' => 'integer',
            'debe_cambiar_contrasena' => 'boolean',
            'contrasena' => 'hashed',
        ];
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    public function dispositivos(): HasMany
    {
        return $this->hasMany(Dispositivo::class);
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(SesionSso::class);
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombres.' '.$this->apellidos);
    }

    public function routeNotificationForMail(): string
    {
        return $this->correo;
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
