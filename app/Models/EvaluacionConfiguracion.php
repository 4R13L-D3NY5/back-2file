<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvaluacionConfiguracion extends Model
{
    use HasFactory;

    protected $table = 'evaluacion_configuraciones';

    protected $fillable = [
        'nivel',
        'sede_id',
        'carrera_id',
        'configuracion'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'configuracion' => 'array',
    ];
}
