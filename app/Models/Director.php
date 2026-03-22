<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Director extends Model
{
    protected $fillable = ['nombres', 'apellidos', 'titulo', 'user_id', 'sede_id', 'carrera_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class);
    }

    public function carreras(): BelongsToMany
    {
        return $this->belongsToMany(Carrera::class, 'director_carrera')
            ->withTimestamps()
            ->withPivot('es_principal');
    }

    /**
     * Relación muchos-a-muchos con carreras a través de la tabla pivot director_carrera.
     * Esta es la relación principal para la nueva estructura.
     * Alias de carreras() por compatibilidad.
     */
    public function carrerasRelacion(): BelongsToMany
    {
        return $this->carreras();
    }
}

