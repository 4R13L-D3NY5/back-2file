<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanningCache extends Model
{
    protected $table = 'planning_cache';

    protected $fillable = [
        'id_horario_api',
        'id_designacion',
        'docente_nombre',
        'docente_ci',
        'sigla',
        'materia',
        'semestre',
        'grupo',
        'tipo_clase',
        'dia',
        'hora_inicio',
        'hora_fin',
        'aula',
        'bloque',
        'capacidad_aula',
        'carrera_codigo',
        'sede_api_id',
        'sede_nombre',
        'gestion',
        'plan_estudios',
        'sincronizado_at',
    ];

    protected $casts = [
        'id_horario_api' => 'integer',
        'id_designacion' => 'integer',
        'semestre' => 'integer',
        'sede_api_id' => 'integer',
        'capacidad_aula' => 'integer',
        'sincronizado_at' => 'datetime',
    ];

    /**
     * Scope: filtrar por gestion y sede
     */
    public function scopeForGestionSede($query, string $gestion, int $sedeApiId)
    {
        return $query->where('gestion', $gestion)->where('sede_api_id', $sedeApiId);
    }

    /**
     * Scope: solo Plan N
     */
    public function scopePlanN($query)
    {
        return $query->where('plan_estudios', 'N');
    }

    /**
     * Scope: filtrar por carrera
     */
    public function scopeForCarrera($query, string $carreraCodigo)
    {
        return $query->where('carrera_codigo', $carreraCodigo);
    }

    /**
     * Scope: filtrar por sigla de materia
     */
    public function scopeForSigla($query, string $sigla)
    {
        return $query->where('sigla', $sigla);
    }
}
