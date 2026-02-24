<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\PlanificacionPersonal;
use App\Models\Grupo;
use App\Models\User;
use App\Models\Sede;
use App\Models\Carrera;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\InformeSemanal;
use App\Models\Cronograma;
use App\Models\Seguimiento;

class ReporteController extends Controller
{
    public function getWeeklyReportDraft(Request $request) 
    {
        $request->validate([
            'grupo_id' => 'required|exists:grupos,id',
            'fecha_inicio' => 'required|date'
        ]);

        $grupoId = $request->grupo_id;
        $startDate = Carbon::parse($request->fecha_inicio)->startOfWeek();
        $endDate = $startDate->copy()->endOfWeek();
        
        $baseDate = Carbon::create(2026, 2, 9)->startOfWeek();
        $weekNum = $startDate->diffInWeeks($baseDate) + 1;

        $grupo = Grupo::with(['docente', 'asignatura'])->findOrFail($grupoId);

        // 1. Sesiones planificadas para esta semana académica
        $plannedSessions = $grupo->cronogramas()
            ->where('semana_academica', $weekNum)
            ->with(['tema', 'seguimientos'])
            ->get();

        // 2. Seguimientos realizados en este rango de fechas real
        $seguimientos = $grupo->seguimientos()
            ->whereBetween('fecha', [$startDate->toDateString(), $endDate->toDateString()])
            ->get();

        $executionMap = $seguimientos->keyBy('cronograma_id');

        // Pre-cargar cronogramas extras
        $extraCronoIds = $seguimientos->pluck('cronograma_id')->diff($plannedSessions->pluck('id'))->filter();
        $extraCronosMap = Cronograma::whereIn('id', $extraCronoIds)->with('tema')->get()->keyBy('id');

        $criteriaStats = [
            'cumplimiento' => ['totalmente' => 0, 'parcialmente' => 0, 'no_cumplido' => 0],
            'planificacion' => ['estrategias' => 0, 'evaluacion' => 0, 'secuencia' => 0],
            'integracion' => ['investigacion' => 0, 'interaccion_social' => 0, 'internalizacion' => 0],
            'evidencia_tipos' => ['fotos_videos' => 0, 'link_evidencia' => 0, 'archivos_secuencia' => 0],
            'registro_oportuno' => ['en_hora_verde' => 0, 'en_el_dia_amarillo' => 0, 'fuera_rojo' => 0]
        ];

        $detailedSessions = [];
        $relevantCronoIds = $plannedSessions->pluck('id')->merge($seguimientos->pluck('cronograma_id'))->filter()->unique();

        foreach ($relevantCronoIds as $cronoId) {
            $session = $plannedSessions->firstWhere('id', $cronoId) ?? $extraCronosMap->get($cronoId);
            $seguimiento = $executionMap->get($cronoId) ?? ($session ? $session->seguimientos->first() : null);

            $isPlannedThisWeek = $session && $session->semana_academica == $weekNum;
            $isExecutedThisWeek = $seguimiento && Carbon::parse($seguimiento->fecha)->between($startDate, $endDate);

            if (!$isPlannedThisWeek && !$isExecutedThisWeek) continue;

            $pedagogico = $seguimiento ? (is_string($seguimiento->pedagogico) ? json_decode($seguimiento->pedagogico, true) : ($seguimiento->pedagogico ?? [])) : [];
            $integracion = $seguimiento ? (is_string($seguimiento->integracion_transversal) ? json_decode($seguimiento->integracion_transversal, true) : ($seguimiento->integracion_transversal ?? [])) : [];
            $evidencias = $seguimiento ? (is_string($seguimiento->evidencias) ? json_decode($seguimiento->evidencias, true) : ($seguimiento->evidencias ?? [])) : [];

            if ($seguimiento) {
                $estadoStr = $seguimiento->estado_cumplimiento ?? '';
                if (in_array($estadoStr, ['TOTAL', 'TOTALMENTE'])) $criteriaStats['cumplimiento']['totalmente']++;
                elseif (in_array($estadoStr, ['PARCIAL', 'PARCIALMENTE'])) $criteriaStats['cumplimiento']['parcialmente']++;
                else $criteriaStats['cumplimiento']['no_cumplido']++;

                if (!empty($pedagogico['estrategias'])) $criteriaStats['planificacion']['estrategias']++;
                if (!empty($pedagogico['evaluación'] ?? $pedagogico['evaluacion'] ?? [])) $criteriaStats['planificacion']['evaluacion']++;
                if (!empty($pedagogico['secuencia'] ?? $pedagogico['secuencias'] ?? [])) $criteriaStats['planificacion']['secuencia']++;

                if ($integracion['investigacion']['cumplido'] ?? false) $criteriaStats['integracion']['investigacion']++;
                if ($integracion['interaccion']['cumplido'] ?? false) $criteriaStats['integracion']['interaccion_social']++;
                if ($integracion['internalizacion']['cumplido'] ?? false) $criteriaStats['integracion']['internalizacion']++;

                if (!empty($evidencias['aprendizaje_activo'])) $criteriaStats['evidencia_tipos']['fotos_videos']++;
                if (!empty($evidencias['evaluacion_formativa'])) $criteriaStats['evidencia_tipos']['link_evidencia']++;
                if (!empty($evidencias['secuencia_didactica'])) $criteriaStats['evidencia_tipos']['archivos_secuencia']++;

                $cronoDate = $session ? Carbon::parse($session->fecha)->startOfDay() : Carbon::parse($seguimiento->fecha)->startOfDay();
                $createdAt = Carbon::parse($seguimiento->created_at)->startOfDay();
                if ($createdAt->equalTo($cronoDate)) $criteriaStats['registro_oportuno']['en_hora_verde']++;
                else if ($createdAt->diffInDays($cronoDate) <= 1) $criteriaStats['registro_oportuno']['en_el_dia_amarillo']++;
                else $criteriaStats['registro_oportuno']['fuera_rojo']++;
            } else {
                $criteriaStats['cumplimiento']['no_cumplido']++;
                $criteriaStats['registro_oportuno']['fuera_rojo']++;
            }

            $detailedSessions[] = [
                'fecha' => $seguimiento ? substr($seguimiento->fecha, 0, 10) : ($session->fecha ?? 'N/A'),
                'tema' => $session->tema->nombre ?? 'N/A',
                'tipo' => $isExecutedThisWeek ? ($isPlannedThisWeek ? 'Programada' : 'Extra') : 'Pendiente',
                'estado' => $seguimiento ? ($seguimiento->estado_cumplimiento ?? 'PENDIENTE') : 'PENDIENTE'
            ];
        }

        $totalSessions = count($detailedSessions);

        $criterios = [
            'Cumplimiento del Tema' => [
                'cumple' => $totalSessions > 0 && ($criteriaStats['cumplimiento']['totalmente'] + $criteriaStats['cumplimiento']['parcialmente']) === $totalSessions,
                'obs' => "Totalmente: {$criteriaStats['cumplimiento']['totalmente']} | Parcialmente: {$criteriaStats['cumplimiento']['parcialmente']} | No: {$criteriaStats['cumplimiento']['no_cumplido']}",
                'stats' => $criteriaStats['cumplimiento'],
                'type' => 'cumplimiento'
            ],
            'Estrategias Pedagógicas' => [
                'cumple' => $criteriaStats['planificacion']['estrategias'] > 0,
                'obs' => "Detectadas: {$criteriaStats['planificacion']['estrategias']}",
                'stats' => ['count' => $criteriaStats['planificacion']['estrategias']],
                'type' => 'planificacion_item'
            ],
            'Evaluación Formativa' => [
                'cumple' => $criteriaStats['planificacion']['evaluacion'] > 0,
                'obs' => "Detectadas: {$criteriaStats['planificacion']['evaluacion']}",
                'stats' => ['count' => $criteriaStats['planificacion']['evaluacion']],
                'type' => 'planificacion_item'
            ],
            'Secuencia Didáctica' => [
                'cumple' => $criteriaStats['planificacion']['secuencia'] > 0,
                'obs' => "Detectadas: {$criteriaStats['planificacion']['secuencia']}",
                'stats' => ['count' => $criteriaStats['planificacion']['secuencia']],
                'type' => 'planificacion_item'
            ],
            'Integración Transversal' => [
                'cumple' => ($criteriaStats['integracion']['investigacion'] + $criteriaStats['integracion']['interaccion_social'] + $criteriaStats['integracion']['internalizacion']) > 0,
                'obs' => "I+D: {$criteriaStats['integracion']['investigacion']} | Soc: {$criteriaStats['integracion']['interaccion_social']} | Int: {$criteriaStats['integracion']['internalizacion']}",
                'stats' => $criteriaStats['integracion'],
                'type' => 'integracion'
            ],
            'Evidencia de Aprendizaje' => [
                'cumple' => ($criteriaStats['evidencia_tipos']['fotos_videos'] + $criteriaStats['evidencia_tipos']['link_evidencia'] + $criteriaStats['evidencia_tipos']['archivos_secuencia']) > 0,
                'obs' => "Media: {$criteriaStats['evidencia_tipos']['fotos_videos']} | Links: {$criteriaStats['evidencia_tipos']['link_evidencia']} | Docs: {$criteriaStats['evidencia_tipos']['archivos_secuencia']}",
                'stats' => $criteriaStats['evidencia_tipos'],
                'type' => 'evidencias'
            ],
            'Registro Oportuno' => [
                'cumple' => $criteriaStats['registro_oportuno']['en_hora_verde'] > 0,
                'obs' => "Verde: {$criteriaStats['registro_oportuno']['en_hora_verde']} | Rojo: {$criteriaStats['registro_oportuno']['fuera_rojo']}",
                'stats' => $criteriaStats['registro_oportuno'],
                'type' => 'registro_oportuno'
            ]
        ];

        $existingReport = InformeSemanal::where('grupo_id', $grupoId)
            ->whereDate('semana_inicio', $startDate->toDateString())
            ->first();

        if ($existingReport) {
            $existingReport->load(['grupo.docente', 'grupo.asignatura']);
        }

        $yesCount = collect($criterios)->where('cumple', true)->count();
        $totalCriterios = count($criterios);
        $percentage = $totalSessions > 0 ? round(($yesCount / $totalCriterios) * 100) : 0;
        
        $scale = 'VERDE';
        if ($percentage < 70) $scale = 'ROJO';
        elseif ($percentage < 90) $scale = 'AMARILLO';

        return response()->json([
            'exists' => !!$existingReport,
            'report' => $existingReport ? [
                'id' => $existingReport->id,
                'grupo_id' => $existingReport->grupo_id,
                'docente_id' => $existingReport->docente_id,
                'docente_nombre' => $existingReport->grupo->docente->nombre_completo ?? 'N/A',
                'asignatura_nombre' => $existingReport->grupo->asignatura->nombre ?? 'N/A',
                'semana_inicio' => $existingReport->semana_inicio ? $existingReport->semana_inicio->toDateString() : $startDate->toDateString(),
                'semana_fin' => $existingReport->semana_fin ? $existingReport->semana_fin->toDateString() : $endDate->toDateString(),
                'criterios' => $existingReport->criterios,
                'observaciones' => $existingReport->observaciones,
                'escala_alerta' => $existingReport->escala_alerta,
                'cumplimiento_porcentaje' => $existingReport->cumplimiento_porcentaje,
                'sesiones_detalle' => $detailedSessions
            ] : [
                'grupo_id' => $grupo->id,
                'docente_id' => $grupo->docente_id,
                'docente_nombre' => $grupo->docente->nombre_completo ?? 'N/A',
                'asignatura_nombre' => $grupo->asignatura->nombre ?? 'N/A',
                'semana_inicio' => $startDate->toDateString(),
                'semana_fin' => $endDate->toDateString(),
                'criterios' => $criterios,
                'observaciones' => '',
                'escala_alerta' => $scale,
                'cumplimiento_porcentaje' => $percentage,
                'sesiones_detalle' => $detailedSessions
            ]
        ]);
    }

