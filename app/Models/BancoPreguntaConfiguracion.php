<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BancoPreguntaConfiguracion extends Model
{
    protected $table = 'banco_preguntas_configuraciones';

    protected $fillable = [
        'asignatura_id',
        'sede_id',
        'grupo_teorico',
        'parcial',
        'con_cartilla',
        'updated_by',
    ];

    protected $casts = [
        'con_cartilla' => 'boolean',
    ];
}
