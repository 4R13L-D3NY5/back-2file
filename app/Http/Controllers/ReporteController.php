<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\PlanificacionPersonal;
use App\Models\Grupo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReporteController extends Controller
{
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
        $startDate = Carbon::parse($request->fecha_inicio);
        $endDate = $startDate->copy()->addDays(6);

        // Fetch subjects linked to career/sede
        $asignaturas = Asignatura::whereHas('carreras', function ($q) use ($carreraId, $sedeId) {
            $q->where('carreras.id', $carreraId)
                ->where('asignatura_carrera.sede_id', $sedeId);
        })->with(['grupos.docente', 'grupos.cronogramas' => function ($q) use ($startDate, $endDate) {
            $q->whereBetween('fecha', [$startDate->toDateString(), $endDate->toDateString()])
                ->withCount(['asistencias' => function ($aq) {
                    $aq->where('asistio', true);
                }]);
        }])->get();

        $reports = [];

        foreach ($asignaturas as $asignatura) {
            foreach ($asignatura->grupos as $grupo) {
                if (!$grupo->docente) continue;

                $sessions = $grupo->cronogramas; // Filtered by date in eager load
                if ($sessions->isEmpty()) continue; // Skip if no classes scheduled this week

                $checks = [];
                $alertLevel = 'VERDE';

                foreach ($sessions as $session) {
                    // 1. Asistencia Check (> 50% just as placeholder threshold)
                    // We need total students count, assuming we can get it from enrollment or count total asistencias rows
                    // For now, let's use a simple heuristic if we don't have total enrollment easily accessible here
                    // Assuming cronograma->asistencias_count might be total attendance records created (present + absent)
                    $totalRecords = $session->asistencias()->count();
                    $present = $session->asistencias_count; // From withCount 'asistencias' where asistio=true

                    $attendanceOk = $totalRecords > 0 ? ($present / $totalRecords) >= 0.5 : false;

                    // 2. Content Check (If theme is assigned)
                    $contentOk = !empty($session->tema_id);

                    // 3. Resources/Strategies (Check if pedagogico field is filled)
                    // pedagogico is cast to array in model
                    $pedagogico = $session->pedagogico;
                    $planningOk = !empty($pedagogico) && !empty($pedagogico['estrategias']);

                    // 4. Completed Check
                    $completedOk = $session->cumplido;

                    $checks[] = [
                        'fecha' => $session->fecha,
                        'asistencia' => $attendanceOk,
                        'contenido' => $contentOk,
                        'planificacion' => $planningOk,
                        'cumplido' => $completedOk
                    ];

                    if (!$completedOk || !$attendanceOk) {
                        $alertLevel = 'ROJO';
                    } else if (!$planningOk) {
                        $alertLevel = ($alertLevel === 'ROJO') ? 'ROJO' : 'AMARILLO';
                    }
                }

                $reports[] = [
                    'id' => $grupo->id . '-' . $startDate->timestamp,
                    'asignatura' => $asignatura->nombre,
                    'carrera' => $asignatura->carreras->where('id', $carreraId)->first()->nombre ?? 'N/A',
                    'docente' => $grupo->docente->nombre_completo,
                    'semana_inicio' => $startDate->toDateString(),
                    'criterios' => $checks,
                    'alerta' => $alertLevel,
                    'acciones' => $alertLevel === 'ROJO' ? 'Verificar' : 'Ninguna'
                ];
            }
        }

        return response()->json($reports);
    }

    /**
     * Estadísticas para Dashboard de Dirección Académica
     * Retorna métricas consolidadas de la sede
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
}
