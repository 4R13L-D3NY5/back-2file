<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BancoPregunta extends Model
{
    use HasFactory;

    protected $table = 'banco_preguntas';

    protected $fillable = [
        'enunciado',
        'tipo', // SELECCION_UNICA, SELECCION_MULTIPLE, FALSO_VERDADERO
        'grupo',
        'grupoTeorico',
        'opciones', // JSON Array
        'respuesta_correcta', // JSON Array or Scalar
        'dificultad',
        'parcial',
        'peso',
        'sede_id',
        'logro_esperado_id',
        'asignatura_id',
        'docente_id',
        'created_by'
    ];

    protected $casts = [
        'opciones' => 'array',
        'respuesta_correcta' => 'array'
    ];

    public function sede()
    {
        return $this->belongsTo(Sede::class);
    }

    public function asignatura()
    {
        return $this->belongsTo(Asignatura::class);
    }

    public function docente()
    {
        return $this->belongsTo(Docente::class);
    }

    public function logro()
    {
        return $this->belongsTo(LogroEsperado::class, 'logro_esperado_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
