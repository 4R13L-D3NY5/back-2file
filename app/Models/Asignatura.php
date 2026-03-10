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
        'plan_estudios',
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
    /**
     * Helper compartido para verificar si un campo tiene contenido real
     * (ignora HTML vacío, &nbsp;, espacios)
     */
    private function hasRealContent($data): bool
    {
        if (empty($data)) return false;

        $processString = function ($html) {
            if (!is_scalar($html)) return '';
            return trim(strip_tags(str_replace(['&nbsp;', '\u00a0', ' '], '', (string) $html)));
        };

        if (is_array($data)) {
            $text = '';
            array_walk_recursive($data, function ($item) use (&$text, $processString) {
                $text .= $processString($item);
            });
            return $text !== '';
        }

        return $processString($data) !== '';
    }



    /**
     * Calcula el porcentaje de progreso de un tema específico basado en el plan (idéntico al frontend)
     */
    public function calcularProgresoTemaBase($tema, $plan): int
    {
        if (!$tema) return 0;

        // 1. Resultados
        $pResultados = 0;
        $totalCamposRes = 3;
        $camposLlenosRes = 0;
        
        if ($this->hasRealContent($tema->resultado_aprendizaje ?? '')) $camposLlenosRes++;
        
        $logros = $tema->logros ?? collect();
        if ($logros->count() > 0) {
            $hasAnyLogro = false;
            foreach ($logros as $l) {
                if ($this->hasRealContent($l->descripcion ?? '')) {
                    $hasAnyLogro = true;
                    break;
                }
            }
            if ($hasAnyLogro) $camposLlenosRes++;
            
            $hasAnyIndicador = false;
            foreach ($logros as $l) {
                if ($l->relationLoaded('indicadores') && $l->indicadores) {
                    foreach ($l->indicadores as $ind) {
                        if ($this->hasRealContent($ind->descripcion ?? '')) {
                            $hasAnyIndicador = true;
                            break 2;
                        }
                    }
                }
            }
            if ($hasAnyIndicador) $camposLlenosRes++;
            
            if ($logros->count() > 1) {
                foreach ($logros->slice(1) as $l) {
                    $totalCamposRes++;
                    if ($this->hasRealContent($l->descripcion ?? '')) $camposLlenosRes++;
                }
            }
            
            foreach ($logros as $l) {
                $indicadores = $l->relationLoaded('indicadores') && $l->indicadores ? $l->indicadores : collect();
                if ($indicadores->count() > 1) {
                    foreach ($indicadores->slice(1) as $ind) {
                        $totalCamposRes++;
                        if ($this->hasRealContent($ind->descripcion ?? '')) $camposLlenosRes++;
                    }
                }
            }
        }
        $pResultados = $totalCamposRes > 0 ? round(($camposLlenosRes / $totalCamposRes) * 100) : 0;

        // 2. Contenidos
        $pContenidos = 0;
        $contenido_items = $tema->contenido_items ?? [];
        
        // Frontend getAny: ['contenidos.conceptual', 'contenido_conceptual']
        $tema_conceptual = is_string($tema->contenido_conceptual) ? json_decode($tema->contenido_conceptual, true) : $tema->contenido_conceptual;
        $tema_procedimental = is_string($tema->contenido_procedimental) ? json_decode($tema->contenido_procedimental, true) : $tema->contenido_procedimental;
        $tema_actitudinal = is_string($tema->contenido_actitudinal) ? json_decode($tema->contenido_actitudinal, true) : $tema->contenido_actitudinal;

        $conceptual = $tema_conceptual ?? (is_array($contenido_items) ? ($contenido_items['conceptual'] ?? []) : []);
        $procedimental = $tema_procedimental ?? (is_array($contenido_items) ? ($contenido_items['procedimental'] ?? []) : []);
        $actitudinal = $tema_actitudinal ?? (is_array($contenido_items) ? ($contenido_items['actitudinal'] ?? []) : []);
        
        if (!is_array($conceptual)) $conceptual = [$conceptual];
        if (!is_array($procedimental)) $procedimental = [$procedimental];
        if (!is_array($actitudinal)) $actitudinal = [$actitudinal];
        
        $totalCamposCont = 3;
        $camposLlenosCont = 0;
        
        if (count($conceptual) > 0 && count(array_filter($conceptual, fn($v) => $this->hasRealContent($v))) > 0) $camposLlenosCont++;
        if (count($conceptual) > 1) {
            foreach (array_slice($conceptual, 1) as $item) {
                $totalCamposCont++;
                if ($this->hasRealContent($item)) $camposLlenosCont++;
            }
        }
        
        if (count($procedimental) > 0 && count(array_filter($procedimental, fn($v) => $this->hasRealContent($v))) > 0) $camposLlenosCont++;
        if (count($procedimental) > 1) {
            foreach (array_slice($procedimental, 1) as $item) {
                $totalCamposCont++;
                if ($this->hasRealContent($item)) $camposLlenosCont++;
            }
        }
        
        if (count($actitudinal) > 0 && count(array_filter($actitudinal, fn($v) => $this->hasRealContent($v))) > 0) $camposLlenosCont++;
        if (count($actitudinal) > 1) {
            foreach (array_slice($actitudinal, 1) as $item) {
                $totalCamposCont++;
                if ($this->hasRealContent($item)) $camposLlenosCont++;
            }
        }
        
        if ($camposLlenosCont === 0 && ($this->hasRealContent($tema->descripcion ?? '') || $this->hasRealContent(!is_array($contenido_items) ? $contenido_items : ''))) {
            $camposLlenosCont = $totalCamposCont;
        }
        
        $pContenidos = $totalCamposCont > 0 ? round(($camposLlenosCont / $totalCamposCont) * 100) : 0;

        // 3. Estrategias
        $pEstrategias = 0;
        // Frontend getAny fallbacks for strategies
        $metodologicas = ($plan && $this->hasRealContent($plan->estrategias_metodologicas)) ? $plan->estrategias_metodologicas : $tema->estrategias_metodologicas;
        $aprendizaje = ($plan && $this->hasRealContent($plan->estrategias_aprendizaje)) ? $plan->estrategias_aprendizaje : $tema->estrategias_aprendizaje;
        
        $tema_recursos = is_string($tema->estrategias_recursos) ? json_decode($tema->estrategias_recursos, true) : $tema->estrategias_recursos;
        $recursosEst = ($plan && !empty($plan->estrategias_recursos)) ? $plan->estrategias_recursos : ($tema_recursos ?? []);
        
        if (!is_array($recursosEst)) $recursosEst = [$recursosEst];
        
        $totalCamposEst = 3;
        $camposLlenosEst = 0;
        
        if ($this->hasRealContent($metodologicas)) $camposLlenosEst++;
        if ($this->hasRealContent($aprendizaje)) $camposLlenosEst++;
        
        if (count($recursosEst) > 0 && count(array_filter($recursosEst, fn($v) => $this->hasRealContent($v))) > 0) $camposLlenosEst++;
        if (count($recursosEst) > 1) {
            foreach (array_slice($recursosEst, 1) as $rec) {
                $totalCamposEst++;
                if ($this->hasRealContent($rec)) $camposLlenosEst++;
            }
        }
        
        $pEstrategias = $totalCamposEst > 0 ? round(($camposLlenosEst / $totalCamposEst) * 100) : 0;

        // 4. Evaluación
        $pEvaluacion = 0;
        
        // Evaluate Planificacion first, fallback to Tema directly
        $tema_ef = is_string($tema->evaluacion_formativa) ? json_decode($tema->evaluacion_formativa, true) : $tema->evaluacion_formativa;
        $tema_es = is_string($tema->evaluacion_sumativa) ? json_decode($tema->evaluacion_sumativa, true) : $tema->evaluacion_sumativa;
        
        $ef = ($plan && !empty($plan->evaluacion_formativa)) ? $plan->evaluacion_formativa : ($tema_ef ?? []);
        $es = ($plan && !empty($plan->evaluacion_sumativa)) ? $plan->evaluacion_sumativa : ($tema_es ?? []);
        
        $totalCamposEval = 6;
        $camposLlenosEval = 0;
        
        $hasListFunc = fn($v) => (is_array($v) && count(array_filter((array)$v, fn($i) => $this->hasRealContent($i))) > 0) || (is_string($v) && $this->hasRealContent($v));
        
        if (is_array($ef)) {
            if ($hasListFunc($ef['actividades'] ?? [])) $camposLlenosEval++;
            if ($hasListFunc($ef['instrumentos'] ?? [])) $camposLlenosEval++;
            if ($hasListFunc($ef['evidencias'] ?? [])) $camposLlenosEval++;
        } elseif ($this->hasRealContent($ef)) {
            $camposLlenosEval += 3;
        }
        
        if (is_array($es)) {
            if ($hasListFunc($es['actividades'] ?? [])) $camposLlenosEval++;
            if ($hasListFunc($es['instrumentos'] ?? [])) $camposLlenosEval++;
            if ($hasListFunc($es['evidencias'] ?? [])) $camposLlenosEval++;
        } elseif ($this->hasRealContent($es)) {
            $camposLlenosEval += 3;
        }
        
        $pEvaluacion = $totalCamposEval > 0 ? round(($camposLlenosEval / $totalCamposEval) * 100) : 0;

        // 5. Secuencia
        $pSecuencia = 0;
        
        // Evaluate Planificacion first, fallback to Tema directly's Secuencias relation OR Secuencia_didactica
        $tema_secuencias = $tema->relationLoaded('secuencias') ? $tema->secuencias->toArray() : [];
        $secuencia = ($plan && !empty($plan->secuencia_didactica)) ? $plan->secuencia_didactica : $tema_secuencias;
        
        $totalCamposSec = 3;
        $camposLlenosSec = 0;
        
        if (is_array($secuencia) && count($secuencia) > 0) {
            foreach ($secuencia as $momento) {
                if ($this->hasRealContent($momento['actividad'] ?? '')) $camposLlenosSec++;
            }
            if (count($secuencia) > 3) $totalCamposSec = count($secuencia);
            $pSecuencia = min(100, round(($camposLlenosSec / $totalCamposSec) * 100));
        } elseif ($this->hasRealContent($secuencia)) {
            $pSecuencia = 100;
        }
        
        return (int) round(($pResultados + $pContenidos + $pEstrategias + $pEvaluacion + $pSecuencia) / 5);
    }

    private function calcularProgresoPorCriterios(?int $userId = null): int
    {
        $indicadores = $this->getIndicadoresDocumentacionAtPorDocenteShared($userId);
        $pac_pct = $indicadores['programa_asignatura']['porcentaje'];
        $analitico_pct = $indicadores['programa_analitico']['porcentaje'];
        $plan_clase_pct = $indicadores['plan_clase']['porcentaje'];
        
        return round(($pac_pct + $analitico_pct + $plan_clase_pct) / 3);
    }

    public function getEstadisticasProgresoAttribute()
    {
        $porcentaje = $this->calcularProgresoPorCriterios();
        return [
            'total'      => 100,
            'completados'=> $porcentaje,
            'pendientes' => 100 - $porcentaje,
            'porcentaje' => $porcentaje,
        ];
    }

    public function getProgresoAttribute()
    {
        return $this->calcularProgresoPorCriterios();
    }

    public function getProgresoPorDocente($userId)
    {
        return $this->calcularProgresoPorCriterios((int) $userId);
    }

    public function getIndicadoresDocumentacionAttribute()
    {
        return $this->getIndicadoresDocumentacionAtPorDocenteShared();
    }

    public function getIndicadoresDocumentacionPorDocente($userId)
    {
        return $this->getIndicadoresDocumentacionAtPorDocenteShared($userId);
    }

    private function getIndicadoresDocumentacionAtPorDocenteShared(?int $userId = null)
    {
        // 1. Programa de Asignatura (PAC)
        $pac_puntos = 0;
        if ($this->hasRealContent($this->justificacion)) $pac_puntos++;
        if ($this->hasRealContent($this->proposito_general)) $pac_puntos++;
        if ($this->hasRealContent($this->competencia_global_especifica) || $this->hasRealContent($this->competencia_asignatura) || $this->hasRealContent($this->elementos_competencia)) $pac_puntos++;
        if ($this->relationLoaded('bibliografias') && $this->bibliografias->count() > 0) $pac_puntos++;
        $pac_pct = round(($pac_puntos / 4) * 100);

        // 2. Programa Analítico, 3. Plan de Clase, 5. Preguntas, 6. Planificacion Personal
        $total_unidades = $this->relationLoaded('unidades') ? $this->unidades->count() : 0;
        $unidades_con_temas = 0;
        
        $total_temas = 0;
        $temas_con_preguntas = 0;
        $temas_con_planificacion = 0;
        $plan_clase_por_unidades = 0;

        if ($this->relationLoaded('unidades')) {
            foreach ($this->unidades as $unidad) {
                if ($unidad->relationLoaded('temas') && $unidad->temas->count() > 0) {
                    $unidades_con_temas++;
                    $total_temas += $unidad->temas->count();
                    
                    $progreso_temas_suma = 0;

                    foreach ($unidad->temas as $tema) {
                        if ($userId !== null) {
                            $plan = $tema->relationLoaded('planificacionesPersonales')
                                ? $tema->planificacionesPersonales->where('user_id', $userId)->first()
                                : ($tema->relationLoaded('planificacionPersonal') &&
                                   $tema->planificacionPersonal &&
                                   $tema->planificacionPersonal->user_id == $userId
                                    ? $tema->planificacionPersonal : null);
                        } else {
                            $plan = $tema->relationLoaded('planificacionPersonal')
                                ? $tema->planificacionPersonal : null;
                        }

                        $progreso_temas_suma += $this->calcularProgresoTemaBase($tema, $plan);

                        // Planificación Personal (progreso por docente - 3 campos del plan)
                        if ($plan) {
                            $p_estrategias = $this->hasRealContent($plan->estrategias_metodologicas) ? 1 : 0;
                            $p_secuencia = $this->hasRealContent($plan->secuencia_didactica) ? 1 : 0;
                            $p_evaluacion = $this->hasRealContent($plan->evaluacion_formativa) ? 1 : 0;
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
                    
                    $plan_clase_por_unidades += round($progreso_temas_suma / $unidad->temas->count());
                }
            }
        }

        $analitico_pct = $total_unidades > 0 ? round(($unidades_con_temas / $total_unidades) * 100) : 0;
        $plan_clase_pct = $unidades_con_temas > 0 ? round($plan_clase_por_unidades / $unidades_con_temas) : 0;
        $planificacion_personal_pct = $total_temas > 0 ? round(($temas_con_planificacion / $total_temas) * 100) : 0;
        $preguntas_pct = $total_temas > 0 ? round(($temas_con_preguntas / $total_temas) * 100) : 0;

        $getColor = function($pct) {
            if ($pct <= 0) return 'negative';
            if ($pct < 100) return 'warning';
            return 'positive';
        };

        return [
            'programa_asignatura' => ['porcentaje' => $pac_pct, 'color' => $getColor($pac_pct)],
            'programa_analitico'  => ['porcentaje' => $analitico_pct, 'color' => $getColor($analitico_pct)],
            'plan_clase'          => ['porcentaje' => $plan_clase_pct, 'color' => $getColor($plan_clase_pct)],
            'preguntas'           => ['porcentaje' => $preguntas_pct, 'color' => $getColor($preguntas_pct)],
            'planificacion_personal' => ['porcentaje' => $planificacion_personal_pct, 'color' => $getColor($planificacion_personal_pct)],
            'cronograma'          => ['porcentaje' => ($this->relationLoaded('cronogramas') && $this->cronogramas->count() > 0) ? 100 : 0, 'color' => ($this->relationLoaded('cronogramas') && $this->cronogramas->count() > 0) ? 'positive' : 'negative'],
        ];
    }
}