    /**
     * Store or Update the Weekly Report
     */
    public function storeWeeklyReport(Request $request)
    {
        $request->validate([
            'grupo_id' => 'required|exists:grupos,id',
            'semana_inicio' => 'required|date',
            'criterios' => 'required|array',
            'escala_alerta' => 'required|in:VERDE,AMARILLO,ROJO'
        ]);

        $startDate = Carbon::parse($request->semana_inicio)->startOfWeek();
        $endDate = $startDate->copy()->endOfWeek();

        $yesCount = collect($request->criterios)->where('cumple', true)->count();
        $totalCriterios = count($request->criterios);
        $percentage = $totalCriterios > 0 ? round(($yesCount / $totalCriterios) * 100) : 0;

        $report = InformeSemanal::updateOrCreate(
            [
                'grupo_id' => $request->grupo_id,
                'semana_inicio' => $startDate->toDateString()
            ],
            [
                'docente_id' => $request->docente_id,
                'semana_fin' => $endDate->toDateString(),
                'criterios' => $request->criterios,
                'observaciones' => $request->observaciones,
                'escala_alerta' => $request->escala_alerta,
                'cumplimiento_porcentaje' => $percentage,
                'created_by' => auth()->id() // Director
            ]
        );

        return response()->json(['message' => 'Informe guardado correctamente', 'report' => $report]);
    }
    /**
     * Dashboard Metrics for Director/Academic Dashboard
     * Optimized for chart rendering and high-level KPIs
     */
    public function getDashboardMetrics(Request $request)
    {
        try {
            $sedeId = $request->sede_id;
            $carreraId = $request->carrera_id;

            // Base Query
            $query = Asignatura::query();

            if ($sedeId) {
                $query->whereHas('carreras', function ($q) use ($sedeId) {
                    $q->where('asignatura_carrera.sede_id', $sedeId);
                });
            }

            if ($carreraId) {
                $query->whereHas('carreras', function ($q) use ($carreraId) {
                    $q->where('carreras.id', $carreraId);
                });
            }

            $asignaturas = $query->withCount('temas')
                ->with(['grupos' => function ($q) {
                    $q->with(['docente', 'cronogramas' => function ($cq) {
                        $cq->select('id', 'grupo_id', 'fecha', 'cumplido', 'tema_id')
                            ->withCount(['asistencias as total_asistencias', 'asistencias as presentes_asistencias' => function ($aq) {
                                $aq->where('asistio', 1);
                            }]);
                    }]);
                }])
                ->get();

            // Initialize Metrics
            $totalAsignaturas = $asignaturas->count();
            $totalDocentesIds = [];
            $cursosAtrasados = 0;
            $cursosEnRiesgo = 0; // < 50% attendance
            $cursosAlDia = 0;

            // Chart Data Containers
            $avanceDistribution = [
                '0-20%' => 0,
                '21-50%' => 0,
                '51-80%' => 0,
                '81-100%' => 0
            ];

            $asistenciaTrend = []; // [date => [sum, count]]
            $docentePerformance = [];

            foreach ($asignaturas as $asignatura) {
                $totalTemas = $asignatura->temas_count;

                foreach ($asignatura->grupos as $grupo) {
                    if ($grupo->docente_id) {
                        $totalDocentesIds[] = $grupo->docente_id;
                    }

                    $cronogramas = $grupo->cronogramas;
                    $temasAvanzados = $cronogramas->where('cumplido', true)->count(); // Uses cumplido boolean field
                    
                    // Calculate Progress
                    $avance = $totalTemas > 0 ? min(100, round(($temasAvanzados / $totalTemas) * 100)) : 0;

                    // Distribution Bucket
                    if ($avance <= 20) $avanceDistribution['0-20%']++;
                    elseif ($avance <= 50) $avanceDistribution['21-50%']++;
                    elseif ($avance <= 80) $avanceDistribution['51-80%']++;
                    else $avanceDistribution['81-100%']++;

                    // Status
                    if ($avance < 20 && $totalTemas > 0) $cursosAtrasados++;
                    else $cursosAlDia++;

                    // Attendance Logic & Trend
                    $asistenciaSum = 0;
                    $asistenciaCount = 0;

                    foreach ($cronogramas as $crono) {
                        if ($crono->total_asistencias > 0) {
                            $ratio = ($crono->presentes_asistencias / $crono->total_asistencias) * 100;
                            $asistenciaSum += $ratio;
                            $asistenciaCount++;

                            // Trend Data (Group by Week)
                            $weekStart = Carbon::parse($crono->fecha)->startOfWeek()->format('Y-m-d');
                            if (!isset($asistenciaTrend[$weekStart])) {
                                $asistenciaTrend[$weekStart] = ['sum' => 0, 'count' => 0];
                            }
                            $asistenciaTrend[$weekStart]['sum'] += $ratio;
                            $asistenciaTrend[$weekStart]['count']++;
                        }
                    }

                    $avgAsistencia = $asistenciaCount > 0 ? ($asistenciaSum / $asistenciaCount) : 0;
                    if ($avgAsistencia < 50 && $asistenciaCount > 0) $cursosEnRiesgo++;

                    // Docente Performance Tracking
                    if ($grupo->docente) {
                        $docId = $grupo->docente->id;
                        if (!isset($docentePerformance[$docId])) {
                            $docentePerformance[$docId] = [
                                'nombre' => $grupo->docente->nombre_completo,
                                'avances' => [],
                                'asistencias' => []
                            ];
                        }
                        $docentePerformance[$docId]['avances'][] = $avance;
                        $docentePerformance[$docId]['asistencias'][] = $avgAsistencia;
                    }
                }
            }

            // Process Trend Data for Chart
            ksort($asistenciaTrend);
            $asistenciaChartData = [];
            foreach ($asistenciaTrend as $date => $data) {
                $asistenciaChartData[] = [
                    'x' => $date, // Timeline
                    'y' => round($data['sum'] / $data['count'], 1)
                ];
            }

            // Process Docente Ranking (Top 5 & Bottom 5)
            $docenteRanked = [];
            foreach ($docentePerformance as $id => $data) {
                $avgAvance = count($data['avances']) > 0 ? array_sum($data['avances']) / count($data['avances']) : 0;
                $avgAsist = count($data['asistencias']) > 0 ? array_sum($data['asistencias']) / count($data['asistencias']) : 0;
                $docenteRanked[] = [
                    'id' => $id,
                    'nombre' => $data['nombre'],
                    'avance' => round($avgAvance, 1),
                    'asistencia' => round($avgAsist, 1),
                    'score' => ($avgAvance * 0.6) + ($avgAsist * 0.4) // Weighted score
                ];
            }
            usort($docenteRanked, fn($a, $b) => $b['score'] <=> $a['score']);
            
            return response()->json([
                'kpis' => [
                    'total_asignaturas' => $totalAsignaturas,
                    'docentes_activos' => count(array_unique($totalDocentesIds)),
                    'cursos_atrasados' => $cursosAtrasados,
                    'cursos_riesgo_asistencia' => $cursosEnRiesgo,
                    'promedio_general_avance' => $totalAsignaturas > 0 ? 'Calculated Elsewhere' : 0 // Can refine
                ],
                'charts' => [
                    'avance_distribucion' => [
                        'categories' => array_keys($avanceDistribution),
                        'data' => array_values($avanceDistribution)
                    ],
                    'asistencia_trend' => $asistenciaChartData,
                    'top_docentes' => array_slice($docenteRanked, 0, 5),
                    'bottom_docentes' => array_slice($docenteRanked, -5)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }

    public function index(Request $request)
    {
        // 1. Base Query: Get Asignaturas filtradas con relaciones optimizadas
        $query = Asignatura::query()
            ->withCount('temas')
            ->with([
                'grupos' => function ($q) {
                    $q->with(['docente', 'cronogramas' => function ($cq) {
                        $cq->withCount('asistencias')
                            ->with(['asistencias' => function ($aq) {
                                $aq->where('asistio', 1);
                            }]);
                    }]);
                }
            ]);

        // Filter: Sede
        if ($request->filled('sede_id')) {
            $sedeId = $request->sede_id;
            $query->whereHas('carreras', function ($q) use ($sedeId) {
                $q->where('asignatura_carrera.sede_id', $sedeId);
            });
        }

        // Filter: Carrera
        if ($request->filled('carrera_id')) {
            $carreraId = $request->carrera_id;
            $query->whereHas('carreras', function ($q) use ($carreraId) {
                $q->where('carreras.id', $carreraId);
            });
        }

        // Filter: Materia specific
        if ($request->filled('materia_id')) {
            $query->where('id', $request->materia_id);
        }

        $asignaturas = $query->get();

        // 3. Pre-fetch Planning Map to avoid N+1 inside loop
        $allUserIds = $asignaturas->pluck('grupos.*.docente.user_id')->flatten()->filter()->unique();
        $planningMap = [];
        if ($allUserIds->isNotEmpty()) {
            $planningMap = DB::table('planificaciones_personales')
                ->join('temas', 'planificaciones_personales.tema_id', '=', 'temas.id')
                ->join('unidades', 'temas.unidad_id', '=', 'unidades.id')
                ->whereIn('planificaciones_personales.user_id', $allUserIds)
                ->select('planificaciones_personales.user_id', 'unidades.asignatura_id')
                ->distinct()
                ->get()
                ->groupBy('user_id')
                ->map(fn($items) => $items->pluck('asignatura_id')->all())
                ->all();
        }

        // 2. Process Data
        $reporteMaterias = [];
        $allDocentesIds = collect();

        $totalAsistenciaSum = 0;
        $totalAsistenciaCount = 0;
        $totalAvanceSum = 0;
        $totalAvanceCount = 0;
        $totalPendingDocs = 0;

        foreach ($asignaturas as $asignatura) {
            $totalTemas = $asignatura->temas_count;
            $docentesFormatted = [];
            $materiaAvanceSum = 0;
            $materiaAvanceCount = 0;

            foreach ($asignatura->grupos as $grupo) {
                if (!$grupo->docente) continue;

                $allDocentesIds->push($grupo->docente_id);
                $userId = $grupo->docente->user_id;

                // Avance
                $temasAvanzados = $grupo->cronogramas->count();
                $avanceTemas = $totalTemas > 0 ? min(100, round(($temasAvanzados / $totalTemas) * 100)) : 0;

                // Asistencia logic optimized
                $asistenciaPromedio = 0;
                $asistenciasCount = 0;
                $localAsistenciaSum = 0;

                foreach ($grupo->cronogramas as $crono) {
                    $totalEstudiantes = $crono->asistencias_count;
                    if ($totalEstudiantes > 0) {
                        $presentes = $crono->asistencias->count(); // Solo cargamos los que asistieron
                        $localAsistenciaSum += ($presentes / $totalEstudiantes) * 100;
                        $asistenciasCount++;
                    }
                }

                if ($asistenciasCount > 0) {
                    $asistenciaPromedio = round($localAsistenciaSum / $asistenciasCount);
                    $totalAsistenciaSum += $asistenciaPromedio;
                    $totalAsistenciaCount++;
                }

                $totalAvanceSum += $avanceTemas;
                $totalAvanceCount++;
                $materiaAvanceSum += $avanceTemas;
                $materiaAvanceCount++;

                // Docs check from pre-fetched map
                $hasPlanning = isset($planningMap[$userId]) && in_array($asignatura->id, $planningMap[$userId]);

                if (!$hasPlanning) $totalPendingDocs++;

                $estado = $hasPlanning ? ($avanceTemas < 20 && $totalTemas > 0 ? 'Atrasado' : 'Al día') : 'Sin documentación';

                // Initials
                $parts = explode(' ', trim($grupo->docente->nombre_completo));
                $initials = (count($parts) > 0 ? strtoupper(substr($parts[0], 0, 1)) : '') .
                    (count($parts) > 1 ? strtoupper(substr(end($parts), 0, 1)) : '');

                $docentesFormatted[] = [
                    'id' => $grupo->docente->id,
                    'ci' => $grupo->docente->ci,
                    'nombre' => $grupo->docente->nombre_completo,
                    'iniciales' => $initials ?: 'DC',
                    'grupo' => $grupo->nombre,
                    'avanceTemas' => $avanceTemas,
                    'asistencia' => $asistenciaPromedio,
                    'pac' => $hasPlanning,
                    'planClase' => $grupo->cronogramas->filter(fn($c) => !empty($c->contenido_conceptual) || !empty($c->contenido_procedimental))->count() > 0,
                    'estado' => $estado,
                    'clasesImpartidas' => $temasAvanzados,
                    'temasCompletados' => $temasAvanzados,
                    'temasTotales' => $totalTemas,
                    'ultimaClase' => $grupo->cronogramas->max('fecha') ?? 'N/A'
                ];
            }

            if (!empty($docentesFormatted)) {
                $reporteMaterias[] = [
                    'id' => $asignatura->id,
                    'codigo' => $asignatura->codigo,
                    'nombre' => $asignatura->nombre,
                    'semestre' => $asignatura->semestre ?? 1,
                    'promedioGeneral' => $materiaAvanceCount > 0 ? round($materiaAvanceSum / $materiaAvanceCount) : 0,
                    'docentes' => $docentesFormatted
                ];
            }
        }

        $data = [
            'reporteMaterias' => $reporteMaterias,
            'metricas' => [
                'totalDocentes' => $allDocentesIds->unique()->count(),
                'promedioAsistencia' => $totalAsistenciaCount > 0 ? round($totalAsistenciaSum / $totalAsistenciaCount) : 0,
                'cumplimientoTemas' => $totalAvanceCount > 0 ? round($totalAvanceSum / $totalAvanceCount) : 0,
                'documentacionPendiente' => $totalPendingDocs
            ]
        ];

        return response()->json($this->cleanUtf8($data));
    }

    private function cleanUtf8($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'cleanUtf8'], $data);
        }
        if (is_string($data)) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }
        return $data;
    }


