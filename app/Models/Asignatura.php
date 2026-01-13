<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asignatura extends Model
{
    protected $fillable = [
        'codigo', 'nombre', 'semestre', 'creditos',
        'area_desempenio', 'tipo_curso', 'modalidad',
        'carga_horaria_total', 'horas_teoricas', 'horas_practicas',
        'sesiones_semanales_teoricas', 'sesiones_semanales_practicas',
        'requisitos', 'justificacion', 'proposito_general', 
        'metodologia_general', 'sistema_evaluacion',
        'carrera_id', 'docente_id'
    ];

    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class);
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(Docente::class);
    }

    // Estructura
    public function unidades(): HasMany
    {
        return $this->hasMany(Unidad::class);
    }

    public function bibliografias(): HasMany
    {
        return $this->hasMany(Bibliografia::class);
    }

    // Ejecución
    public function horarios(): HasMany
    {
        return $this->hasMany(Horario::class);
    }

    public function cronogramas(): HasMany
    {
        return $this->hasMany(Cronograma::class);
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }
}
