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
        'comun_tipo',
        'docente_formacion',
        'docente_telefono',
        'docente_email'
    ];

    protected $casts = [
        'reglamento_normativa' => 'array',
        'elementos_competencia' => 'array',
        'metodologia_general' => 'array',
        'sistema_evaluacion' => 'array'
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

    public function temas()
    {
        return $this->hasManyThrough(Tema::class, Unidad::class);
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

    public function auditorias(): HasMany
    {
        return $this->hasMany(Auditoria::class);
    }

    /**
     * ACCESSORS
     */
    public function getEstadisticasProgresoAttribute()
    {
        $total = 0;
        $completados = 0;

        $criteria = [
            'descripcion' => !empty($this->descripcion),
            'justificacion' => !empty($this->justificacion),
            'proposito_general' => !empty($this->proposito_general),
            'metodologia_general' => !empty($this->metodologia_general),
            'sistema_evaluacion' => !empty($this->sistema_evaluacion),
        ];

        foreach ($criteria as $completed_item) {
            $total++;
            if ($completed_item) $completados++;
        }

        // Relies on eager loading to avoid N+1
        foreach ($this->unidades as $unidad) {
            foreach ($unidad->temas as $tema) {
                // Aumentamos en 2 el total por tema: 1 por contenido y 1 por plan de clase
                $total += 2;

                // 1. Criteria for completion: content is not empty
                if (
                    !empty($tema->contenido_conceptual) ||
                    !empty($tema->contenido_procedimental) ||
                    !empty($tema->contenido_items)
                ) {
                    $completados++;
                }

                // 2. Plan de Clase (Planificación Personal) presence
                $plan = $tema->planificacionPersonal;
                if ($plan) {
                    if (!empty($plan->estrategias_metodologicas) || 
                        !empty($plan->secuencia_didactica) || 
                        !empty($plan->evaluacion_formativa) ||
                        !empty($plan->estrategias_aprendizaje)) {
                        $completados++;
                    }
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

    public function getProgresoPorDocente($userId)
    {
        $total = 0;
        $completados = 0;

        $criteria = [
            'descripcion' => !empty($this->descripcion),
            'justificacion' => !empty($this->justificacion),
            'proposito_general' => !empty($this->proposito_general),
            'metodologia_general' => !empty($this->metodologia_general),
            'sistema_evaluacion' => !empty($this->sistema_evaluacion),
        ];

        foreach ($criteria as $completed_item) {
            $total++;
            if ($completed_item) $completados++;
        }

        // Helper para revisar contenido real (ignora tags HTML, soporta arrays)
        $hasContent = function($data) {
            if (empty($data)) return false;
            
            $processString = function($html) {
                if (!is_scalar($html)) return '';
                return trim(strip_tags(str_replace(['&nbsp;', ' '], '', (string)$html)));
            };
            
            if (is_array($data)) {
                $text = '';
                array_walk_recursive($data, function($item) use (&$text, $processString) {
                    $text .= $processString($item);
                });
                return $text !== '';
            }
            
            return $processString($data) !== '';
        };

        if ($this->relationLoaded('unidades')) {
            foreach ($this->unidades as $unidad) {
                if ($unidad->relationLoaded('temas')) {
                    foreach ($unidad->temas as $tema) {
                        $total += 2;

                        if (
                            !empty($tema->contenido_conceptual) ||
                            !empty($tema->contenido_procedimental) ||
                            !empty($tema->contenido_items)
                        ) {
                            $completados++;
                        }

                        $miPlan = $tema->relationLoaded('planificacionesPersonales') 
                            ? $tema->planificacionesPersonales->where('user_id', $userId)->first() 
                            : ($tema->relationLoaded('planificacionPersonal') && $tema->planificacionPersonal && $tema->planificacionPersonal->user_id == $userId ? $tema->planificacionPersonal : null);

                        if ($miPlan) {
                            if ($hasContent($miPlan->estrategias_metodologicas) || 
                                $hasContent($miPlan->secuencia_didactica) || 
                                $hasContent($miPlan->evaluacion_formativa) ||
                                $hasContent($miPlan->estrategias_aprendizaje)) {
                                $completados++;
                            }
                        }
                    }
                }
            }
        }

        return $total > 0 ? round(($completados / $total) * 100) : 0;
    }

    public function getIndicadoresDocumentacionAttribute()
    {
        // 1. Programa de Asignatura (PAC)
        $pac_puntos = 0;
        if (!empty($this->justificacion)) $pac_puntos++;
        if (!empty($this->proposito_general)) $pac_puntos++;
        if (!empty($this->competencia_global_especifica) || !empty($this->competencia_asignatura) || !empty($this->elementos_competencia)) $pac_puntos++;
        if ($this->relationLoaded('bibliografias') && $this->bibliografias->count() > 0) $pac_puntos++;
        $pac_pct = round(($pac_puntos / 4) * 100);

        // 2. Programa Analítico, 3. Plan de Clase, 5. Preguntas
        $total_unidades = $this->relationLoaded('unidades') ? $this->unidades->count() : 0;
        $unidades_con_temas = 0;
        
        $total_temas = 0;
        $temas_con_plan = 0;
        $temas_con_preguntas = 0;

        $temas_con_planificacion = 0; // Para el porcentaje de planificación personal subsedes

        if ($this->relationLoaded('unidades')) {
            foreach ($this->unidades as $unidad) {
                if ($unidad->relationLoaded('temas') && $unidad->temas->count() > 0) {
                    $unidades_con_temas++;
                    $total_temas += $unidad->temas->count();
                    
                    foreach ($unidad->temas as $tema) {
                        // Helper para revisar contenido real (ignora tags y espacios HTML, soporta arrays)
                        $hasContent = function($data) {
                            if (empty($data)) return false;
                            
                            $processString = function($html) {
                                if (!is_scalar($html)) return '';
                                return trim(strip_tags(str_replace(['&nbsp;', ' '], '', (string)$html)));
                            };
                            
                            if (is_array($data)) {
                                $text = '';
                                array_walk_recursive($data, function($item) use (&$text, $processString) {
                                    $text .= $processString($item);
                                });
                                return $text !== '';
                            }
                            
                            return $processString($data) !== '';
                        };

                        // Plan de Clase
                        if ($tema->planificacionPersonal && (
                            $hasContent($tema->planificacionPersonal->estrategias_metodologicas) ||
                            $hasContent($tema->planificacionPersonal->secuencia_didactica) ||
                            $hasContent($tema->planificacionPersonal->evaluacion_formativa)
                        )) {
                            $temas_con_plan++;
                        }
                        
                        // Planificación Personal (Cap 2) - Progreso más estricto
                        if ($tema->planificacionPersonal) {
                            $p_estrategias = $hasContent($tema->planificacionPersonal->estrategias_metodologicas) ? 1 : 0;
                            $p_secuencia = $hasContent($tema->planificacionPersonal->secuencia_didactica) ? 1 : 0;
                            $p_evaluacion = $hasContent($tema->planificacionPersonal->evaluacion_formativa) ? 1 : 0;
                            $temas_con_planificacion += ($p_estrategias + $p_secuencia + $p_evaluacion) / 3;
                        }
                        
                        // Preguntas
                        $has_q = false;
                        if ($tema->relationLoaded('logros')) {
                            foreach ($tema->logros as $logro) {
                                if ($logro->relationLoaded('bancoPreguntas') && $logro->bancoPreguntas->count() > 0) {
                                    $has_q = true;
                                    break;
                                }
                            }
                        }
                        if ($has_q) $temas_con_preguntas++;
                    }
                }
            }
        }

        $analitico_pct = $total_unidades > 0 ? round(($unidades_con_temas / $total_unidades) * 100) : 0;
        $plan_clase_pct = $total_temas > 0 ? round(($temas_con_plan / $total_temas) * 100) : 0;
        $planificacion_personal_pct = $total_temas > 0 ? round(($temas_con_planificacion / $total_temas) * 100) : 0;
        $preguntas_pct = $total_temas > 0 ? round(($temas_con_preguntas / $total_temas) * 100) : 0;
        
        $cronograma_pct = ($this->relationLoaded('cronogramas') && $this->cronogramas->count() > 0) ? 100 : 0;

        $getColor = function($pct) {
            if ($pct <= 0) return 'negative';
            if ($pct < 100) return 'warning';
            return 'positive';
        };

        return [
            'programa_asignatura' => ['porcentaje' => $pac_pct, 'color' => $getColor($pac_pct)],
            'programa_analitico' => ['porcentaje' => $analitico_pct, 'color' => $getColor($analitico_pct)],
            'plan_clase' => ['porcentaje' => $plan_clase_pct, 'color' => $getColor($plan_clase_pct)],
            'cronograma' => ['porcentaje' => $cronograma_pct, 'color' => $getColor($cronograma_pct)],
            'preguntas' => ['porcentaje' => $preguntas_pct, 'color' => $getColor($preguntas_pct)],
            'planificacion_personal' => ['porcentaje' => $planificacion_personal_pct, 'color' => $getColor($planificacion_personal_pct)]
        ];
    }

    public function getIndicadoresDocumentacionPorDocente($userId)
    {
        // 1. Programa de Asignatura (PAC)
        $pac_puntos = 0;
        if (!empty($this->justificacion)) $pac_puntos++;
        if (!empty($this->proposito_general)) $pac_puntos++;
        if (!empty($this->competencia_global_especifica) || !empty($this->competencia_asignatura) || !empty($this->elementos_competencia)) $pac_puntos++;
        if ($this->relationLoaded('bibliografias') && $this->bibliografias->count() > 0) $pac_puntos++;
        $pac_pct = round(($pac_puntos / 4) * 100);

        // 2. Programa Analítico, 3. Plan de Clase, 5. Preguntas
        $total_unidades = $this->relationLoaded('unidades') ? $this->unidades->count() : 0;
        $unidades_con_temas = 0;
        
        $total_temas = 0;
        $temas_con_plan = 0;
        $temas_con_preguntas = 0;
        $temas_con_planificacion = 0; 

        if ($this->relationLoaded('unidades')) {
            foreach ($this->unidades as $unidad) {
                if ($unidad->relationLoaded('temas') && $unidad->temas->count() > 0) {
                    $unidades_con_temas++;
                    $total_temas += $unidad->temas->count();
                    
                    foreach ($unidad->temas as $tema) {
                        $miPlan = $tema->relationLoaded('planificacionesPersonales') 
                            ? $tema->planificacionesPersonales->where('user_id', $userId)->first() 
                            : ($tema->relationLoaded('planificacionPersonal') && $tema->planificacionPersonal && $tema->planificacionPersonal->user_id == $userId ? $tema->planificacionPersonal : null);

                        // Helper para revisar contenido real (soportando arrays)
                        $hasContent = function($data) {
                            if (empty($data)) return false;
                            
                            $processString = function($html) {
                                if (!is_scalar($html)) return '';
                                return trim(strip_tags(str_replace(['&nbsp;', ' '], '', (string)$html)));
                            };
                            
                            if (is_array($data)) {
                                $text = '';
                                array_walk_recursive($data, function($item) use (&$text, $processString) {
                                    $text .= $processString($item);
                                });
                                return $text !== '';
                            }
                            
                            return $processString($data) !== '';
                        };

                        // Plan de Clase
                        if ($miPlan && (
                            $hasContent($miPlan->estrategias_metodologicas) ||
                            $hasContent($miPlan->secuencia_didactica) ||
                            $hasContent($miPlan->evaluacion_formativa)
                        )) {
                            $temas_con_plan++;
                        }
                        
                        // Planificación Personal (Cap 2)
                        if ($miPlan) {
                            $p_estrategias = $hasContent($miPlan->estrategias_metodologicas) ? 1 : 0;
                            $p_secuencia = $hasContent($miPlan->secuencia_didactica) ? 1 : 0;
                            $p_evaluacion = $hasContent($miPlan->evaluacion_formativa) ? 1 : 0;
                            $temas_con_planificacion += ($p_estrategias + $p_secuencia + $p_evaluacion) / 3;
                        }
                        
                        // Preguntas (Cap 5 - no depende del usuario normalmente, pero lo dejamos igual)
                        $has_q = false;
                        if ($tema->relationLoaded('logros')) {
                            foreach ($tema->logros as $logro) {
                                if ($logro->relationLoaded('bancoPreguntas') && $logro->bancoPreguntas->count() > 0) {
                                    $has_q = true;
                                    break;
                                }
                            }
                        }
                        if ($has_q) $temas_con_preguntas++;
                    }
                }
            }
        }

        $analitico_pct = $total_unidades > 0 ? round(($unidades_con_temas / $total_unidades) * 100) : 0;
        $plan_clase_pct = $total_temas > 0 ? round(($temas_con_plan / $total_temas) * 100) : 0;
        $planificacion_personal_pct = $total_temas > 0 ? round(($temas_con_planificacion / $total_temas) * 100) : 0;
        $preguntas_pct = $total_temas > 0 ? round(($temas_con_preguntas / $total_temas) * 100) : 0;
        
        $cronograma_pct = ($this->relationLoaded('cronogramas') && $this->cronogramas->count() > 0) ? 100 : 0;

        $getColor = function($pct) {
            if ($pct <= 0) return 'negative';
            if ($pct < 100) return 'warning';
            return 'positive';
        };

        return [
            'programa_asignatura' => ['porcentaje' => $pac_pct, 'color' => $getColor($pac_pct)],
            'programa_analitico' => ['porcentaje' => $analitico_pct, 'color' => $getColor($analitico_pct)],
            'plan_clase' => ['porcentaje' => $plan_clase_pct, 'color' => $getColor($plan_clase_pct)],
            'cronograma' => ['porcentaje' => $cronograma_pct, 'color' => $getColor($cronograma_pct)],
            'preguntas' => ['porcentaje' => $preguntas_pct, 'color' => $getColor($preguntas_pct)],
            'planificacion_personal' => ['porcentaje' => $planificacion_personal_pct, 'color' => $getColor($planificacion_personal_pct)]
        ];
    }
}
