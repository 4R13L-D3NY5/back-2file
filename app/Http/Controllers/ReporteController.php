<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\PlanificacionPersonal;
use Illuminate\Http\Request;

class ReporteController extends Controller
{
    public function index(Request $request)
    {
        // 1. Base Query: Get Asignaturas filtrades
        $query = Asignatura::query()->with([
            'grupos.docente',
            'grupos.cronogramas.asistencias',
            'unidades.temas' // Para contar total temas
        ]);

        // Filter: Sede (via pivot carreras_sedes or easier logic if needed)
        // For simplicity, we assume we want subjects linked to careers available in the sede.
        if ($request->has('sede_id') && $request->sede_id) {
            $sedeId = $request->sede_id;
            $query->whereHas('carreras.sedes', function ($q) use ($sedeId) {
                $q->where('sedes.id', $sedeId);
            });
        }

        // Filter: Carrera
        if ($request->has('carrera_id') && $request->carrera_id) {
            $carreraId = $request->carrera_id;
            $query->whereHas('carreras', function ($q) use ($carreraId) {
                $q->where('carreras.id', $carreraId);
            });
        }

        // Filter: Materia specific
        if ($request->has('materia_id') && $request->materia_id) {
            $query->where('id', $request->materia_id);
        }

        $asignaturas = $query->get();

        // 2. Process Data
        $reporteMaterias = [];
        $allDocentesIds = collect();

        // Metrics Accumulators
        $totalAsistenciaSum = 0;
        $totalAsistenciaCount = 0;
        $totalAvanceSum = 0;
        $totalAvanceCount = 0;
        $totalPendingDocs = 0;

        foreach ($asignaturas as $asignatura) {

            $totalTemas = $asignatura->temas_count ?? $asignatura->unidades->sum(function ($u) {
                return $u->temas->count();
            });

            $docentesFormatted = [];
            $materiaAvanceSum = 0;
            $materiaAvanceCount = 0;

            foreach ($asignatura->grupos as $grupo) {
                if (!$grupo->docente) continue;

                $allDocentesIds->push($grupo->docente_id);

                // Calculations (Same logic as DocenteController)
                // Avance
                $temasAvanzados = $grupo->cronogramas->count();
                $avanceTemas = 0;
                if ($totalTemas > 0) {
                    $avanceTemas = min(100, round(($temasAvanzados / $totalTemas) * 100));
                }

                // Asistencia
                $asistenciaPromedio = 0;
                $asistenciasCount = 0;
                $localAsistenciaSum = 0;
                foreach ($grupo->cronogramas as $crono) {
                    if ($crono->asistencias->count() > 0) {
                        $presentes = $crono->asistencias->where('asistio', 1)->count();
                        $total = $crono->asistencias->count();
                        $localAsistenciaSum += ($presentes / $total) * 100;
                        $asistenciasCount++;
                    }
                }
                if ($asistenciasCount > 0) {
                    $asistenciaPromedio = round($localAsistenciaSum / $asistenciasCount);
                }

                // Global Stats Accumulation
                if ($asistenciasCount > 0) {
                    $totalAsistenciaSum += $asistenciaPromedio;
                    $totalAsistenciaCount++;
                }
                $totalAvanceSum += $avanceTemas;
                $totalAvanceCount++;
                $materiaAvanceSum += $avanceTemas;
                $materiaAvanceCount++;

                // Docs
                $hasPlanning = PlanificacionPersonal::where('user_id', $grupo->docente->user_id ?? 0) // Docente model -> User relation needed or store user_id in docente
                    ->whereHas('tema.unidad.asignatura', function ($q) use ($asignatura) {
                        $q->where('id', $asignatura->id);
                    })->exists();

                // Try fallback if user_id not on docente table directly (it IS there based on model)
                if (!$hasPlanning && $grupo->docente->id) {
                    // Try by looking up via Docente -> User
                    $user = $grupo->docente; // Docente model has no planning relation directly usually.
                    // But PlanificacionPersonal is linked to User.
                    // Let's rely on loose check or specific query.
                    // The logic in DocenteController used `user_id`.
                }

                $pac = $hasPlanning;
                $syllabus = $hasPlanning;
                $planClase = $avanceTemas > 0;

                $estado = 'Al día';
                if ($avanceTemas < 20 && $totalTemas > 0) $estado = 'Atrasado';
                if (!$hasPlanning) {
                    $estado = 'Sin documentación';
                    $totalPendingDocs++;
                } else if ($estado === 'Atrasado') {
                    // Maybe count as pending attention?
                }

                // Initials
                $parts = explode(' ', trim($grupo->docente->nombre_completo));
                $initials = '';
                if (count($parts) > 0) {
                    $initials .= strtoupper(substr($parts[0], 0, 1));
                    if (count($parts) > 1) $initials .= strtoupper(substr(end($parts), 0, 1));
                }

                $docentesFormatted[] = [
                    'id' => $grupo->docente->id,
                    'nombre' => $grupo->docente->nombre_completo,
                    'iniciales' => $initials ?: 'DC',
                    'grupo' => $grupo->nombre,
                    'avanceTemas' => $avanceTemas,
                    'asistencia' => $asistenciaPromedio,
                    'pac' => $pac,
                    'planClase' => $planClase,
                    'syllabus' => $syllabus,
                    'estado' => $estado,
                    // Extra fields for other tabs
                    'clasesImpartidas' => $temasAvanzados,
                    'estudiantesInscritos' => 0, // TODO: Count matriculas
                    'temasCompletados' => $temasAvanzados,
                    'temasTotales' => $totalTemas,
                    'ultimaClase' => $grupo->cronogramas->max('fecha') ?? 'N/A'
                ];
            }

            if (count($docentesFormatted) > 0) {
                $promedioGeneral = $materiaAvanceCount > 0 ? round($materiaAvanceSum / $materiaAvanceCount) : 0;

                $reporteMaterias[] = [
                    'id' => $asignatura->id,
                    'codigo' => $asignatura->codigo,
                    'nombre' => $asignatura->nombre,
                    // 'semestre' => $asignatura->semestre, // If column exists, otherwise infer or ignore
                    'semestre' => $asignatura->semestre ?? 1,
                    'promedioGeneral' => $promedioGeneral,
                    'docentes' => $docentesFormatted
                ];
            }
        }

        // Metrics Final Calc
        $metricas = [
            'totalDocentes' => $allDocentesIds->unique()->count(),
            'promedioAsistencia' => $totalAsistenciaCount > 0 ? round($totalAsistenciaSum / $totalAsistenciaCount) : 0,
            'cumplimientoTemas' => $totalAvanceCount > 0 ? round($totalAvanceSum / $totalAvanceCount) : 0,
            'documentacionPendiente' => $totalPendingDocs
        ];

        // Flatten for other tabs (Asistencias, Seguimiento, Docs)
        // These can be derived on frontend from reporteMaterias usually,
        // but if frontend expects flat lists for tables, we can prep them or tell frontend to flatten.
        // Frontend uses: reporteAsistencias, reporteSeguimiento, etc.
        // Let's return the main hierarchy and let Frontend flatten OR return all.
        // Returning all is easier for pagination if server-side, but here we do client-side.
        // Let's just return structured data + metrics.

        return response()->json([
            'reporteMaterias' => $reporteMaterias,
            'metricas' => $metricas
        ]);
    }
}
