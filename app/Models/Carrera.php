<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carrera extends Model
{
    protected $fillable = ['nombre', 'codigo', 'sede_id', 'facultad', 'sede', 'director_id'];

    public function director(): BelongsTo
    {
        return $this->belongsTo(Director::class);
    }

    public function asignaturas(): HasMany
    {
        return $this->hasMany(Asignatura::class);
    }

    public function docentes()
    {
        return $this->hasManyThrough(Docente::class, Asignatura::class, 'carrera_id', 'id', 'id', 'docente_id');
    }
}
