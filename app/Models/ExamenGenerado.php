<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamenGenerado extends Model
{
    use HasFactory;

    protected $table = 'examenes_generados';

    protected $fillable = [
        'evaluacion_id',
        'tipo', // A, B
        'patron_respuestas_json' // Cache or Metadata
    ];

    protected $casts = [
        'patron_respuestas_json' => 'array'
    ];

    public function evaluacion()
    {
        return $this->belongsTo(Evaluacion::class);
    }

    public function preguntas()
    {
        return $this->belongsToMany(BancoPregunta::class, 'examen_preguntas', 'examen_generado_id', 'banco_pregunta_id')
                    ->withPivot('orden')
                    ->orderBy('pivot_orden');
    }
}
