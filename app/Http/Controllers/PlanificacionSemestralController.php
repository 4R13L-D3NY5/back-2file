<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\Cronograma;
use App\Models\Horario;
use App\Models\Seguimiento;
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
            ->with([
                'temas',
                'temas.secuencias',
                'temas.planificacionPersonal' => function ($query) use ($targetUserId) {
                    $query->where('user_id', $targetUserId);
                },
                'tema.secuencias',
                'tema.planificacionPersonal' => function ($query) use ($targetUserId) {
                    $query->where('user_id', $targetUserId);
                },
            ])
            ->orderBy('numero_sesion')
            ->get();

        // 2. Fetch Seguimientos for the specific group (from new seguimientos table)
        $seguimientosMap = collect();
        if ($grupoId) {
            $cronogramaIds = $masterCronogramas->pluck('id');
            $seguimientosMap = Seguimiento::whereIn('cronograma_id', $cronogramaIds)
                ->where('grupo_id', $grupoId)
                ->get()
                ->keyBy('cronograma_id');
        }

        // 3. Map Master Records to the result, merging Seguimiento data if it exists
        $cronogramas = $masterCronogramas->map(function ($master) use ($seguimientosMap, $grupoId) {
            $seguimiento = $seguimientosMap->get($master->id);

            // Always keep master ID (cronograma_id) for the frontend
            $masterData = $master->toArray();
            $masterData['cronograma_id'] = $master->id; // The real cronograma ID
            $masterData['seguimiento_id'] = null;
            $masterData['cumplido'] = false; // Reset by default, only true if seguimiento exists below

            if ($seguimiento) {
                // Merge seguimiento data
                $masterData['seguimiento_id'] = $seguimiento->id;
                $masterData['grupo_id'] = $seguimiento->grupo_id;
                $masterData['fecha'] = $seguimiento->fecha?->format('Y-m-d');
                $masterData['observaciones'] = $this->cleanUtf8($seguimiento->observaciones);
                $masterData['cumplido'] = $seguimiento->cumplido;
                $masterData['estado_cumplimiento'] = $seguimiento->estado_cumplimiento;
                // Merge saved pedagogico with defaults
                $masterData['pedagogico'] = $this->cleanUtf8($seguimiento->pedagogico);
                $masterData['evidencias'] = $seguimiento->evidencias;
                $masterData['integracion_transversal'] = $seguimiento->integracion_transversal;
                $masterData['seguimiento_created_at'] = $seguimiento->created_at?->toIso8601String();
            } else if ($grupoId) {
                $masterData['grupo_id'] = $grupoId;
                $masterData['fecha'] = null;
                $masterData['cumplido'] = false;
                $masterData['pedagogico'] = null;
            }

            // Cleanup master fields
            $masterData['contenido_conceptual'] = $this->cleanUtf8($master->contenido_conceptual);
            $masterData['contenido_procedimental'] = $this->cleanUtf8($master->contenido_procedimental);
            $masterData['contenido_actitudinal'] = $this->cleanUtf8($master->contenido_actitudinal);
            $masterData['criterios_desempeno'] = $this->cleanUtf8($master->criterios_desempeno);
            $masterData['instrumentos_evaluacion'] = $this->cleanUtf8($master->instrumentos_evaluacion);
            
            // Resolver Detalles Pedagógicos (defaults) if not saved from seguimiento
            $pedagogico = $masterData['pedagogico'] ?? [];
            $needsResolution = empty($pedagogico) ||
                !isset($pedagogico['estrategias']) ||
                !isset($pedagogico['evaluacion']) ||
                !isset($pedagogico['secuencia']);

            if ($needsResolution) {
                $resolved = $this->resolvePedagogicoDefaults($master);
                $masterData['pedagogico'] = array_merge(
                    $pedagogico ?? [],
                    $resolved
                );
            }

            return $masterData;
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
                        'observaciones' => $sesionData['observaciones'] ?? null,
                        'semana_academica' => $sesionData['semana'] ?? null,
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

                // NOTE: Execution records removed. Follow-up data now stored in 'seguimientos' table.
                // Dates are calculated on-the-fly in the frontend based on horarios.
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
                       'tipo_clase' => 'Teórica',
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
                       'tipo_clase' => 'Práctica',
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
     * Guarda/Actualiza el seguimiento de una sesión en la tabla 'seguimientos'
     */
    public function updateSeguimiento(Request $request)
    {
        try {
            \Log::info('updateSeguimiento (new table)', $request->all());

            // Resolve the master cronograma record
            $cronogramaId = $request->input('cronograma_id');
            $grupoId = $request->input('grupo_id');

            if (!$cronogramaId || !$grupoId) {
                // Fallback: find by asignatura + numero_sesion
                $asignaturaId = $request->input('asignatura_id');
                $numeroSesion = $request->input('numero_sesion');

                if (!$asignaturaId || !$grupoId || !$numeroSesion) {
                    return response()->json(['error' => 'Faltan datos: cronograma_id o (asignatura_id + grupo_id + numero_sesion)'], 422);
                }

                $cronograma = Cronograma::where('asignatura_id', $asignaturaId)
                    ->whereNull('grupo_id')
                    ->where('numero_sesion', $numeroSesion)
                    ->first();

                if (!$cronograma) {
                    return response()->json(['error' => "No se encontró la sesión #$numeroSesion del cronograma maestro"], 404);
                }
                $cronogramaId = $cronograma->id;
            }

            // Parse pedagogico JSON
            $pedagogicoInput = $request->input('pedagogico', '{}');
            $pedagogico = json_decode($pedagogicoInput, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                \Log::error('JSON Decode Error in pedagogico: ' . json_last_error_msg());
                $pedagogico = [];
            }

            $integracionInput = $request->input('integracion_transversal', '{}');
            $integracionTransversal = json_decode($integracionInput, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                \Log::error('JSON Decode Error in integracion_transversal: ' . json_last_error_msg());
                $integracionTransversal = [];
            }

            // Handle evidence file uploads
            $evidencias = [];
            
            // Re-use existing evidence paths if provided as strings
            $existingEvidencias = $request->input('evidencias') ? json_decode($request->input('evidencias'), true) : [];
            if (json_last_error() !== JSON_ERROR_NONE) $existingEvidencias = [];

            if ($request->hasFile('evidencia_aprendizaje')) {
                $evidencias['aprendizaje_activo'] = $request->file('evidencia_aprendizaje')->store('evidencias/aprendizaje', 'public');
            } else {
                $evidencias['aprendizaje_activo'] = $existingEvidencias['aprendizaje_activo'] ?? null;
            }

            if ($request->hasFile('evidencia_evaluacion')) {
                $evidencias['evaluacion_formativa'] = $request->file('evidencia_evaluacion')->store('evidencias/evaluacion', 'public');
            } else {
                // If it's a string (link or existing path), keep it
                $evidencias['evaluacion_formativa'] = $request->input('evidencia_evaluacion') ?: ($existingEvidencias['evaluacion_formativa'] ?? null);
            }

            if ($request->hasFile('evidencia_secuencia')) {
                $evidencias['secuencia_didactica'] = $request->file('evidencia_secuencia')->store('evidencias/secuencia', 'public');
            } else {
                $evidencias['secuencia_didactica'] = $existingEvidencias['secuencia_didactica'] ?? null;
            }

            // Integración Transversal evidence files
            $integracionEvidencias = [];
            foreach (['investigacion', 'interaccion', 'internalizacion'] as $tipo) {
                if ($request->hasFile("evidencia_$tipo")) {
                    $integracionEvidencias[$tipo] = $request->file("evidencia_$tipo")->store("evidencias/$tipo", 'public');
                } else {
                    $integracionEvidencias[$tipo] = $integracionTransversal[$tipo]['evidencia'] ?? null;
                }
            }
            foreach ($integracionTransversal as $key => $value) {
                if (isset($integracionEvidencias[$key])) {
                    $integracionTransversal[$key]['evidencia'] = $integracionEvidencias[$key];
                }
            }

            // Build pedagogico with estado
            $isCumplido = filter_var($request->input('tema_cumplido', false), FILTER_VALIDATE_BOOLEAN);
            $estadoCumplimiento = $request->input('estado_cumplimiento', $isCumplido ? 'TOTAL' : 'NO');
            $completePedagogico = array_merge((array)$pedagogico, [
                'estado_cumplimiento' => $estadoCumplimiento
            ]);

            // Create or update the seguimiento record
            $seguimiento = Seguimiento::updateOrCreate(
                [
                    'cronograma_id' => $cronogramaId,
                    'grupo_id' => $grupoId,
                ],
                [
                    'user_id' => Auth::id(),
                    'fecha' => $request->input('fecha') ?: now()->format('Y-m-d'),
                    'cumplido' => true,
                    'tema_cumplido' => $isCumplido,
                    'estado_cumplimiento' => $estadoCumplimiento,
                    'observaciones' => $request->input('observaciones'),
                    'pedagogico' => $completePedagogico,
                    'evidencias' => $evidencias,
                    'integracion_transversal' => $integracionTransversal,
                ]
            );

            \Log::info('Seguimiento saved', ['id' => $seguimiento->id, 'cronograma_id' => $cronogramaId, 'grupo_id' => $grupoId]);

            return response()->json([
                'message' => 'Seguimiento guardado correctamente',
                'seguimiento_id' => $seguimiento->id,
                'pedagogico' => $completePedagogico,
                'cumplido' => true
            ]);

        } catch (\Exception $e) {
            \Log::error('Error in updateSeguimiento: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return response()->json(['error' => $e->getMessage()], 500);
        }
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

        // If tema (singular, via tema_id) is not set, fall back to first of multi-tema relation
        $tema = $cronograma->tema;
        if (!$tema && $cronograma->relationLoaded('temas') && $cronograma->temas->isNotEmpty()) {
            $tema = $cronograma->temas->first();
        }

        if (!$tema) {
            return $defaults;
        }

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

