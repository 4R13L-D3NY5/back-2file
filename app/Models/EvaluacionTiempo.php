<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvaluacionTiempo extends Model
{
    use HasFactory;

    protected $table = 'evaluacion_tiempos';

    protected $fillable = [
        'gestion',
        'minutos_antes_entrega',
        'horas_antes_generacion',
        'horas_post_patron',
        'alerta_horas_antes',
    ];

    protected $casts = [
        'minutos_antes_entrega' => 'integer',
        'horas_antes_generacion' => 'integer',
        'horas_post_patron' => 'integer',
        'alerta_horas_antes' => 'integer',
    ];
}
