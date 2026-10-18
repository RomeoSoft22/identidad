<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sistema extends Model
{
    protected $table = 'sistemas';

    protected $fillable = ['codigo', 'nombre', 'url_base', 'oauth_client_id', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'oauth_client_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Rol::class);
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    public static function porCliente(?string $clientId): ?self
    {
        return $clientId ? static::where('oauth_client_id', $clientId)->first() : null;
    }
}
