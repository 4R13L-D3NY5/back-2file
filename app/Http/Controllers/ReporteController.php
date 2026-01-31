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
            $planningMap = DB::table('planificacion_personal')
                ->join('temas', 'planificacion_personal.tema_id', '=', 'temas.id')
                ->join('unidades', 'temas.unidad_id', '=', 'unidades.id')
                ->whereIn('planificacion_personal.user_id', $allUserIds)
                ->select('planificacion_personal.user_id', 'unidades.asignatura_id')
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
                    'nombre' => $grupo->docente->nombre_completo,
                    'iniciales' => $initials ?: 'DC',
                    'grupo' => $grupo->nombre,
                    'avanceTemas' => $avanceTemas,
                    'asistencia' => $asistenciaPromedio,
                    'pac' => $hasPlanning,
                    'planClase' => $avanceTemas > 0,
                    'syllabus' => $hasPlanning,
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

        return response()->json([
            'reporteMaterias' => $reporteMaterias,
            'metricas' => [
                'totalDocentes' => $allDocentesIds->unique()->count(),
                'promedioAsistencia' => $totalAsistenciaCount > 0 ? round($totalAsistenciaSum / $totalAsistenciaCount) : 0,
                'cumplimientoTemas' => $totalAvanceCount > 0 ? round($totalAvanceSum / $totalAvanceCount) : 0,
                'documentacionPendiente' => $totalPendingDocs
            ]
        ]);
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
}
