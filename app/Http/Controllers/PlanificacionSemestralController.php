<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\Cronograma;
use App\Models\Horario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PlanificacionSemestralController extends Controller
{
    /**
     * Obtiene todo el estado de la planificación semestral
     * (Configuración, Horarios, Sessions de Cronograma)
     */
    public function index($asignaturaId, Request $request)
    {
        $grupoId = $request->input('grupo_id');

        // IMPORTANTE: docente_id es el ID de la tabla 'docentes', NO el user_id
        // Debemos convertir docente_id -> user_id para filtrar PlanificacionPersonal
        $targetUserId = Auth::id();
        if ($request->filled('docente_id')) {
            $docente = \App\Models\Docente::find($request->input('docente_id'));
            if ($docente && $docente->user_id) {
                $targetUserId = $docente->user_id;
            }
        }

        $asignatura = Asignatura::with(['horarios', 'cronogramas' => function ($q) use ($grupoId, $targetUserId) {
            $q->orderBy('numero_sesion')
                ->with([
                    'temas',
                    'tema.secuencias',
                    'tema.planificacionPersonal' => function ($query) use ($targetUserId) {
                        $query->where('user_id', $targetUserId);
                    }
                ]);

            if ($grupoId) {
                $q->where('grupo_id', $grupoId);
            }

            // FILTER: Strict 'My Sessions' for Teachers (Rol ID 6 = DOCENTE)
            // Fixes duplicate sessions in 'Planificación por Unidades' view
            $currentUser = Auth::user();
            if ($currentUser && $currentUser->rol_id === 6 && $currentUser->docente) {
                $q->whereHas('grupo', function ($gq) use ($currentUser) {
                    $gq->where('docente_id', $currentUser->docente->id);
                });
            }
        }])->findOrFail($asignaturaId);

        // Resolver Detalles Pedagógicos para cada sesión
        $cronogramas = $asignatura->cronogramas->map(function ($cronograma) {
            // Check if pedagogico is missing required fields (estrategias, evaluacion, secuencia)
            $needsResolution = empty($cronograma->pedagogico) ||
                !isset($cronograma->pedagogico['estrategias']) ||
                !isset($cronograma->pedagogico['evaluacion']) ||
                !isset($cronograma->pedagogico['secuencia']);

            if ($needsResolution) {
                $resolved = $this->resolvePedagogicoDefaults($cronograma);
                // Merge with existing pedagogico data (preserve tipo_sesion, etc.)
                $cronograma->pedagogico = array_merge(
                    $cronograma->pedagogico ?? [],
                    $resolved
                );
            }
            return $cronograma;
        });

        return response()->json([
            'config' => [
                'fecha_inicio_clases' => $asignatura->fecha_inicio_clases,
                'fecha_fin_clases' => $asignatura->fecha_fin_clases,
                'gestion_academica' => $asignatura->gestion_academica
            ],
            'horarios' => $asignatura->horarios,
            'planificacion' => $cronogramas
        ]);
    }

    /**
     * Guarda la configuración del calendario y los horarios
     */
    public function saveConfig(Request $request, $asignaturaId)
    {
        $asignatura = Asignatura::findOrFail($asignaturaId);

        DB::transaction(function () use ($asignatura, $request) {
            // 1. Update Asignatura Dates
            $asignatura->update($request->only([
                'fecha_inicio_clases',
                'fecha_fin_clases',
                'gestion_academica'
            ]));

            // 2. Sync Horarios (Delete All and Re-create)
            if ($request->has('horarios')) {
                $asignatura->horarios()->delete();
                $asignatura->horarios()->createMany($request->input('horarios'));
            }
        });

        return response()->json(['message' => 'Configuración guardada']);
    }

    /**
     * Guarda (sobrescribe) la planificación de sesiones (Cronograma)
     */
    public function savePlanificacion(Request $request, $asignaturaId)
    {
        $asignatura = Asignatura::findOrFail($asignaturaId);
        $sesiones = $request->input('sesiones', []);
        $grupoId = $request->input('grupo_id');

        DB::transaction(function () use ($asignatura, $sesiones, $grupoId) {

            if ($grupoId) {
                $asignatura->cronogramas()->where('grupo_id', $grupoId)->delete();
            } else {
                $asignatura->cronogramas()->whereNull('grupo_id')->delete();
            }

            foreach ($sesiones as $sesionData) {
                $cronograma = $asignatura->cronogramas()->create([
                    'numero_sesion' => $sesionData['numeroGlobal'] ?? $sesionData['numero_sesion'],
                    'fecha' => $this->parseDate($sesionData['fecha']),
                    'semana_academica' => $sesionData['semana'] ?? null,
                    'periodo_examen' => $sesionData['periodoExamen'] ?? null,
                    'tema_id' => $sesionData['tema_id'] ?? null,
                    'grupo_id' => $grupoId,
                    'contenido_conceptual' => $sesionData['conceptual'] ?? null,
                    'contenido_procedimental' => $sesionData['procedimental'] ?? null,
                    'contenido_actitudinal' => $sesionData['actitudinal'] ?? null,
                    'criterios_desempeno' => $sesionData['criteriosDesempeno'] ?? null,
                    'instrumentos_evaluacion' => $sesionData['instrumentosEvaluacion'] ?? null,
                    'observaciones' => $sesionData['observaciones'] ?? null,
                    'contenido_items_seleccionados' => $sesionData['contenido_items_seleccionados'] ?? []
                ]);

                // Sincronizar múltiples temas si vienen en el request
                if (isset($sesionData['temas_ids']) && is_array($sesionData['temas_ids'])) {
                    $cronograma->temas()->sync($sesionData['temas_ids']);
                } elseif (!empty($sesionData['tema_id'])) {
                    $cronograma->temas()->sync([$sesionData['tema_id']]);
                }
            }
        });

        return response()->json([
            'message' => 'Planificación guardada',
            'count' => count($sesiones)
        ]);
    }

    /**
     * Genera automáticamente las sesiones basado en Horarios y Fechas
     */
    public function generarPlanificacion(Request $request, $asignaturaId)
    {
        $asignatura = Asignatura::with('horarios')->findOrFail($asignaturaId);

        if (!$asignatura->fecha_inicio_clases || !$asignatura->horarios->count()) {
            return response()->json(['error' => 'Configure fechas y horario primero'], 400);
        }

        $startDate = \Carbon\Carbon::parse($asignatura->fecha_inicio_clases);
        $endDate = \Carbon\Carbon::parse($asignatura->fecha_fin_clases);
        $horarios = $asignatura->horarios;
        $sesiones = [];
        $count = 1;

        // Semanas Académicas (1 a 20)
        for ($semana = 1; $semana <= 20; $semana++) {

            // Determinar si es semana de examen (Lógica fija solicitada)
            $periodoExamen = null;
            if ($semana >= 7 && $semana <= 8) $periodoExamen = '1er Parcial';
            elseif ($semana >= 14 && $semana <= 15) $periodoExamen = '2do Parcial';
            elseif ($semana >= 18 && $semana <= 19) $periodoExamen = 'Examen Final';
            elseif ($semana == 20) $periodoExamen = '2da Instancia';

            // Iterar horarios para esta semana
            foreach ($horarios as $horario) {
                // Calcular fecha exacta
                $dayMap = [
                    'Lunes' => 1,
                    'Martes' => 2,
                    'Miercoles' => 3,
                    'Miércoles' => 3,
                    'Jueves' => 4,
                    'Viernes' => 5,
                    'Sabado' => 6,
                    'Sábado' => 6
                ];

                $targetDia = $dayMap[ucfirst($horario->dia)] ?? 1;

                // Fecha base de la semana actual
                $weekStart = $startDate->copy()->addWeeks($semana - 1)->startOfWeek();
                // Ajustar al día específico
                $sessionDate = $weekStart->copy()->addDays($targetDia - 1);

                $sesiones[] = [
                    'asignatura_id' => $asignatura->id,
                    'numero_sesion' => $count++,
                    'fecha' => $sessionDate->format('Y-m-d'),
                    'semana_academica' => $semana,
                    'periodo_examen' => $periodoExamen,
                    'grupo_id' => $request->input('grupo_id'), // Link to Group
                    // Si es examen, no lleva contenido (user request)
                    'contenido_conceptual' => $periodoExamen ? null : '',
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
        }

        // Reemplazar existente
        DB::transaction(function () use ($asignatura, $sesiones, $request) {
            $grupoId = $request->input('grupo_id');
            if ($grupoId) {
                $asignatura->cronogramas()->where('grupo_id', $grupoId)->delete();
            } else {
                $asignatura->cronogramas()->whereNull('grupo_id')->delete();
            }
            Cronograma::insert($sesiones);
        });

        return response()->json(['message' => 'Planificación generada', 'total' => count($sesiones)]);
    }

    /**
     * Copia la planificación (Cronogramas) de otra asignatura (Unificación)
     */
    public function copiarPlanificacion(Request $request, $asignaturaId)
    {
        $request->validate(['source_asignatura_id' => 'required|exists:asignaturas,id']);

        $target = Asignatura::findOrFail($asignaturaId);
        $source = Asignatura::with('cronogramas')->findOrFail($request->source_asignatura_id);

        DB::transaction(function () use ($target, $source) {
            // Borrar actual
            $target->cronogramas()->delete();

            // Copiar
            $newCronogramas = [];
            foreach ($source->cronogramas as $c) {
                // Implementation pending based on user requirements for copying
            }

            // Implementación "Update Content by Session Number"
            foreach ($source->cronogramas as $sourceItem) {
                $target->cronogramas()
                    ->where('numero_sesion', $sourceItem->numero_sesion)
                    ->update([
                        'contenido_conceptual' => $sourceItem->contenido_conceptual,
                        'contenido_procedimental' => $sourceItem->contenido_procedimental,
                        'contenido_actitudinal' => $sourceItem->contenido_actitudinal,
                        'criterios_desempeno' => $sourceItem->criterios_desempeno,
                        'instrumentos_evaluacion' => $sourceItem->instrumentos_evaluacion,
                        'tema_id' => $sourceItem->tema_id
                    ]);
            }
        });

        return response()->json(['message' => 'Contenidos importados correctamente']);
    }

    /**
     * Actualiza el seguimiento de una sesión específica de cronograma
     */
    public function updateSeguimiento(Request $request, $id)
    {
        $cronograma = Cronograma::findOrFail($id);

        // Parse pedagogico JSON
        $pedagogico = json_decode($request->input('pedagogico', '{}'), true);
        $integracionTransversal = json_decode($request->input('integracion_transversal', '{}'), true);

        // Handle evidence file uploads
        $evidencias = [];

        // Aprendizaje Activo
        if ($request->hasFile('evidencia_aprendizaje')) {
            $file = $request->file('evidencia_aprendizaje');
            $path = $file->store('evidencias/aprendizaje', 'public');
            $evidencias['aprendizaje_activo'] = $path;
        }

        // Evaluación Formativa (can be file or text)
        if ($request->hasFile('evidencia_evaluacion')) {
            $file = $request->file('evidencia_evaluacion');
            $path = $file->store('evidencias/evaluacion', 'public');
            $evidencias['evaluacion_formativa'] = $path;
        } elseif ($request->filled('evidencia_evaluacion')) {
            $evidencias['evaluacion_formativa'] = $request->input('evidencia_evaluacion');
        }

        // Secuencia Didáctica
        if ($request->hasFile('evidencia_secuencia')) {
            $file = $request->file('evidencia_secuencia');
            $path = $file->store('evidencias/secuencia', 'public');
            $evidencias['secuencia_didactica'] = $path;
        }

        // Integración Transversal evidences
        $integracionEvidencias = [];

        if ($request->hasFile('evidencia_investigacion')) {
            $file = $request->file('evidencia_investigacion');
            $path = $file->store('evidencias/investigacion', 'public');
            $integracionEvidencias['investigacion'] = $path;
        }

        if ($request->hasFile('evidencia_interaccion')) {
            $file = $request->file('evidencia_interaccion');
            $path = $file->store('evidencias/interaccion', 'public');
            $integracionEvidencias['interaccion'] = $path;
        }

        if ($request->hasFile('evidencia_internalizacion')) {
            $file = $request->file('evidencia_internalizacion');
            $path = $file->store('evidencias/internalizacion', 'public');
            $integracionEvidencias['internalizacion'] = $path;
        }

        // Merge integración transversal data with evidences
        foreach ($integracionTransversal as $key => $value) {
            if (isset($integracionEvidencias[$key])) {
                $integracionTransversal[$key]['evidencia'] = $integracionEvidencias[$key];
            }
        }

        // Build complete pedagogico object
        // Include tema_cumplido as pedagogical information
        $completePedagogico = array_merge($pedagogico, [
            'tema_cumplido' => filter_var($request->input('tema_cumplido', false), FILTER_VALIDATE_BOOLEAN),
            'evidencias' => $evidencias,
            'integracionTransversal' => $integracionTransversal
        ]);

        // Session is marked as completed when teacher saves the follow-up
        // regardless of whether the planned topic was covered
        $cronograma->update([
            'cumplido' => true,  // Always true when saving follow-up
            'observaciones' => $request->input('observaciones'),
            'pedagogico' => $completePedagogico
        ]);

        return response()->json([
            'message' => 'Seguimiento guardado correctamente',
            'pedagogico' => $completePedagogico,
            'cumplido' => true
        ]);
    }

    private function parseDate($dateString)
    {
        return \Carbon\Carbon::parse($dateString)->format('Y-m-d');
    }

    /**
     * Resuelve los valores por defecto de los detalles pedagógicos
     * Basado en Planificación Personal > Tema > Defaults
     */
    private function resolvePedagogicoDefaults($cronograma)
    {
        $defaults = [
            'estrategias' => [],
            'evaluacion' => [],
            'secuencia' => [],
            'integracion' => [
                'investigacion' => [
                    ['nombre' => 'Verificación de investigación', 'cumplido' => false],
                    ['nombre' => 'Análisis crítico', 'cumplido' => false]
                ],
                'interaccion' => [
                    ['nombre' => 'Interacción social', 'cumplido' => false],
                    ['nombre' => 'Trabajo en equipo', 'cumplido' => false]
                ],
                'internalizacion' => [
                    ['nombre' => 'Internalización de valores', 'cumplido' => false],
                    ['nombre' => 'Aplicación ética', 'cumplido' => false]
                ]
            ]
        ];

        if (!$cronograma->tema) {
            return $defaults;
        }

        $tema = $cronograma->tema;

        // Check if planificacionPersonal was eager loaded and exists
        // The relationship is loaded with user_id constraint in the index method
        $planificacionPersonal = null;
        if ($tema->relationLoaded('planificacionPersonal')) {
            $planificacionPersonal = $tema->planificacionPersonal;
        }

        // Prioridad: Planificación Personal -> Tema Base
        $planning = $planificacionPersonal ?? $tema;

        // Debug logging
        \Log::info('Resolving pedagogico for tema_id: ' . $tema->id, [
            'has_planificacion_personal' => !is_null($planificacionPersonal),
            'planning_type' => get_class($planning),
            'estrategias_recursos' => $planning->estrategias_recursos ?? 'null',
            'evaluacion_formativa' => $planning->evaluacion_formativa ?? 'null',
            'secuencia_didactica' => $planning->secuencia_didactica ?? 'null'
        ]);

        // 1. Estrategias
        if (!empty($planning->estrategias_recursos)) {
            foreach ($planning->estrategias_recursos as $est) {
                $defaults['estrategias'][] = ['nombre' => $est, 'cumplido' => false];
            }
        }
        // Si es Tema base, puede tener metodologías como string
        if (isset($planning->estrategias_metodologicas) && is_string($planning->estrategias_metodologicas)) {
            $defaults['estrategias'][] = ['nombre' => 'Metodología: ' . substr($planning->estrategias_metodologicas, 0, 50), 'cumplido' => false];
        } else if (empty($defaults['estrategias'])) {
            $defaults['estrategias'][] = ['nombre' => 'Clase Magistral', 'cumplido' => false];
        }

        // 2. Evaluación
        // Estructura en JSON: evaluacion_formativa: { actividades: [], instrumentos: [] }
        $evalSources = [$planning->evaluacion_formativa, $planning->evaluacion_sumativa];
        foreach ($evalSources as $eval) {
            if ($eval && is_array($eval)) {
                if (!empty($eval['actividades'])) {
                    foreach ($eval['actividades'] as $act) {
                        $defaults['evaluacion'][] = ['nombre' => $act, 'cumplido' => false];
                    }
                }
            }
        }
        if (empty($defaults['evaluacion'])) {
            $defaults['evaluacion'][] = ['nombre' => 'Participación en clase', 'cumplido' => false];
        }

        // 3. Secuencia Didáctica
        // En PlanificacionPersonal es un JSON (secuencia_didactica array)
        if ($planificacionPersonal && !empty($planificacionPersonal->secuencia_didactica)) {
            foreach ($planificacionPersonal->secuencia_didactica as $sec) {
                $nombre = $sec['momento'] ?? 'Actividad';
                if (isset($sec['actividad'])) $nombre .= ': ' . substr($sec['actividad'], 0, 60);
                $defaults['secuencia'][] = ['nombre' => $nombre, 'cumplido' => false];
            }
        }
        // En Tema es una relación (secuencias)
        else if ($tema->secuencias->count() > 0) {
            foreach ($tema->secuencias as $sec) {
                $defaults['secuencia'][] = [
                    'nombre' => $sec->momento . ': ' . substr($sec->descripcion, 0, 60),
                    'cumplido' => false
                ];
            }
        }
        // Fallback
        else {
            $defaults['secuencia'] = [
                ['nombre' => 'Inicio', 'cumplido' => false],
                ['nombre' => 'Desarrollo', 'cumplido' => false],
                ['nombre' => 'Cierre', 'cumplido' => false]
            ];
        }

        return $defaults;
    }
}
