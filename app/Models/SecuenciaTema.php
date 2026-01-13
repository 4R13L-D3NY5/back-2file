<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecuenciaTema extends Model
{
    use HasFactory;

    protected $table = 'secuencias_temas';

    protected $fillable = [
        'momento', // Inicio, Desarrollo, Cierre
        'descripcion',
        'duracion_minutos',
        'tema_id'
    ];

    public function tema()
    {
        return $this->belongsTo(Tema::class);
    }
}
