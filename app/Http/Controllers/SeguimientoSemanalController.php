<?php

namespace App\Http\Controllers;

use App\Models\SeguimientoSemanal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SeguimientoSemanalController extends Controller
{
    public function index(Request $request)
    {
        $query = SeguimientoSemanal::with(['docente', 'asignatura', 'creador']);

        if ($request->has('carrera_id')) {
            $query->where('carrera_id', $request->carrera_id);
        }
        if ($request->has('sede_id')) {
            $query->where('sede_id', $request->sede_id);
        }
        if ($request->has('docente_id')) {
            $query->where('docente_id', $request->docente_id);
        }
        if ($request->has('asignatura_id')) {
            $query->where('asignatura_id', $request->asignatura_id);
        }
        if ($request->has('semana_inicio')) {
            $query->whereDate('semana_inicio', $request->semana_inicio);
        }

        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'sede_id' => 'required',
            'carrera_id' => 'required',
            'docente_id' => 'required',
            'asignatura_id' => 'required',
            'semana_inicio' => 'required|date',
            'criterios' => 'required|array',
            'alerta' => 'required|in:VERDE,AMARILLO,ROJO'
        ]);

        $seguimiento = SeguimientoSemanal::create([
            ...$request->all(),
            'created_by' => Auth::id() ?? 1 // Fallback for dev if auth missing, preferably Auth::id()
        ]);

        return response()->json($seguimiento, 201);
    }

    public function bulkGenerate(Request $request)
    {
        $request->validate([
            'semana_inicio' => 'required|date',
            'carrera_id' => 'required'
        ]);

        $start = \Carbon\Carbon::parse($request->semana_inicio);
        $end = $start->copy()->addDays(6);

        // Get all assignments for this carrera
        $asignaturas = \App\Models\Asignatura::where('carrera_id', $request->carrera_id)->get();

        $count = 0;
        foreach ($asignaturas as $asignatura) {
            // Check if report already exists for this week
            $exists = SeguimientoSemanal::where('asignatura_id', $asignatura->id)
                ->where('semana_inicio', $request->semana_inicio)
                ->exists();

            if ($exists) continue;

            // Calculate Status
            $statusData = $this->calculateCompliance($asignatura->id, $start, $end);

            // Try to find a docente associated with this asignatura
            // Logic: search in 'asignatura_docente' pivot or 'grupos'
            $docente_id = \DB::table('asignatura_docente')->where('asignatura_id', $asignatura->id)->first()?->docente_id;
            if (!$docente_id) {
                $docente_id = \App\Models\Grupo::where('asignatura_id', $asignatura->id)->first()?->docente_id;
            }

            if (!$docente_id) continue;

            // Determine Alerta (Same criteria as frontend)
            $totalCriterios = 7;
            $checked = collect($statusData)->filter(fn($v) => $v)->count();

            $alerta = 'ROJO';
            if ($checked === $totalCriterios) $alerta = 'VERDE';
            elseif ($checked >= $totalCriterios - 2) $alerta = 'AMARILLO';

            SeguimientoSemanal::create([
                'asignatura_id' => $asignatura->id,
                'docente_id' => $docente_id,
                'semana_inicio' => $request->semana_inicio,
                'criterios' => $statusData,
                'alerta' => $alerta,
                'sede_id' => $request->sede_id ?? 1,
                'carrera_id' => $request->carrera_id,
                'observaciones_generales' => 'CUMPLIDO',
                'created_by' => Auth::id() ?? 1
            ]);
            $count++;
        }

        return response()->json([
            'message' => "Se han generado $count reportes automáticos para la semana.",
            'count' => $count
        ]);
    }

    private function calculateCompliance($asignatura_id, $start, $end)
    {
        // 1. Fetch Cronogramas for this week
        $cronogramas = \App\Models\Cronograma::where('asignatura_id', $asignatura_id)
            ->whereBetween('fecha', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->withCount(['asistencias' => function ($aq) {
                $aq->where('asistio', true);
            }])
            ->get();

        if ($cronogramas->isEmpty()) {
            return [
                'temaImpartido' => false,
                'actividadesFormativas' => false,
                'secuenciaDidactica' => false,
                'plataformaVirtual' => false,
                'evidencias' => false,
                'evaluaciones' => false,
                'integracionTransversal' => false
            ];
        }

        // Logic based on ReporteController logic

        // 1. Asistencia Check (Any session with > 50% attendance)
        $anyAsistenciaOk = $cronogramas->contains(function ($s) {
            $totalRecords = $s->asistencias()->count();
            $present = $s->asistencias_count;
            return $totalRecords > 0 ? ($present / $totalRecords) >= 0.5 : false;
        });

        // 2. Content Check (Any session with content/theme assigned)
        $anyContentOk = $cronogramas->contains(function ($s) {
            return !empty($s->tema_id);
        });

        // 3. Planning Check (Strategies defined)
        $anyPlanningOk = $cronogramas->contains(function ($s) {
            $pedagogico = $s->pedagogico;
            return !empty($pedagogico) && !empty($pedagogico['estrategias']);
        });

        // 4. Completed Check
        $allCompleted = $cronogramas->every(function ($s) {
            return $s->cumplido;
        });

        return [
            'temaImpartido' => $anyContentOk,
            'actividadesFormativas' => $anyPlanningOk,
            'secuenciaDidactica' => $anyPlanningOk, // Proxy for now
            'plataformaVirtual' => $anyPlanningOk, // Proxy
            'evidencias' => $anyAsistenciaOk, // Minimum requirement
            'evaluaciones' => $allCompleted,
            'integracionTransversal' => false // Manual
        ];
    }

    public function checkStatus(Request $request)
    {
        $request->validate([
            'asignatura_id' => 'required',
            'semana_inicio' => 'required|date'
        ]);

        $start = \Carbon\Carbon::parse($request->semana_inicio);
        $end = $start->copy()->addDays(6);

        $criterios = $this->calculateCompliance($request->asignatura_id, $start, $end);

        return response()->json(['criterios' => $criterios]);
    }
}
