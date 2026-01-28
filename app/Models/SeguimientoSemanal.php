<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeguimientoSemanal extends Model
{
    protected $table = 'seguimiento_semanal';

    protected $fillable = [
        'sede_id',
        'carrera_id',
        'docente_id',
        'asignatura_id',
        'semana_inicio',
        'semana_fin',
        'criterios',
        'alerta',
        'observaciones_generales',
        'created_by'
    ];

    protected $casts = [
        'criterios' => 'array',
        'semana_inicio' => 'date',
        'semana_fin' => 'date'
    ];

    public function docente()
    {
        return $this->belongsTo(User::class, 'docente_id');
    }

    public function asignatura()
    {
        return $this->belongsTo(Asignatura::class, 'asignatura_id');
    }

    public function carrera()
    {
        return $this->belongsTo(Carrera::class, 'carrera_id');
    }

    public function creador()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
