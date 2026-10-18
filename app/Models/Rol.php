<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rol extends Model
{
    protected $table = 'roles';

    protected $fillable = ['sistema_id', 'codigo', 'nombre'];

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }
}