    public function getAuditoriaSemanal(Request $request)
    {
        $request->validate([
            'docente_id' => 'required',
            'asignatura_id' => 'required', // We might need group, but let's infer
            'semana_numero' => 'required|integer|min:1|max:20'
        ]);

        // Find the group
        // Assuming 1 active group for doc/asig in this semester/sede context
        // Ideally we filter by 'gestion' too, let's pick latest
        $grupo = Grupo::where('docente_id', $request->docente_id)
            ->where('asignatura_id', $request->asignatura_id)
            ->latest()
            ->first();

        if (!$grupo) {
            return response()->json([
                'error' => 'No se encontró grupo asignado para este docente y materia'
            ], 404);
        }

        // Determine Semester Start Date based on first Cronograma
        // (Or could use explicit AcademicCalendar model if we had one)
        $firstClass = $grupo->cronogramas()->orderBy('fecha', 'asc')->first();

        if (!$firstClass) {
            return response()->json([
                'sesiones' => [],
                'mensaje' => 'No hay cronograma registrado para esta materia'
            ]);
        }

        $startDate = Carbon::parse($firstClass->fecha);

        // Calculate requested week range
        // Week 1 starts at startDate
        $daysToAdd = ($request->semana_numero - 1) * 7;
        $weekStart = $startDate->copy()->addDays($daysToAdd);
        $weekEnd = $weekStart->copy()->addDays(6);

        // Fetch Data
        $sesiones = $grupo->cronogramas()
            ->whereBetween('fecha', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->with(['tema', 'asistencias'])
            ->get();

        $mappedSesiones = $sesiones->map(function ($crono) {
            // Logic for checks
            $asistenciaCount = $crono->asistencias->where('asistio', 1)->count();
            $totalEstudiantes = $crono->asistencias->count();
            $asistenciaOk = ($totalEstudiantes > 0 && ($asistenciaCount / $totalEstudiantes) > 0.5); // Example threshold

            $tema = $crono->tema;

            return [
                'id' => $crono->id,
                'fecha' => Carbon::parse($crono->fecha)->format('Y-m-d'),
                'tema' => $tema ? $tema->titulo : ('Sesión ' . $crono->numero_sesion),
                'unidad' => $tema && $tema->unidad ? 'Unidad ' . $tema->unidad->numero : 'General',

                // Indicators
                'cumplido' => $crono->estado === 'FINALIZADO' && $asistenciaOk, // Logic: marked finished + attendance taken
                'estrategias' => $tema && (!empty($tema->estrategias_metodologicas) || !empty($tema->estrategias_recursos)),
                'evaluacion' => $tema && (!empty($tema->evaluacion_formativa) || !empty($tema->evaluacion_sumativa)),
                'secuencia' => $tema && $tema->secuencias()->exists(),

                // Raw data for debugging if needed
                'estado_crono' => $crono->estado
            ];
        });

        return response()->json([
            'sesiones' => $mappedSesiones,
            'rango' => [
                'inicio' => $weekStart->format('Y-m-d'),
                'fin' => $weekEnd->format('Y-m-d')
            ]
        ]);
    }

    public function generateWeeklyReport(Request $request)
    {
        $request->validate([
            'carrera_id' => 'required',
            'sede_id' => 'required',
            'fecha_inicio' => 'required|date'
        ]);

        $carreraId = $request->carrera_id;
        $sedeId = $request->sede_id;
        
        // Alinear a Lunes-Domingo
        $startDate = Carbon::parse($request->fecha_inicio)->startOfWeek();
        $endDate = $startDate->copy()->endOfWeek();
        
        // Calcular semana académica (Base: 9 de Feb, 2026)
        $baseDate = Carbon::create(2026, 2, 9)->startOfWeek();
        $weekNum = $startDate->diffInWeeks($baseDate) + 1;

        \Log::info("Generando Reporte Semanal. Semana Académica: $weekNum, Rango: {$startDate->toDateString()} - {$endDate->toDateString()}");

        // 1. Obtener Grupos con relaciones filtradas
        $grupos = Grupo::whereHas('asignatura.carreras', function ($q) use ($carreraId, $sedeId) {
            $q->where('carreras.id', $carreraId)
              ->where('asignatura_carrera.sede_id', $sedeId);
        })
        ->with([
            'asignatura',
            'docente',
            'cronogramas' => function ($cq) use ($weekNum) {
                $cq->where('semana_academica', $weekNum);
            }, 
            'seguimientos' => function ($sq) use ($startDate, $endDate) {
                $sq->whereBetween('fecha', [$startDate->toDateString(), $endDate->toDateString()]);
            }
        ])
        ->get();

        // Si el modelo usa 'docente', corregir aquí
        // This block is now redundant as 'docente' is loaded in the 'with' clause above
        // if ($grupos->isNotEmpty() && !$grupos->first()->relationLoaded('docente')) {
        //     $grupos->load('docente');
        // }

        // 2. Pre-cargar todos los cronogramas relevantes para evitar N+1
        // Necesitamos cronogramas de la semana académica O cronogramas vinculados a seguimientos realizados esta semana
        $allCronogramaIds = collect();
        foreach ($grupos as $grupo) {
            $allCronogramaIds = $allCronogramaIds->merge($grupo->cronogramas->pluck('id'));
            $allCronogramaIds = $allCronogramaIds->merge($grupo->seguimientos->pluck('cronograma_id'));
        }
        $allCronogramaIds = $allCronogramaIds->filter()->unique();

        $allCronogramasMap = Cronograma::whereIn('id', $allCronogramaIds)
            ->with('tema')
            ->get()
            ->keyBy('id');

        $reports = [];

        foreach ($grupos as $grupo) {
            $docente = $grupo->docente ?? $grupo->docence;
            if (!$docente || !$grupo->asignatura) continue;

            // Verificar si existe reporte oficial guardado
            $officialReport = InformeSemanal::where('grupo_id', $grupo->id)
                ->whereDate('semana_inicio', $startDate->toDateString())
                ->first();

            if ($officialReport) {
                $reports[] = [
                    'id' => $grupo->id . '-' . $startDate->timestamp,
                    'grupo_id' => $grupo->id,
                    'grupo_nombre' => $grupo->nombre,
                    'asignatura' => $grupo->asignatura->nombre,
                    'docente' => $docente->nombre_completo,
                    'semana_inicio' => $startDate->toDateString(),
                    'criterios' => $officialReport->criterios,
                    'alerta' => $officialReport->escala_alerta,
                    'acciones' => 'Revisado',
                    'estado' => 'Guardado'
                ];
                continue;
            }

            // CÁLCULO DE BORRADOR DINÁMICO
            $executionMap = $grupo->seguimientos->keyBy('cronograma_id');
            $plannedSessions = $grupo->cronogramas; 
            
            // Si no hay nada planificado NI ejecutado
            if ($plannedSessions->isEmpty() && $grupo->seguimientos->isEmpty()) {
                $reports[] = [
                    'id' => $grupo->id . '-' . $startDate->timestamp,
                    'grupo_id' => $grupo->id,
                    'grupo_nombre' => $grupo->nombre,
                    'asignatura' => $grupo->asignatura->nombre,
                    'docente' => $docente->nombre_completo,
                    'semana_inicio' => $startDate->toDateString(),
                    'criterios' => [],
                    'alerta' => 'ROJO',
                    'acciones' => 'Sin Planificación',
                    'estado' => 'Pendiente'
                ];
                continue; 
            }

            $checks = [];
            $alertLevel = 'VERDE';

            // Identificar todos los cronogramas que pintaremos en este grupo (planificados o ejecutados)
            $relevantCronoIds = $plannedSessions->pluck('id')
                ->merge($grupo->seguimientos->pluck('cronograma_id'))
                ->filter()
                ->unique();
            
            foreach ($relevantCronoIds as $cronoId) {
                $session = $allCronogramasMap->get($cronoId);
                $seguimiento = $executionMap->get($cronoId);
                
                if (!$session && !$seguimiento) continue;

                $isPlannedThisWeek = $session && $session->semana_academica == $weekNum;
                $isExecutedThisWeek = $seguimiento && Carbon::parse($seguimiento->fecha)->between($startDate, $endDate);

                // Solo incluimos si pertenece a esta semana (por plan o por ejecución real)
                if (!$isPlannedThisWeek && !$isExecutedThisWeek) continue;

                $pedagogico = $seguimiento ? (is_string($seguimiento->pedagogico) ? json_decode($seguimiento->pedagogico, true) : ($seguimiento->pedagogico ?? [])) : [];
                $evidencias = $seguimiento ? (is_string($seguimiento->evidencias) ? json_decode($seguimiento->evidencias, true) : ($seguimiento->evidencias ?? [])) : [];

                // Criterios de cumplimiento
                $evidenceOk = !empty($evidencias); 
                $contentOk = $session && !empty($session->tema_id);
                $planningOk = !empty($pedagogico) && (isset($pedagogico['estrategias']) && collect($pedagogico['estrategias'])->where('cumplido', true)->count() > 0);
                
                $estadoStr = $seguimiento->estado_cumplimiento ?? '';
                $completedOk = in_array($estadoStr, ['TOTAL', 'TOTALMENTE', 'PARCIAL', 'PARCIALMENTE']);

                $checks[] = [
                    'fecha' => $seguimiento ? (is_string($seguimiento->fecha) ? substr($seguimiento->fecha, 0, 10) : $seguimiento->fecha->format('Y-m-d')) : ($session->fecha ?? 'Pendiente'),
                    'asistencia' => $evidenceOk,
                    'contenido' => $contentOk,
                    'planificacion' => $planningOk,
                    'cumplido' => $completedOk,
                    'tipo' => $isExecutedThisWeek ? ($isPlannedThisWeek ? 'Programada' : 'Extra') : 'Pendiente'
                ];

                if (!$completedOk || !$evidenceOk) {
                    $alertLevel = 'ROJO';
                }
            }

            if (empty($checks)) $alertLevel = 'ROJO';

            $reports[] = [
                'id' => $grupo->id . '-' . $startDate->timestamp,
                'grupo_id' => $grupo->id,
                'grupo_nombre' => $grupo->nombre,
                'asignatura' => $grupo->asignatura->nombre,
                'docente' => $docente->nombre_completo,
                'semana_inicio' => $startDate->toDateString(),
                'criterios' => $checks,
                'alerta' => $alertLevel,
                'acciones' => $alertLevel === 'ROJO' ? 'Verificar' : 'Pendiente',
                'estado' => count($checks) > 0 ? 'Borrador' : 'Pendiente'
            ];
        }

        return response()->json($reports);
    }

    /**
     * Estadísticas para Dashboard de Dirección Académica
     */
    public function direccionStats(Request $request)
    {
        $sedeId = $request->sede_id;

        if (!$sedeId) {
            return response()->json(['error' => 'sede_id es requerido'], 400);
        }

        // 1. Obtener carreras de la sede con conteo de asignaturas
        $carreras = DB::table('carreras')
            ->join('asignatura_carrera', function ($join) use ($sedeId) {
                $join->on('carreras.id', '=', 'asignatura_carrera.carrera_id')
                    ->where('asignatura_carrera.sede_id', '=', $sedeId);
            })
            ->select('carreras.id', 'carreras.nombre')
            ->distinct()
            ->get();

        $carrerasConProgreso = [];
        $totalAsignaturas = 0;
        $docentesIds = collect();
        $asignaturasCompletas = 0;
        $asignaturasEnProgreso = 0;
        $asignaturasAtrasadas = 0;
        $totalProgresoSum = 0;
        $totalProgresoCount = 0;

        $colors = ['#7C3AED', '#14B8A6', '#F97316', '#3B82F6', '#22C55E', '#EF4444', '#8B5CF6', '#EC4899'];

        foreach ($carreras as $index => $carrera) {
            // Asignaturas de esta carrera en esta sede
            $asignaturas = Asignatura::whereHas('carreras', function ($q) use ($carrera, $sedeId) {
                $q->where('carreras.id', $carrera->id)
                    ->where('asignatura_carrera.sede_id', $sedeId);
            })
                ->withCount('temas')
                ->with(['grupos' => function ($q) use ($sedeId) {
                    $q->where('sede_id', $sedeId)->with('cronogramas', 'docente');
                }])
                ->get();

            $carreraAsignaturas = $asignaturas->count();
            $totalAsignaturas += $carreraAsignaturas;

            // Calcular progreso de la carrera
            $carreraProgresoSum = 0;
            $carreraProgresoCount = 0;
            $carreraDocentes = 0;

            foreach ($asignaturas as $asignatura) {
                $temasTotales = $asignatura->temas_count;

                foreach ($asignatura->grupos as $grupo) {
                    if ($grupo->docente) {
                        $docentesIds->push($grupo->docente_id);
                        $carreraDocentes++;
                    }

                    $temasAvanzados = $grupo->cronogramas->count();
                    $progreso = $temasTotales > 0 ? min(100, round(($temasAvanzados / $temasTotales) * 100)) : 0;

                    $carreraProgresoSum += $progreso;
                    $carreraProgresoCount++;
                    $totalProgresoSum += $progreso;
                    $totalProgresoCount++;

                    // Clasificar estado
                    if ($progreso >= 80) {
                        $asignaturasCompletas++;
                    } else if ($progreso >= 30) {
                        $asignaturasEnProgreso++;
                    } else {
                        $asignaturasAtrasadas++;
                    }
                }
            }

            $carreraProgreso = $carreraProgresoCount > 0 ? round($carreraProgresoSum / $carreraProgresoCount) : 0;

            $carrerasConProgreso[] = [
                'id' => $carrera->id,
                'nombre' => $carrera->nombre,
                'asignaturas' => $carreraAsignaturas,
                'docentes' => $carreraDocentes,
                'progreso' => $carreraProgreso,
                'color' => $colors[$index % count($colors)]
            ];
        }

        // Ordenar por progreso descendente
        usort($carrerasConProgreso, fn($a, $b) => $b['progreso'] - $a['progreso']);

        // Conteo de directores de carrera en la sede
        $directoresCarrera = DB::table('directors')
            ->join('users', 'directors.user_id', '=', 'users.id')
            ->where('users.sede_id', $sedeId)
            ->count();

        // Progreso general
        $progresoGeneral = $totalProgresoCount > 0 ? round($totalProgresoSum / $totalProgresoCount) : 0;

        return response()->json([
            'stats' => [
                'totalCarreras' => count($carrerasConProgreso),
                'totalAsignaturas' => $totalAsignaturas,
                'docentesActivos' => $docentesIds->unique()->count(),
                'progresoGeneral' => $progresoGeneral
            ],
            'kpis' => [
                'asignaturasCompletas' => $asignaturasCompletas,
                'asignaturasEnProgreso' => $asignaturasEnProgreso,
                'asignaturasAtrasadas' => $asignaturasAtrasadas,
                'directoresCarrera' => $directoresCarrera
            ],
            'carreras' => $carrerasConProgreso
        ]);
    }

    /**
     * Reporte de avance general con materias comunes
     * Incluye vinculaciones entre materias para seguimiento consolidado
     */
    public function avanceGeneral(Request $request)
    {
        $sedeId = $request->sede_id;
        $carreraId = $request->carrera_id;

        $query = Asignatura::query()
            ->with(['carreras.sede', 'grupos.docente', 'grupos.cronogramas'])
            ->withCount(['temas']);

        // Filtrar por sede
        if ($sedeId) {
            $query->whereHas('carreras', function ($q) use ($sedeId) {
                $q->where('asignatura_carrera.sede_id', $sedeId);
            });
        }

        // Filtrar por carrera
        if ($carreraId) {
            $query->whereHas('carreras', function ($q) use ($carreraId) {
                $q->where('carreras.id', $carreraId);
            });
        }

        $asignaturas = $query->get()->map(function ($asig) {
            $temasTotal = $asig->temas_count;
            
            // Calcular temas avanzados desde cronogramas
            $temasAvanzados = 0;
            $docentePrincipal = null;
            
            foreach ($asig->grupos as $grupo) {
                $temasAvanzados += $grupo->cronogramas->count();
                if (!$docentePrincipal && $grupo->docente) {
                    $docentePrincipal = $grupo->docente->nombre_completo;
                }
            }

            $avance = $temasTotal > 0 ? min(100, round(($temasAvanzados / $temasTotal) * 100)) : 0;

            // Lógica de Materias Comunes (vinculaciones por comun_token)
            $esVinculada = !empty($asig->comun_token);
            $materiaPadre = null;
            
            if ($esVinculada) {
                // Buscar la materia "padre" o relacionada con el mismo token
                $relacionada = Asignatura::where('comun_token', $asig->comun_token)
                    ->where('id', '!=', $asig->id)
                    ->first();
                    
                if ($relacionada) {
                    $materiaPadre = $relacionada->nombre . ' (' . $relacionada->codigo . ')';
                }
            }

            // Obtener último tema avanzado
            $ultimoTema = null;
            $ultimaCronograma = $asig->grupos->flatMap(fn($g) => $g->cronogramas)->sortByDesc('fecha')->first();
            if ($ultimaCronograma && $ultimaCronograma->tema) {
                $ultimoTema = $ultimaCronograma->tema->titulo;
            }

            // Determinar estado
            $estado = 'Atrasado';
            if ($avance >= 80) {
                $estado = 'Completo';
            } elseif ($avance >= 30) {
                $estado = 'En Proceso';
            }

            return [
                'id' => $asig->id,
                'codigo' => $asig->codigo,
                'asignatura' => $asig->nombre,
                'docente' => $docentePrincipal ?? 'Sin asignar',
                'es_vinculada' => $esVinculada,
                'materia_padre' => $materiaPadre,
                'avance_porcentaje' => $avance,
                'temas_avanzados' => $temasAvanzados,
                'temas_total' => $temasTotal,
                'ultimo_tema' => $ultimoTema,
                'estado' => $estado
            ];
        });

        return response()->json($asignaturas);
    }

    /**
     * Matriz de Control Institucional (Nivel 3)
     * Calcula avance real vs planeado y genera semáforos
     */
    public function getMatrizControl(Request $request)
    {
        $sedeId = $request->sede_id;
        $carreraId = $request->carrera_id;

        $query = Asignatura::query()
            ->withCount('temas')
            ->with(['grupos.cronogramas', 'carreras']);

        // Filtrar por sede
        if ($sedeId) {
            $query->whereHas('carreras', function ($q) use ($sedeId) {
                $q->where('asignatura_carrera.sede_id', $sedeId);
            });
        }

        // Filtrar por carrera
        if ($carreraId) {
            $query->whereHas('carreras', function ($q) use ($carreraId) {
                $q->where('carreras.id', $carreraId);
            });
        }

        $asignaturas = $query->get();

        // Calcular semana actual del semestre (asumiendo inicio en enero)
        $inicioSemestre = Carbon::create(2026, 1, 6); // Primer lunes de enero 2026
        $semanasTranscurridas = max(1, Carbon::now()->diffInWeeks($inicioSemestre));
        $semanasTotales = 20; // 20 semanas por semestre

        $matriz = $asignaturas->map(function ($asig) use ($semanasTranscurridas, $semanasTotales) {
            $totalTemas = $asig->temas_count;
            
            // Avance real: temas avanzados / total temas
            $temasAvanzados = $asig->grupos->sum(fn($g) => $g->cronogramas->count());
            $avanceReal = $totalTemas > 0 ? min(100, round(($temasAvanzados / $totalTemas) * 100)) : 0;
            
            // Avance planeado: semanas transcurridas / semanas totales
            $avancePlaneado = min(100, round(($semanasTranscurridas / $semanasTotales) * 100));
            
            // Calcular diferencia y semáforo
            $diferencia = $avanceReal - $avancePlaneado;
            
            if ($diferencia >= -10) {
                $semaforo = 'positive';
                $alertaLabel = 'Normal';
                $acciones = 'Ninguna';
            } elseif ($diferencia >= -25) {
                $semaforo = 'warning';
                $alertaLabel = 'Atención';
                $acciones = 'Seguimiento reforzado';
            } else {
                $semaforo = 'negative';
                $alertaLabel = 'Crítico';
                $acciones = 'Requiere intervención inmediata';
            }

            // Obtener carrera principal
            $carrera = $asig->carreras->first();

            return [
                'id' => $asig->id,
                'asignatura' => $asig->nombre,
                'codigo' => $asig->codigo,
                'carrera' => $carrera ? $carrera->nombre : 'N/A',
                'avancePlaneado' => $avancePlaneado . '%',
                'avanceReal' => $avanceReal . '%',
                'diferencia' => ($diferencia >= 0 ? '+' : '') . $diferencia . '%',
                'semaforo' => $semaforo,
                'alertaLabel' => $alertaLabel,
                'acciones' => $acciones,
                'temasTotal' => $totalTemas,
                'temasAvanzados' => $temasAvanzados
            ];
        });

        // Ordenar por semáforo (críticos primero)
        $ordenSemaforo = ['negative' => 0, 'warning' => 1, 'positive' => 2];
        $matriz = $matriz->sortBy(fn($item) => $ordenSemaforo[$item['semaforo']] ?? 3)->values();

        return response()->json($matriz);
    }

    /**
     * Auditoría Semanal 25% (Nivel 3)
     * Selección aleatoria de asignaturas para auditoría
     */
    public function getAuditoria25(Request $request)
    {
        $sedeId = $request->sede_id;
        $carreraId = $request->carrera_id;
        $semana = $request->semana ?? Carbon::now()->weekOfYear;

        $query = Asignatura::query()
            ->with(['grupos.docente', 'grupos.cronogramas' => function ($q) {
                $q->orderBy('fecha', 'desc')->limit(5);
            }]);

        if ($sedeId) {
            $query->whereHas('carreras', fn($q) => $q->where('asignatura_carrera.sede_id', $sedeId));
        }
        if ($carreraId) {
            $query->whereHas('carreras', fn($q) => $q->where('carreras.id', $carreraId));
        }

        // Obtener 25% aleatorio (usando semana como seed para consistencia)
        $asignaturas = $query->get();
        $total = $asignaturas->count();
        $cantidad25 = max(1, ceil($total * 0.25));

        // Seed basado en semana para reproducibilidad
        srand($semana * 1000 + ($carreraId ?? 0));
        $seleccionadas = $asignaturas->shuffle()->take($cantidad25);

        $auditoria = $seleccionadas->map(function ($asig) use ($semana) {
            $grupo = $asig->grupos->first();
            $docente = $grupo?->docente;
            $ultimosCronogramas = $grupo?->cronogramas ?? collect();

            // Verificar criterios de cumplimiento
            $inicioPuntual = $ultimosCronogramas->where('estado', 'FINALIZADO')->count() > 0;
            $secuencialidad = $ultimosCronogramas->whereNotNull('tema_id')->count() >= 2;
            $metodologias = $ultimosCronogramas->filter(fn($c) => 
                !empty($c->pedagogico) || !empty($c->contenido_conceptual)
            )->count() > 0;

            return [
                'id' => $asig->id,
                'asignatura' => $asig->nombre,
                'docente' => $docente ? $docente->nombre_completo : 'Sin asignar',
                'semana' => 'S-' . $semana,
                'inicioPuntual' => $inicioPuntual,
                'secuencialidad' => $secuencialidad,
                'metodologias' => $metodologias,
                'cumplimientoTotal' => ($inicioPuntual && $secuencialidad && $metodologias)
            ];
        });

        return response()->json([
            'auditorias' => $auditoria->values(),
            'meta' => [
                'semana' => $semana,
                'totalAsignaturas' => $total,
                'auditadas' => $cantidad25,
                'porcentaje' => 25
            ]
        ]);
    }

    // ============================================
    // NIVEL 2: REPORTES DIRECTOR DE CARRERA
    // ============================================

    /**
     * Docentes sin ningún avance en documentación (0%)
     */
    public function docentesSinAvance(Request $request)
    {
        $sedeId = $request->sede_id;
        $carreraId = $request->carrera_id;

        $docentes = $this->getDocentesConAvance($sedeId, $carreraId);
        
        $sinAvance = $docentes->filter(fn($d) => $d['avance'] == 0);

        return response()->json([
            'docentes' => $sinAvance->values(),
            'meta' => [
                'total' => $docentes->count(),
                'sinAvance' => $sinAvance->count(),
                'porcentaje' => $docentes->count() > 0 
                    ? round(($sinAvance->count() / $docentes->count()) * 100, 1) 
                    : 0
            ]
        ]);
    }

    /**
     * Docentes con avance crítico (<50%)
     */
    public function docentesCriticos(Request $request)
    {
        $sedeId = $request->sede_id;
        $carreraId = $request->carrera_id;
        $umbral = $request->umbral ?? 50;

        $docentes = $this->getDocentesConAvance($sedeId, $carreraId);
        
        $criticos = $docentes->filter(fn($d) => $d['avance'] < $umbral && $d['avance'] > 0);

        return response()->json([
            'docentes' => $criticos->values(),
            'umbral' => $umbral,
            'meta' => [
                'total' => $docentes->count(),
                'criticos' => $criticos->count(),
                'porcentaje' => $docentes->count() > 0 
                    ? round(($criticos->count() / $docentes->count()) * 100, 1) 
                    : 0
            ]
        ]);
    }

    /**
     * Ranking de docentes por porcentaje de avance
     */
    public function rankingDocentes(Request $request)
    {
        $sedeId = $request->sede_id;
        $carreraId = $request->carrera_id;
        $orden = $request->orden ?? 'desc'; // desc = mejores primero, asc = peores primero

        $docentes = $this->getDocentesConAvance($sedeId, $carreraId);
        
        $ranking = $orden === 'asc' 
            ? $docentes->sortBy('avance')->values()
            : $docentes->sortByDesc('avance')->values();

        // Añadir posición en ranking
        $ranking = $ranking->map(function($d, $index) {
            $d['posicion'] = $index + 1;
            return $d;
        });

        return response()->json([
            'ranking' => $ranking,
            'promedioGeneral' => $docentes->count() > 0 
                ? round($docentes->avg('avance'), 1) 
                : 0
        ]);
    }

    /**
     * Asignaturas sin ningún cronograma registrado
     */
    public function asignaturasSinCronograma(Request $request)
    {
        $sedeId = $request->sede_id;
        $carreraId = $request->carrera_id;

        $query = Asignatura::query()
            ->with(['carreras', 'grupos.docente'])
            ->withCount(['grupos as cronogramas_count' => function($q) {
                $q->join('cronogramas', 'grupos.id', '=', 'cronogramas.grupo_id');
            }]);

        if ($sedeId) {
            $query->whereHas('carreras', fn($q) => $q->where('asignatura_carrera.sede_id', $sedeId));
        }
        if ($carreraId) {
            $query->whereHas('carreras', fn($q) => $q->where('carreras.id', $carreraId));
        }

        $asignaturas = $query->get();
        
        $sinCronograma = $asignaturas->filter(fn($a) => $a->cronogramas_count == 0)
            ->map(function($a) {
                $grupo = $a->grupos->first();
                return [
                    'id' => $a->id,
                    'codigo' => $a->codigo,
                    'nombre' => $a->nombre,
                    'carrera' => $a->carreras->first()?->nombre ?? 'N/A',
                    'docente' => $grupo?->docente?->nombre_completo ?? 'Sin asignar',
                    'grupos' => $a->grupos->count()
                ];
            });

        return response()->json([
            'asignaturas' => $sinCronograma->values(),
            'meta' => [
                'total' => $asignaturas->count(),
                'sinCronograma' => $sinCronograma->count(),
                'porcentaje' => $asignaturas->count() > 0 
                    ? round(($sinCronograma->count() / $asignaturas->count()) * 100, 1) 
                    : 0
            ]
        ]);
    }

    // ============================================
    // NIVEL 3: REPORTES DIRECCIÓN ACADÉMICA
    // ============================================

    /**
     * Carreras con avance crítico (<50%)
     */
    public function carrerasCriticas(Request $request)
    {
        $sedeId = $request->sede_id;
        $umbral = $request->umbral ?? 50;

        $carreras = $this->getCarrerasConAvance($sedeId);
        
        $criticas = $carreras->filter(fn($c) => $c['avance'] < $umbral);

        return response()->json([
            'carreras' => $criticas->values(),
            'umbral' => $umbral,
            'meta' => [
                'total' => $carreras->count(),
                'criticas' => $criticas->count(),
                'porcentaje' => $carreras->count() > 0 
                    ? round(($criticas->count() / $carreras->count()) * 100, 1) 
                    : 0
            ]
        ]);
    }

    /**
     * Ranking de carreras por avance
     */
    public function rankingCarreras(Request $request)
    {
        $sedeId = $request->sede_id;
        $orden = $request->orden ?? 'desc';

        $carreras = $this->getCarrerasConAvance($sedeId);
        
        $ranking = $orden === 'asc' 
            ? $carreras->sortBy('avance')->values()
            : $carreras->sortByDesc('avance')->values();

        $ranking = $ranking->map(function($c, $index) {
            $c['posicion'] = $index + 1;
            return $c;
        });

        return response()->json([
            'ranking' => $ranking,
            'promedioGeneral' => $carreras->count() > 0 
                ? round($carreras->avg('avance'), 1) 
                : 0
        ]);
    }

    /**
     * Resumen ejecutivo de la sede
     */
    public function resumenEjecutivoSede(Request $request)
    {
        $sedeId = $request->sede_id;

        if (!$sedeId) {
            return response()->json(['error' => 'sede_id es requerido'], 400);
        }

        $carreras = $this->getCarrerasConAvance($sedeId);
        $docentes = $this->getDocentesConAvance($sedeId, null);

        // Estadísticas de carreras
        $carrerasCompletas = $carreras->filter(fn($c) => $c['avance'] >= 80)->count();
        $carrerasEnProgreso = $carreras->filter(fn($c) => $c['avance'] >= 50 && $c['avance'] < 80)->count();
        $carrerasCriticas = $carreras->filter(fn($c) => $c['avance'] < 50)->count();

        // Estadísticas de docentes
        $docentesCompletos = $docentes->filter(fn($d) => $d['avance'] >= 80)->count();
        $docentesCriticos = $docentes->filter(fn($d) => $d['avance'] < 50)->count();
        $docentesSinAvance = $docentes->filter(fn($d) => $d['avance'] == 0)->count();

        // Top 5 mejores y peores carreras
        $mejoresCarreras = $carreras->sortByDesc('avance')->take(5)->values();
        $peoresCarreras = $carreras->sortBy('avance')->take(5)->values();

        return response()->json([
            'resumen' => [
                'progresoGeneral' => $carreras->count() > 0 ? round($carreras->avg('avance'), 1) : 0,
                'totalCarreras' => $carreras->count(),
                'totalDocentes' => $docentes->count()
            ],
            'estadisticasCarreras' => [
                'completas' => $carrerasCompletas,
                'enProgreso' => $carrerasEnProgreso,
                'criticas' => $carrerasCriticas
            ],
            'estadisticasDocentes' => [
                'completos' => $docentesCompletos,
                'criticos' => $docentesCriticos,
                'sinAvance' => $docentesSinAvance
            ],
            'mejoresCarreras' => $mejoresCarreras,
            'peoresCarreras' => $peoresCarreras
        ]);
    }

    // ============================================
    // NIVEL 4: REPORTES VICERRECTOR
    // ============================================

    /**
     * Sedes con avance crítico (solo Vicerrector Nacional)
     */
    public function sedesCriticas(Request $request)
    {
        $umbral = $request->umbral ?? 50;

        $sedes = $this->getSedesConAvance();
        
        $criticas = $sedes->filter(fn($s) => $s['avance'] < $umbral);

        return response()->json([
            'sedes' => $criticas->values(),
            'umbral' => $umbral,
            'meta' => [
                'total' => $sedes->count(),
                'criticas' => $criticas->count()
            ]
        ]);
    }

    /**
     * Ranking de sedes por avance
     */
    public function rankingSedes(Request $request)
    {
        $orden = $request->orden ?? 'desc';

        $sedes = $this->getSedesConAvance();
        
        $ranking = $orden === 'asc' 
            ? $sedes->sortBy('avance')->values()
            : $sedes->sortByDesc('avance')->values();

        $ranking = $ranking->map(function($s, $index) {
            $s['posicion'] = $index + 1;
            return $s;
        });

        return response()->json([
            'ranking' => $ranking,
            'promedioNacional' => $sedes->count() > 0 
                ? round($sedes->avg('avance'), 1) 
                : 0
        ]);
    }

    /**
     * Todas las alertas rojas (críticas) consolidadas
     */
    public function alertasRojas(Request $request)
    {
        $sedeId = $request->sede_id; // null = todas las sedes

        $alertas = collect();

        // 1. Carreras críticas
        $carreras = $this->getCarrerasConAvance($sedeId);
        $carrerasCriticas = $carreras->filter(fn($c) => $c['avance'] < 50)
            ->map(fn($c) => [
                'tipo' => 'carrera',
                'icono' => 'school',
                'color' => 'negative',
                'titulo' => $c['nombre'],
                'subtitulo' => 'Avance: ' . $c['avance'] . '%',
                'sede' => $c['sede'] ?? 'N/A',
                'avance' => $c['avance']
            ]);
        $alertas = $alertas->merge($carrerasCriticas);

        // 2. Docentes sin avance
        $docentes = $this->getDocentesConAvance($sedeId, null);
        $docentesCriticos = $docentes->filter(fn($d) => $d['avance'] == 0)
            ->map(fn($d) => [
                'tipo' => 'docente',
                'icono' => 'person',
                'color' => 'negative',
                'titulo' => $d['nombre'],
                'subtitulo' => 'Sin documentación registrada',
                'carrera' => $d['carrera'] ?? 'N/A',
                'avance' => 0
            ]);
        $alertas = $alertas->merge($docentesCriticos);

        // 3. Ordenar por avance (más críticos primero)
        $alertas = $alertas->sortBy('avance')->values();

        return response()->json([
            'alertas' => $alertas,
            'meta' => [
                'totalAlertas' => $alertas->count(),
                'carrerasCriticas' => $carrerasCriticas->count(),
                'docentesCriticos' => $docentesCriticos->count()
            ]
        ]);
    }

    // ============================================
    // MÉTODOS AUXILIARES PRIVADOS
    // ============================================

    /**
     * Obtener docentes con su porcentaje de avance
     */
    private function getDocentesConAvance($sedeId, $carreraId)
    {
        $query = User::query()
            ->whereHas('docente')
            ->with(['docente.grupos.asignatura.temas', 'docente.grupos.cronogramas']);

        if ($sedeId) {
            $query->whereHas('docente', fn($q) => $q->where('sede_id', $sedeId));
        }

        if ($carreraId) {
            $query->whereHas('docente.grupos.asignatura.carreras', fn($q) => 
                $q->where('carreras.id', $carreraId)
            );
        }

        return $query->get()->map(function($user) {
            $docente = $user->docente;
            $totalTemas = 0;
            $temasAvanzados = 0;
            $asignaturas = collect();

            foreach ($docente->grupos as $grupo) {
                if ($grupo->asignatura) {
                    $temas = $grupo->asignatura->temas->count();
                    $avanzados = $grupo->cronogramas->count();
                    $totalTemas += $temas;
                    $temasAvanzados += min($avanzados, $temas);
                    
                    if (!$asignaturas->contains('id', $grupo->asignatura->id)) {
                        $asignaturas->push([
                            'id' => $grupo->asignatura->id,
                            'nombre' => $grupo->asignatura->nombre
                        ]);
                    }
                }
            }

            $avance = $totalTemas > 0 ? round(($temasAvanzados / $totalTemas) * 100) : 0;
            $carrera = $docente->grupos->first()?->asignatura?->carreras?->first();

            return [
                'id' => $docente->id,
                'userId' => $user->id,
                'nombre' => $user->nombre . ' ' . $user->apellido,
                'email' => $user->email,
                'carrera' => $carrera?->nombre ?? 'N/A',
                'asignaturas' => $asignaturas->count(),
                'temasTotal' => $totalTemas,
                'temasAvanzados' => $temasAvanzados,
                'avance' => $avance,
                'semaforo' => $avance >= 80 ? 'positive' : ($avance >= 50 ? 'warning' : 'negative')
            ];
        });
    }

    /**
     * Obtener carreras con su porcentaje de avance
     */
    private function getCarrerasConAvance($sedeId)
    {
        $query = Carrera::query()
            ->with(['asignaturas.temas', 'asignaturas.grupos.cronogramas', 'sede']);

        if ($sedeId) {
            $query->where(function($q) use ($sedeId) {
                $q->where('sede_id', $sedeId)
                  ->orWhereHas('sedes', fn($sub) => $sub->where('sede_id', $sedeId));
            });
        }

        return $query->get()->map(function($carrera) {
            $totalTemas = 0;
            $temasAvanzados = 0;

            foreach ($carrera->asignaturas as $asig) {
                $temas = $asig->temas->count();
                $avanzados = $asig->grupos->sum(fn($g) => $g->cronogramas->count());
                $totalTemas += $temas;
                $temasAvanzados += min($avanzados, $temas);
            }

            $avance = $totalTemas > 0 ? round(($temasAvanzados / $totalTemas) * 100) : 0;

            return [
                'id' => $carrera->id,
                'nombre' => $carrera->nombre,
                'sede' => $carrera->sede?->nombre ?? 'N/A',
                'asignaturas' => $carrera->asignaturas->count(),
                'temasTotal' => $totalTemas,
                'temasAvanzados' => $temasAvanzados,
                'avance' => $avance,
                'semaforo' => $avance >= 80 ? 'positive' : ($avance >= 50 ? 'warning' : 'negative')
            ];
        });
    }

    /**
     * Obtener sedes con su porcentaje de avance
     */
    private function getSedesConAvance()
    {
        $sedes = Sede::with(['carreras.asignaturas.temas', 'carreras.asignaturas.grupos.cronogramas'])->get();

        return $sedes->map(function($sede) {
            $totalTemas = 0;
            $temasAvanzados = 0;

            foreach ($sede->carreras as $carrera) {
                foreach ($carrera->asignaturas as $asig) {
                    $temas = $asig->temas->count();
                    $avanzados = $asig->grupos->sum(fn($g) => $g->cronogramas->count());
                    $totalTemas += $temas;
                    $temasAvanzados += min($avanzados, $temas);
                }
            }








            $avance = $totalTemas > 0 ? round(($temasAvanzados / $totalTemas) * 100) : 0;

            return [
                'id' => $sede->id,
                'nombre' => $sede->nombre,
                'carreras' => $sede->carreras->count(),
                'temasTotal' => $totalTemas,
                'temasAvanzados' => $temasAvanzados,
                'avance' => $avance,
                'semaforo' => $avance >= 80 ? 'positive' : ($avance >= 50 ? 'warning' : 'negative')
            ];
        });
    }

    public function exportWeeklyReportHtml(Request $request)
    {
        $response = $this->getWeeklyReportDraft($request);
        $data = $response->getData(true);
        
        if (!isset($data['report'])) {
            return "Error: No se pudo generar el reporte.";
        }

        return view('reports.weekly_official', ['report' => $data['report']]);
    }
}
