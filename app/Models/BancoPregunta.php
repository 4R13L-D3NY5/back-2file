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
        'opciones', // JSON Array
        'respuesta_correcta', // JSON Array or Scalar
        'dificultad',
        'peso',
        'logro_esperado_id',
        'created_by'
    ];

    protected $casts = [
        'opciones' => 'array',
        'respuesta_correcta' => 'array'
    ];

    public function logro()
    {
        return $this->belongsTo(LogroEsperado::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
