<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanificacionPersonal extends Model
{
    use HasFactory;

    protected $table = 'planificaciones_personales';

    protected $fillable = [
        'tema_id',
        'user_id',
        'estrategias_metodologicas',
        'estrategias_aprendizaje',
        'estrategias_recursos',
        'evaluacion_formativa',
        'evaluacion_sumativa',
        'secuencia_didactica',
    ];

    protected $casts = [
        'estrategias_recursos' => 'array',
        'evaluacion_formativa' => 'array',
        'evaluacion_sumativa' => 'array',
        'secuencia_didactica' => 'array',
    ];

    public function tema()
    {
        return $this->belongsTo(Tema::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
