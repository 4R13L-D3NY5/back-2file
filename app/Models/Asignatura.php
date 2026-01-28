<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asignatura extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'codigo',
        'nombre',
        'estado',
        // semestre REMOVED - now in asignatura_carrera pivot
        // carrera_id REMOVED - now many-to-many via asignatura_carrera
        'creditos',
        'area_desempenio',
        'tipo_curso',
        'modalidad',
        'carga_horaria_total',
        'horas_detalle',
        'sesiones_semanales',
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
        // docente_id REMOVED - Docentes are linked via grupos table
        'elementos_competencia',
        'competencia_asignatura',
        'competencia_global_especifica',
        'reglamento_normativa',
        'organizacion_calendario',
        'comun_token',
        'docente_formacion',
        'docente_telefono',
        'docente_email'
    ];

    protected $casts = [
        'reglamento_normativa' => 'array',
        'elementos_competencia' => 'array',
        'metodologia_general' => 'array'
    ];

    /**
     * Carreras que ofrecen esta asignatura (Many-to-Many via pivot)
     * Pivot contains: semestre, sede_id
     */
    public function carreras(): BelongsToMany
    {
        return $this->belongsToMany(Carrera::class, 'asignatura_carrera')
            ->withPivot('semestre', 'sede_id')
            ->withTimestamps();
    }

    /**
     * Docentes asignados a esta materia (a través de grupos)
     */
    public function docentes()
    {
        return $this->hasManyThrough(
            Docente::class,
            Grupo::class,
            'asignatura_id', // FK en grupos
            'id',            // FK en docentes
            'id',            // PK en asignaturas
            'docente_id'     // Local key en grupos
        )->distinct();
    }

    /**
     * Grupos de esta asignatura (nueva estructura normalizada)
     */
    public function grupos(): HasMany
    {
        return $this->hasMany(Grupo::class);
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

    /**
     * Horarios (a través de grupos)
     */
    public function horarios()
    {
        return $this->hasManyThrough(
            Horario::class,
            Grupo::class,
            'asignatura_id',
            'grupo_id'
        );
    }

    public function cronogramas(): HasMany
    {
        return $this->hasMany(Cronograma::class);
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    /**
     * ACCESSORS
     */
    public function getEstadisticasProgresoAttribute()
    {
        $total = 0;
        $completados = 0;

        // Relies on eager loading to avoid N+1
        foreach ($this->unidades as $unidad) {
            foreach ($unidad->temas as $tema) {
                $total++;
                // Criteria for completion: content is not empty
                if (
                    !empty($tema->contenido_conceptual) ||
                    !empty($tema->contenido_procedimental)
                ) {
                    $completados++;
                }
            }
        }

        return [
            'total' => $total,
            'completados' => $completados,
            'pendientes' => $total - $completados,
            'porcentaje' => $total > 0 ? round(($completados / $total) * 100) : 0
        ];
    }

    public function getProgresoAttribute()
    {
        return $this->estadisticas_progreso['porcentaje'];
    }
}
