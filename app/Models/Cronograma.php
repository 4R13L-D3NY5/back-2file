<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cronograma extends Model
{
    protected $fillable = [
        'fecha',
        'numero_sesion',
        'observaciones',
        'asignatura_id',
        'grupo_id',
        'tema_id',
        'cumplido',
        // New fields for Planning Semestral
        'periodo_examen',
        'semana_academica',
        'contenido_conceptual',
        'contenido_procedimental',
        'contenido_actitudinal',
        'criterios_desempeno',
        'instrumentos_evaluacion'
    ];

    public function asignatura(): BelongsTo
    {
        return $this->belongsTo(Asignatura::class);
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function scopeForGroup($query, $grupoId)
    {
        return $query->where('grupo_id', $grupoId);
    }

    public function tema(): BelongsTo
    {
        return $this->belongsTo(Tema::class);
    }

    public function secuenciasDidacticas(): HasMany
    {
        return $this->hasMany(SecuenciaDidactica::class);
    }

    public function estrategiasDidacticas(): HasMany
    {
        return $this->hasMany(EstrategiaDidactica::class);
    }

    public function evaluaciones(): HasMany
    {
        return $this->hasMany(Evaluacion::class);
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class);
    }
}
