<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\Carrera;
use App\Models\Sede;
use App\Models\User;
use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function director(Request $request)
    {
        $request->validate([
            'carrera_id' => 'required',
            'sede_id' => 'required'
        ]);

        $carreraId = $request->carrera_id;
        $sedeId = $request->sede_id;

        // 1. Asignaturas Context
        $asignaturas = Asignatura::whereHas('carreras', function ($q) use ($carreraId, $sedeId) {
            $q->where('carreras.id', $carreraId)
                ->where('asignatura_carrera.sede_id', $sedeId);
        })->withCount('temas')->with(['grupos.cronogramas'])->get();

        $totalAsignaturas = $asignaturas->count();

        // 2. Docentes
        $docentes = User::whereHas('docente.grupos.asignatura.carreras', function ($q) use ($carreraId, $sedeId) {
            $q->where('carreras.id', $carreraId)
                ->where('asignatura_carrera.sede_id', $sedeId);
        })->with('docente.grupos.asignatura')->get();

        // 3. Metrics
        $totalAvanceSum = 0;
        $totalAvanceCount = 0;
        $documentacionPendiente = 0;

        // Progress per semester
        $semestresMap = [];
        for ($i = 1; $i <= 10; $i++) $semestresMap[$i] = ['asignaturas' => 0, 'avanceSum' => 0];

        foreach ($asignaturas as $asignatura) {
            $semestre = DB::table('asignatura_carrera')
                ->where('asignatura_id', $asignatura->id)
                ->where('carrera_id', $carreraId)
                ->where('sede_id', $sedeId)
                ->value('semestre') ?? 1;

            $semestresMap[$semestre]['asignaturas']++;

            $materiaAvance = 0;
            $materiaGruposCount = $asignatura->grupos->count();

            if ($materiaGruposCount > 0) {
                foreach ($asignatura->grupos as $grupo) {
                    $avanzados = $grupo->cronogramas->count();
                    $progreso = $asignatura->temas_count > 0 ? min(100, round(($avanzados / $asignatura->temas_count) * 100)) : 0;
                    $materiaAvance += $progreso;
                }
                $materiaAvance = round($materiaAvance / $materiaGruposCount);
            }

            $semestresMap[$semestre]['avanceSum'] += $materiaAvance;
            $totalAvanceSum += $materiaAvance;
            $totalAvanceCount++;
        }

        $semestresFormatted = [];
        foreach ($semestresMap as $num => $data) {
            if ($data['asignaturas'] > 0) {
                $semestresFormatted[] = [
                    'numero' => $num,
                    'asignaturas' => $data['asignaturas'],
                    'progreso' => round($data['avanceSum'] / $data['asignaturas'])
                ];
            }
        }

        // Docentes detail
        $docentesList = $docentes->map(function ($u) use ($carreraId) {
            $d = $u->docente;
            // Eager loaded asignatura.carreras or check asignatura directly
            $materiasCount = $d->grupos->filter(function ($g) use ($carreraId) {
                return $g->asignatura && $g->asignatura->carrera_id == $carreraId;
            })->count();

            return [
                'id' => $d->id,
                'nombre' => $u->nombre . ' ' . $u->apellido,
                'avatar' => strtoupper(substr($u->nombre, 0, 1) . substr($u->apellido, 0, 1)),
                'materias' => $materiasCount,
                'progreso' => 0
            ];
        })->values();

        return response()->json([
            'stats' => [
                'totalAsignaturas' => $totalAsignaturas,
                'docentesActivos' => $docentes->count(),
                'documentacionPendiente' => 0, // TODO
                'progresoCarrera' => $totalAvanceCount > 0 ? round($totalAvanceSum / $totalAvanceCount) : 0,
            ],
            'semestres' => $semestresFormatted,
            'docentes' => $docentesList
        ]);
    }

    public function index()
    {
        // 1. Basic Stats
        $totalSedes = Sede::count();
        $totalCarreras = Carrera::count();
        $totalAsignaturas = Asignatura::count();
        $totalUsuarios = User::count();

        // 2. Progress by Sede (Mocked for now but structure ready)
        $sedes = Sede::all();
        $estadisticasSedes = $sedes->map(function ($sede) {
            $colors = ['#7C3AED', '#14B8A6', '#F97316', '#3B82F6', '#22C55E', '#EF4444', '#8B5CF6', '#EC4899', '#06B6D4'];

            return [
                'id' => $sede->id,
                'nombre' => $sede->nombre,
                'progreso' => 0,
                'asignaturas' => Asignatura::whereHas('carreras', function ($q) use ($sede) {
                    $q->where('asignatura_carrera.sede_id', $sede->id);
                })->count(),
                'docentes' => User::whereHas('docente', function ($q) use ($sede) {
                    $q->where('sede_id', $sede->id);
                })->count(),
                'color' => $colors[$sede->id % count($colors)]
            ];
        });

        // 3. Users by Role
        $roles = Rol::withCount('users')->get();
        $usuariosPorRol = $roles->map(function ($rol) use ($totalUsuarios) {
            return [
                'nombre' => $rol->nombre,
                'cantidad' => $rol->usuarios_count,
                'porcentaje' => $totalUsuarios > 0 ? round(($rol->usuarios_count / $totalUsuarios) * 100, 1) : 0,
                'icono' => $rol->icono ?? 'person',
                'color' => $rol->color ?? '#7C3AED'
            ];
        });

        // 4. Global Metrics Calculation
        $temasTotales = DB::table('temas')->count();
        $temasAvanzados = DB::table('cronogramas')->whereNotNull('tema_id')->distinct('tema_id')->count();
        $progresoGlobal = $temasTotales > 0 ? min(100, round(($temasAvanzados / $temasTotales) * 100)) : 0;

        return response()->json([
            'stats' => [
                'totalSedes' => $totalSedes,
                'totalCarreras' => $totalCarreras,
                'totalAsignaturas' => $totalAsignaturas,
                'totalUsuarios' => $totalUsuarios,
                'progresoGlobal' => $progresoGlobal,
                'tasaCompletitud' => $progresoGlobal, // Use same for now or logic if exists
                'tasaCumplimiento' => round($progresoGlobal * 0.9), // Placeholder logic
            ],
            'estadisticasSedes' => $estadisticasSedes,
            'usuariosPorRol' => $usuariosPorRol,
            'actividadReciente' => [
                ['id' => 1, 'tipo' => 'success', 'texto' => 'Sistema auditado y optimizado', 'tiempo' => 'Ahora'],
                ['id' => 2, 'tipo' => 'info', 'texto' => 'Conexión a base de datos establecida', 'tiempo' => '1 min']
            ]
        ]);
    }
}
