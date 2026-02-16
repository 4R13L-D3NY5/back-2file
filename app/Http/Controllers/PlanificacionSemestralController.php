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
        $targetUserId = Auth::id();
        
        if ($grupoId) {
            $grupo = \App\Models\Grupo::find($grupoId);
            if ($grupo && $grupo->docente_id) {
                $docenteGrupo = \App\Models\Docente::find($grupo->docente_id);
                if ($docenteGrupo && $docenteGrupo->user_id) {
                    $targetUserId = $docenteGrupo->user_id;
                }
            }
        }
        
        if ($request->filled('docente_id')) {
            $docente = \App\Models\Docente::find($request->input('docente_id'));
            if ($docente && $docente->user_id) {
                $targetUserId = $docente->user_id;
            }
        }

        $asignatura = Asignatura::with(['horarios' => function ($q) use ($grupoId) {
            if ($grupoId) {
                $q->where('grupo_id', $grupoId);
            }
        }])->findOrFail($asignaturaId);

        // 1. Fetch Master Records (Shared Planning Content)
        // Master records have group_id = NULL
        $masterCronogramas = Cronograma::where('asignatura_id', $asignaturaId)
            ->whereNull('grupo_id')
            ->with(['temas', 'tema.planificacionPersonal' => function ($query) use ($targetUserId) {
                $query->where('user_id', $targetUserId);
            }])
            ->orderBy('numero_sesion')
            ->get();

        // 2. Fetch Execution Records for the specific group (if requested)
        $executionRecords = collect();
        if ($grupoId) {
            $executionRecords = Cronograma::where('asignatura_id', $asignaturaId)
                ->where('grupo_id', $grupoId)
                ->get()
                ->keyBy('numero_sesion');
        }

        // 3. Map Master Records to the result, merging Execution data if it exists
        $cronogramas = $masterCronogramas->map(function ($master) use ($executionRecords, $grupoId) {
            $execution = $executionRecords->get($master->numero_sesion);

            if ($execution) {
                // Merge group-specific fields into the master structure
                $master->id = $execution->id;
                $master->grupo_id = $execution->grupo_id;
                $master->fecha = $execution->fecha;
                $master->semana_academica = $execution->semana_academica;
                $master->periodo_examen = $execution->periodo_examen;
                $master->observaciones = $this->cleanUtf8($execution->observaciones);
                $master->pedagogico = $this->cleanUtf8($execution->pedagogico);
                $master->cumplido = $execution->cumplido;
            } else if ($grupoId) {
                // Return a template session for the group
                $master->id = null; // Frontend knows it's new for this group
                $master->grupo_id = $grupoId;
                $master->fecha = null;
                $master->cumplido = false;
                $master->pedagogico = null;
            }

            // Cleanup master fields just in case
            $master->contenido_conceptual = $this->cleanUtf8($master->contenido_conceptual);
            $master->contenido_procedimental = $this->cleanUtf8($master->contenido_procedimental);
            $master->contenido_actitudinal = $this->cleanUtf8($master->contenido_actitudinal);
            $master->criterios_desempeno = $this->cleanUtf8($master->criterios_desempeno);
            $master->instrumentos_evaluacion = $this->cleanUtf8($master->instrumentos_evaluacion);
            
            // Resolver Detalles Pedagógicos (defaults)
            $needsResolution = empty($master->pedagogico) ||
                !isset($master->pedagogico['estrategias']) ||
                !isset($master->pedagogico['evaluacion']) ||
                !isset($master->pedagogico['secuencia']);

            if ($needsResolution) {
                $resolved = $this->resolvePedagogicoDefaults($master);
                $master->pedagogico = array_merge(
                    $master->pedagogico ?? [],
                    $resolved
                );
            }

            return $master;
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

            // 2. Sync Horarios (Delete All and Re-create but SCOPED by Group)
            // IMPORTANTE: Solo borrar los horarios del grupo actual para no afectar a otros
            $grupoId = $request->input('grupo_id');
            
            if ($request->has('horarios')) {
                if ($grupoId) {
                    $asignatura->horarios()->where('grupo_id', $grupoId)->delete();
                } else {
                    // Fallback peligroso (solo si no se manda grupo, borrar nulls)
                    $asignatura->horarios()->whereNull('grupo_id')->delete();
                }
                
                // Asignar grupo_id a los nuevos horarios
                $nuevosHorarios = collect($request->input('horarios'))->map(function($h) use ($grupoId) {
                    $h['grupo_id'] = $grupoId;
                    return $h;
                })->toArray();
                
                $asignatura->horarios()->createMany($nuevosHorarios);
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
            foreach ($sesiones as $sesionData) {
                $numeroSesion = $sesionData['numeroGlobal'] ?? $sesionData['numero_sesion'];

                // 1. SAVE MASTER PLANNING (Shared content) - ALWAYS
                // We identify Master record by (asignatura_id, grupo_id=NULL, numero_sesion)
                $master = Cronograma::updateOrCreate(
                    [
                        'asignatura_id' => $asignatura->id,
                        'grupo_id' => null,
                        'numero_sesion' => $numeroSesion,
                    ],
                    [
                        'tema_id' => $sesionData['tema_id'] ?? null,
                        'contenido_conceptual' => $sesionData['conceptual'] ?? null,
                        'contenido_procedimental' => $sesionData['procedimental'] ?? null,
                        'contenido_actitudinal' => $sesionData['actitudinal'] ?? null,
                        'criterios_desempeno' => $sesionData['criteriosDesempeno'] ?? null,
                        'instrumentos_evaluacion' => $sesionData['instrumentosEvaluacion'] ?? null,
                        'contenido_items_seleccionados' => $sesionData['contenido_items_seleccionados'] ?? [],
                        'semana_academica' => $sesionData['semana'] ?? null, // Added missing field
                        // Fix for type: check if key exists, otherwise don't overwite or use default
                    ] + (isset($sesionData['tipoClase']) ? ['tipo_clase' => $sesionData['tipoClase']] : [])
                );

                // Sync multiple topics for Master
                if (isset($sesionData['temas_ids']) && is_array($sesionData['temas_ids'])) {
                     // Verify relation exists before syncing
                     // (Assuming Cronograma belongsToMany Temas, if not, skip)
                     // Based on previous code: $master->temas()
                     $master->temas()->sync($sesionData['temas_ids']);
                } elseif (!empty($sesionData['tema_id'])) {
                     $master->temas()->sync([$sesionData['tema_id']]);
                }

                // 2. SAVE EXECUTION DATA (Group specific) - ONLY IF GROUP SELECTED
                if ($grupoId) {
                    // Check if we really need an execution record.
                    // If date, observations, compliance or specific pedagogy is set.
                    // We DO NOT save content here to avoid duplication, unless explicitly overridden (future feature)

                    $fecha = $this->parseDate($sesionData['fecha'] ?? null);

                    // Only create/update if there is meaningful execution data
                    // But we must support clearing dates too.
                    // So we updateOrCreate.

                    Cronograma::updateOrCreate(
                        [
                            'asignatura_id' => $asignatura->id,
                            'grupo_id' => $grupoId,
                            'numero_sesion' => $numeroSesion,
                        ],
                        [
                            'fecha' => $fecha,
                            'semana_academica' => $sesionData['semana'] ?? null,
                            'periodo_examen' => $sesionData['periodoExamen'] ?? null,
                            'observaciones' => $sesionData['observaciones'] ?? null,
                            // We might want to save cumulative status here
                            // 'cumplido' => ...
                        ]
                    );
                }
            }
        });

        return response()->json([
            'message' => 'Planificación guardada exitosamente',
            'count' => count($sesiones)
        ]);
    }

    /**
     * Genera automáticamente las sesiones basado en Horarios y Fechas
     */
    public function generarPlanificacion(Request $request, $asignaturaId)
    {
        $asignatura = Asignatura::findOrFail($asignaturaId);

        // Validar configuración de sesiones semanales
        $sesionesTeoricas = $asignatura->sesiones_semanales_teoricas ?? 0;
        $sesionesPracticas = $asignatura->sesiones_semanales_practicas ?? 0;

        if ($sesionesTeoricas == 0 && $sesionesPracticas == 0) {
             return response()->json(['error' => 'Configure las sesiones teóricas y prácticas semanales en la pestaña Configuración antes de generar'], 400);
        }

        DB::transaction(function () use ($asignatura, $sesionesTeoricas, $sesionesPracticas) {
            // 1. Limpiar PLANIFICACIÓN MAESTRA existente (solo master, grupo_id = NULL)
            // PRECAUCIÓN: Esto borra el contenido maestro. El usuario debe confirmar "Vaciar" o "Regenerar" en el frontend.
            // Si hay datos de ejecución (docentes), esos están en registros con grupo_id != NULL y NO SE TOCAN.
            Cronograma::where('asignatura_id', $asignatura->id)
                ->whereNull('grupo_id')
                ->delete();

            $sesiones = [];
            $totalSemanas = 20;
            $contadorGlobal = 1;

            for ($semana = 1; $semana <= $totalSemanas; $semana++) {
                // Generar Teóricas
                for ($t = 1; $t <= $sesionesTeoricas; $t++) {
                   $sesiones[] = [
                       'asignatura_id' => $asignatura->id,
                       'grupo_id' => null, // MASTER RECORD
                       'numero_sesion' => $contadorGlobal++,
                       'semana_academica' => $semana,
                       'tipo_clase' => 'Teórica', // Nuevo campo o atributo en JSON
                       'observaciones' => 'Teórica ' . $t, // Fallback visual
                       'fecha' => null, // MASTER tiene fecha NULL
                       'created_at' => now(),
                       'updated_at' => now()
                   ];
                }

                // Generar Prácticas
                for ($p = 1; $p <= $sesionesPracticas; $p++) {
                   $sesiones[] = [
                       'asignatura_id' => $asignatura->id,
                       'grupo_id' => null, // MASTER RECORD
                       'numero_sesion' => $contadorGlobal++,
                       'semana_academica' => $semana,
                       'tipo_clase' => 'Práctica', // Nuevo campo o atributo en JSON
                       'observaciones' => 'Práctica ' . $p, // Fallback visual
                       'fecha' => null, // MASTER tiene fecha NULL
                       'created_at' => now(),
                       'updated_at' => now()
                   ];
                }
            }

            if (!empty($sesiones)) {
                \Log::info('Generando Planificación Maestra. Primera Sesión:', $sesiones[0]);
                Cronograma::insert($sesiones);
            }
        });

        return response()->json(['message' => 'Planificación Maestra generada exitosamente']);
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
        if (empty($dateString)) {
            return null;
        }
        try {
            // Check if string is a valid date
            if (!strtotime($dateString)) return null;
            return \Carbon\Carbon::parse($dateString)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
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
                $defaults['estrategias'][] = ['nombre' => $this->cleanUtf8($est), 'cumplido' => false];
            }
        }
        // Si es Tema base, puede tener metodologías como string
        if (isset($planning->estrategias_metodologicas) && is_string($planning->estrategias_metodologicas)) {
            $defaults['estrategias'][] = ['nombre' => 'Metodología: ' . $this->cleanUtf8(substr($planning->estrategias_metodologicas, 0, 50)), 'cumplido' => false];
        } else if (empty($defaults['estrategias'])) {
            $defaults['estrategias'][] = ['nombre' => 'Clase Magistral', 'cumplido' => false];
        }

        // 2. Evaluación
        $evalSources = [$planning->evaluacion_formativa, $planning->evaluacion_sumativa];
        foreach ($evalSources as $eval) {
            if ($eval && is_array($eval)) {
                if (!empty($eval['actividades'])) {
                    foreach ($eval['actividades'] as $act) {
                        $defaults['evaluacion'][] = ['nombre' => $this->cleanUtf8($act), 'cumplido' => false];
                    }
                }
            }
        }
        if (empty($defaults['evaluacion'])) {
            $defaults['evaluacion'][] = ['nombre' => 'Participación en clase', 'cumplido' => false];
        }

        // 3. Secuencia Didáctica
        if ($planificacionPersonal && !empty($planificacionPersonal->secuencia_didactica)) {
            foreach ($planificacionPersonal->secuencia_didactica as $sec) {
                $nombre = $sec['momento'] ?? 'Actividad';
                if (isset($sec['actividad'])) $nombre .= ': ' . substr($sec['actividad'], 0, 60);
                $defaults['secuencia'][] = ['nombre' => $this->cleanUtf8($nombre), 'cumplido' => false];
            }
        }
        else if ($tema->secuencias->count() > 0) {
            foreach ($tema->secuencias as $sec) {
                $defaults['secuencia'][] = [
                    'nombre' => $this->cleanUtf8($sec->momento . ': ' . substr($sec->descripcion, 0, 60)),
                    'cumplido' => false
                ];
            }
        }
        else {
            $defaults['secuencia'] = [
                ['nombre' => 'Inicio', 'cumplido' => false],
                ['nombre' => 'Desarrollo', 'cumplido' => false],
                ['nombre' => 'Cierre', 'cumplido' => false]
            ];
        }

        return $defaults;
    }

    /**
     * Asegura que un string (o array de strings) sea UTF-8 válido
     */
    private function cleanUtf8($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->cleanUtf8($value);
            }
            return $data;
        }

        if (is_string($data)) {
            // Eliminar caracteres no-UTF8 o mal formados
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }

        return $data;

}
}

