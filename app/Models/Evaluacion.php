<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Evaluacion extends Model
{
    use HasFactory;

    protected $table = 'evaluaciones';

    protected $fillable = [
        'nombre',
        'parcial',
        'fecha_examen',
        'hora_inicio',
        'duracion_minutos',
        'mezclar_preguntas',
        'mezclar_opciones',
        'estado',
        'asignatura_id'
    ];

    public function asignatura()
    {
        return $this->belongsTo(Asignatura::class);
    }

    public function examenesGenerados()
    {
        return $this->hasMany(ExamenGenerado::class);
    }
}
