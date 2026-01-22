<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Carrera extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nombre',
        'codigo',
        'sigla',
        'sede_id',
        'facultad',
        'director_id',
        'area',
        'mision',
        'vision',
        'perfil_profesional',
        'imagen',
        'activo'
    ];

    protected $casts = [
        'activo' => 'boolean'
    ];

    public function director(): BelongsTo
    {
        return $this->belongsTo(Director::class);
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sede::class);
    }

    public function sedes()
    {
        return $this->belongsToMany(\App\Models\Sede::class, 'carrera_sede');
    }

    /**
     * Asignaturas de esta carrera (Many-to-Many via pivot)
     * Pivot contains: semestre, sede_id
     */
    public function asignaturas(): BelongsToMany
    {
        return $this->belongsToMany(Asignatura::class, 'asignatura_carrera')
            ->withPivot('semestre', 'sede_id')
            ->withTimestamps();
    }

    /**
     * Docentes que enseñan en esta carrera (via asignaturas → grupos)
     */
    public function docentes()
    {
        return Docente::whereHas('grupos.asignatura.carreras', function ($query) {
            $query->where('carreras.id', $this->id);
        });
    }
}
