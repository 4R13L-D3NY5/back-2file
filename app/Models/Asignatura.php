<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asignatura extends Model
{
    protected $fillable = [
        'codigo',
        'nombre',
        'semestre',
        'creditos',
        'area_desempenio',
        'tipo_curso',
        'modalidad',
        'carga_horaria_total',
        'horas_teoricas',
        'horas_practicas',
        'sesiones_semanales_teoricas',
        'sesiones_semanales_practicas',
        'requisitos',
        'justificacion',
        'descripcion',
        'proposito_general',
        'metodologia_general',
        'sistema_evaluacion',
        'contenido_minimo',
        'carrera_id',
        'docente_id',
        'elementos_competencia',
        'competencia_asignatura',
        'competencia_global_especifica', // CGE del Word
        'reglamento_normativa',          // Reglamento y Normativa
        'organizacion_calendario'        // Organización y Calendario
    ];

    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class);
    }

    public function docentes()
    {
        return $this->belongsToMany(Docente::class, 'asignatura_docente')
            ->withPivot(['grupo', 'aula', 'horario', 'cupo', 'estudiantes_inscritos'])
            ->withTimestamps();
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
