<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Sede extends Model
{
    use HasFactory;
    protected $table = 'sedes';

    protected $fillable = [
        'nombre',
        'codigo',
        'id_api',
        'ciudad',
        // 'direccion',
        // 'telefono',
        'activo'
    ];

    protected $casts = [
        'activo' => 'boolean'
    ];

    public function carreras()
    {
        return $this->belongsToMany(Carrera::class, 'carrera_sede');
    }
}
