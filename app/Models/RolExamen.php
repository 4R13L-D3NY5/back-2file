<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RolExamen extends Model
{
    use HasFactory;

    protected $table = 'rol_examenes';

    protected $fillable = [
        'gestion',
        'carrera_id',
        'sede_id',
        'materia_codigo',
        'materia_nombre',
        'tipo_examen',
        'grupo',
        'semana',
        'fecha',
        'estado',
        'config_generacion',
        'timestamps_proceso',
        'variantes',
        'patrones',
        'hora_inicio',
        'hora_fin',
        'aula',
        'observaciones',
        'conflictos',
        'created_by',
    ];

    protected $casts = [
        'fecha' => 'date',
        'semana' => 'integer',
        'sede_id' => 'integer',
        'config_generacion' => 'array',
        'timestamps_proceso' => 'array',
        'variantes' => 'array',
        'patrones' => 'array',
        'conflictos' => 'array',
    ];

    // ==========================================
    // RELACIONES
    // ==========================================

    public function carrera()
    {
        return $this->belongsTo(Carrera::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function asignatura()
    {
        return $this->belongsTo(Asignatura::class, 'materia_codigo', 'codigo');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeGestion($query, $gestion)
    {
        return $query->where('gestion', $gestion);
    }

    public function scopeCarrera($query, $carreraId)
    {
        return $query->where('carrera_id', $carreraId);
    }

    public function scopeMateria($query, $codigoMateria)
    {
        return $query->where('materia_codigo', $codigoMateria);
    }

    public function scopeSemana($query, $semana)
    {
        return $query->where('semana', $semana);
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    public function getHorarioFormateadoAttribute()
    {
        return $this->hora_inicio . ' - ' . $this->hora_fin;
    }

    public function getFechaFormateadaAttribute()
    {
        return $this->fecha ? $this->fecha->format('d/m/Y') : '';
    }
}
