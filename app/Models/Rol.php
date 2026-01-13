<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rol extends Model
{
    protected $table = 'roles';
    protected $fillable = [
        'nombre',
        'codigo',
        'descripcion',
        'color',
        'icono',
        'permisos',
        'activo',
        'orden'
    ];

    protected $casts = [
        'permisos' => 'array',
        'activo' => 'boolean',
        'orden' => 'integer'
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
