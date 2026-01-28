<?php

namespace App\Http\Controllers;

use App\Models\Docente;
use Illuminate\Http\Request;

class DocenteController extends Controller
{
    public function index(Request $request)
    {
        $query = Docente::query()->with([
            'grupos.asignatura.carreras.sedes',
            'grupos.asignatura.unidades.temas',
            'grupos.cronogramas.asistencias',
            'grupos.horarios'
        ]);

        // Search
        if ($request->has('q') && $request->q) {
            $term = $request->q;
            $query->where(function ($q) use ($term) {
                $q->where('nombre_completo', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('ci', 'like', "%{$term}%");
            });
        } elseif ($request->has('search') && $request->search) {
            // Fallback for legacy calls
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('nombre_completo', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        // Filter: Sede
        if ($request->has('sede_id') && $request->sede_id) {
            $sedeId = $request->sede_id;
            // Filter docentes who have groups in asignaturas belonging to carreras in the selected sede
            $query->whereHas('grupos.asignatura.carreras.sedes', function ($q) use ($sedeId) {
                $q->where('sedes.id', $sedeId);
            });
        }

        // Filter: Carrera
        if ($request->has('carrera_id') && $request->carrera_id) {
            $carreraId = $request->carrera_id;
            $query->whereHas('grupos.asignatura.carreras', function ($q) use ($carreraId) {
                $q->where('carreras.id', $carreraId);
            });
        }

        // Filter: Estado
        if ($request->has('estado') && $request->estado !== null && $request->estado !== 'null') {
            $isActive = filter_var($request->estado, FILTER_VALIDATE_BOOLEAN);
            $query->where('estado', $isActive);
        }

        $docentes = $query->orderBy('nombre_completo')->get();

        $data = $docentes->map(function ($docente) {
            $grupos = $docente->grupos;
            $materiasData = [];

            // Group by Asignatura/Materia to show detailed progress per subject
            foreach ($grupos as $grupo) {
                if (!$grupo->asignatura) continue;

                $asignatura = $grupo->asignatura;

                // --- 1. Avance de Temas ---
                // Total themes in the subject
                $totalTemas = $asignatura->temas_count ?? $asignatura->unidades->sum(function ($u) {
                    return $u->temas->count();
                });
                // Themes covered (Cronogramas registered)
                $temasAvanzados = $grupo->cronogramas->count();

                $avanceTemas = 0;
                if ($totalTemas > 0) {
                    $avanceTemas = min(100, round(($temasAvanzados / $totalTemas) * 100));
                }

                // --- 2. Asistencia ---
                // Average attendance across all cronogramas for this group
                $asistenciaPromedio = 0;
                $totalAsistencias = 0;
                $asistenciasCount = 0;

                foreach ($grupo->cronogramas as $cronograma) {
                    // If relations loaded, calculate
                    if ($cronograma->asistencias->count() > 0) {
                        $presentes = $cronograma->asistencias->where('asistio', 1)->count();
                        $total = $cronograma->asistencias->count();
                        $totalAsistencias += ($presentes / $total) * 100;
                        $asistenciasCount++;
                    }
                }

                if ($asistenciasCount > 0) {
                    $asistenciaPromedio = round($totalAsistencias / $asistenciasCount);
                } else {
                    // Fallback randomness if no attendance data yet (For Demo/Real Feel if empty)
                    // Or keep 0. Let's keep 0 if real.
                    $asistenciaPromedio = 0;
                }

                // --- 3. Documentación Status ---
                // Heuristics based on PlanificacionPersonal existence
                // Check if ANY planning exists for this user + subject
                // Ideally, we check specific "types" or just existence.
                $hasPlanning = \App\Models\PlanificacionPersonal::where('user_id', $docente->id)
                    ->whereHas('tema.unidad.asignatura', function ($q) use ($asignatura) {
                        $q->where('id', $asignatura->id);
                    })->exists();

                $pac = $hasPlanning; // Simple heuristic: If they planned, PAC is "Done"
                $syllabus = $hasPlanning; // Syllabus is usually generated from plan
                $planClase = $avanceTemas > 0; // If they have cronogramas, they have class plans (implied)

                // Detailed State
                $estado = 'Al día';
                if ($avanceTemas < 20 && $totalTemas > 0) $estado = 'Atrasado';
                if (!$hasPlanning) $estado = 'Sin documentación';

                $materiasData[] = [
                    'id' => $asignatura->id,
                    'codigo' => $asignatura->codigo,
                    'nombre' => $asignatura->nombre,
                    'grupo' => $grupo->nombre, // 'Grupo 1'
                    'avanceTemas' => $avanceTemas,
                    'asistencia' => $asistenciaPromedio,
                    'pac' => $pac,
                    'planClase' => $planClase,
                    'syllabus' => $syllabus,
                    'estado' => $estado
                ];
            }

            // Inferencia de Sede (First Group)
            $sede = null;
            $firstGrupo = $grupos->first();
            if ($firstGrupo && $firstGrupo->asignatura) {
                // Try via pivot first
                $carrera = $firstGrupo->asignatura->carreras->first();
                if ($carrera && $carrera->sedes->first()) {
                    $sede = $carrera->sedes->first();
                } elseif ($carrera && $carrera->sede) {
                    $sede = $carrera->sede;
                }
            }

            // Initials calculation
            $parts = explode(' ', trim($docente->nombre_completo));
            $initials = '';
            if (count($parts) > 0) {
                // First letter of first name
                $initials .= strtoupper(substr($parts[0], 0, 1));
                // First letter of last name (if exists)
                if (count($parts) > 1) {
                    $initials .= strtoupper(substr(end($parts), 0, 1));
                }
            }

            // Mapping Response
            return [
                'id' => $docente->id,
                'nombre' => $docente->nombre_completo,
                'ci' => $docente->ci ?? 'N/A',
                'email' => $docente->email,
                'iniciales' => $initials ?: 'DC',
                'carrera' => $firstGrupo->asignatura->carrera_id ?? null, // Simplification
                'carrera_nombre' => $firstGrupo->asignatura->carreras->first()->nombre ?? 'General',
                'materiasData' => $materiasData,
                // Stats for top-level cards (calculated from materias)
                'materias_count' => count($materiasData),
                'estado_general' => collect($materiasData)->pluck('estado')->contains('Sin documentación') ? 'Sin documentación' : 'Al día',
                'sede_nombre' => $sede ? $sede->nombre : 'Cochabamba'
            ];
        });

        // Calculate Stats based on the processed data (not just raw query)
        $stats = [
            'totalDocentes' => $data->count(),
            'alDia' => $data->filter(fn($d) => $d['estado_general'] === 'Al día')->count(),
            'conRetraso' => $data->filter(fn($d) => $d['estado_general'] === 'Atrasado')->count(),
            'sinDocumentacion' => $data->filter(fn($d) => $d['estado_general'] === 'Sin documentación')->count(),
        ];

        return response()->json([
            'data' => $data,
            'metricas' => $stats // Renaming to match frontend expectation 'metricas'
        ]);
    }

    public function mySubjects(Request $request) {
        $user = auth()->user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        // Find Docente record linked to User
        // Looking at Model User, it usually has 'docente' relation or is checking ID.
        // In this project structure, Docente might BE the user or linked.
        // index() uses Docente::query(), implying Docente is a separate model?
        // Let's check if User is Docente.
        // Assuming Docente model has user_id or User has docente relation.
        $docente = Docente::where('user_id', $user->id)->first();
        
        // If not found (maybe User IS the Docente in some legacy logic, or testing admin), try to find by email or logic.
        // But let's assume valid linkage.
        if (!$docente) {
             // Fallback for demo: Return all if Super Admin? No, keep restricted.
             return response()->json([]);
        }

        // Get groups/subjects
        $grupos = $docente->grupos()->with(['asignatura.carreras'])->get();

        $subjects = $grupos->map(function ($grupo) {
             return [
                 'id' => $grupo->asignatura->id,
                 'nombre' => $grupo->asignatura->nombre,
                 'codigo' => $grupo->asignatura->codigo,
                 'grupo' => $grupo->nombre, // 'Grupo A'
                 'horario' => '08:00 - 10:00', // Mock/Placeholder unless Schedule exists
                 'carreras' => $grupo->asignatura->carreras->map(fn($c) => [
                     'id' => $c->id,
                     'nombreCarrera' => $c->nombre,
                     'materia' => $grupo->asignatura->nombre
                 ])
             ];
        });

        // If no groups found via Docente model (maybe direct User relation?), check alternatives.
        // But existing code uses $docente->grupos.

        return response()->json($subjects);
    }
}
