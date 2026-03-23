<?php

namespace App\Http\Controllers;

use App\Models\Docente;
use Illuminate\Http\Request;

class DocenteController extends Controller
{
    public function index(Request $request)
    {
        $query = Docente::query()->with([
            'sede',
            // Optimized Eager Loading
            'grupos.asignatura.carreras', // Removed .sedes (heavy & unused)
            // 'grupos.asignatura.unidades.temas', // Removing deep load, will use simplistic count or load on demand if needed.
            // Better: Load 'unidades' is fine, but 'temas' might be too much if we just need count.
            // Let's keep structure but maybe limit columns? For now, just removing .sedes is big.
            'grupos.asignatura.unidades.temas' => function ($q) {
                $q->select('id', 'unidad_id', 'titulo'); // 'titulo' instead of 'nombre'
            },
            'grupos.cronogramas' => function ($query) {
                // Optimization: Get counts instead of loading all assistance records
                $query->withCount([
                    'asistencias as total_asistencias',
                    'asistencias as presentes_asistencias' => function ($q) {
                        $q->where('asistio', 1);
                    }
                ]);
            },
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
            // Optimización: Filtrar directamente por sede_id del docente (asignado en Sync)
            $query->where('sede_id', $sedeId);

            /* RELACION COMPLEJA (LEGACY)
            $query->whereHas('grupos.asignatura.carreras.sedes', function ($q) use ($sedeId) {
                $q->where('sedes.id', $sedeId);
            });
            */
        }

        // Filter: Carrera
        if ($request->has('carrera_id') && $request->carrera_id) {
            $carreraId = $request->carrera_id;
            $query->whereHas('grupos.asignatura.carreras', function ($q) use ($carreraId) {
                $q->where('carreras.id', $carreraId);
            });
        }

        // Filter: Asignatura
        if ($request->has('asignatura_id') && $request->asignatura_id) {
            $asignaturaId = $request->asignatura_id;
            $query->whereHas('grupos.asignatura', function ($q) use ($asignaturaId) {
                $q->where('asignaturas.id', $asignaturaId);
            });
        }

        // Filter: Estado
        if ($request->has('estado') && $request->estado !== null && $request->estado !== 'null') {
            $isActive = filter_var($request->estado, FILTER_VALIDATE_BOOLEAN);
            $query->where('estado', $isActive);
        }

        $docentes = $query->orderBy('nombre_completo')->get();

        $data = $docentes->map(function ($docente) {
            try {
                $grupos = $docente->grupos;
                $materiasGrouped = [];

                // Group by Asignatura/Materia to show detailed progress per subject
                foreach ($grupos as $grupo) {
                    if (!$grupo->asignatura) continue;

                    $asignatura = $grupo->asignatura;

                    // Merge groups if the subject is already processed
                    if (isset($materiasGrouped[$asignatura->id])) {
                        $materiasGrouped[$asignatura->id]['grupo'] .= ', ' . $grupo->nombre;
                        continue;
                    }

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
                        // Optimized calculation using withCount attributes
                        if ($cronograma->total_asistencias > 0) {
                            $presentes = $cronograma->presentes_asistencias;
                            $total = $cronograma->total_asistencias;
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
                    // Programa Analitico is based on the initial configuration of the subject (descripcion, sistema interpretacion etc)
                    $programaAnalitico = !empty($asignatura->descripcion) && !empty($asignatura->justificacion) && !empty($asignatura->sistema_evaluacion);
                    
                    // PAC is based on having content in Temas
                    $totalTemasEvaluados = 0;
                    $temasConContenido = 0;
                    $temasConPlanClase = 0;

                    foreach ($asignatura->unidades as $unidad) {
                        foreach ($unidad->temas as $tema) {
                            $totalTemasEvaluados++;
                            
                            if (!empty($tema->contenido_conceptual) || !empty($tema->contenido_procedimental) || !empty($tema->contenido_items)) {
                                $temasConContenido++;
                            }

                            // Verifica si existe algun Plan de Clase del Docente actual para este tema
                            $hasPlan = \App\Models\PlanificacionPersonal::where('user_id', $docente->user_id)
                                ->where('tema_id', $tema->id)
                                ->exists();
                            
                            if ($hasPlan) {
                                $temasConPlanClase++;
                            }
                        }
                    }

                    $pac = ($totalTemasEvaluados > 0 && $temasConContenido == $totalTemasEvaluados);
                    $planClase = ($totalTemasEvaluados > 0 && $temasConPlanClase > 0);
                    $cronograma = $avanceTemas > 0; // If they have scheduled sessions

                    // Detailed State
                    $estado = 'Al día';
                    if (!$programaAnalitico || !$pac || !$planClase || !$cronograma) {
                        $estado = 'Sin documentación';
                    } else if ($avanceTemas < 20 && $totalTemas > 0) {
                        $estado = 'Atrasado';
                    }

                    // Load related carreras to allow UI filtering by director career
                    $carreras_ids = [];
                    if ($asignatura->carreras) {
                        $carreras_ids = $asignatura->carreras->pluck('id')->toArray();
                    }

                    $materiasGrouped[$asignatura->id] = [
                        'id' => $asignatura->id,
                        'codigo' => $asignatura->codigo,
                        'nombre' => $asignatura->nombre,
                        'grupo' => $grupo->nombre, // 'Grupo 1'
                        'avanceTemas' => $avanceTemas,
                        'programaAnalitico' => $programaAnalitico,
                        'pac' => $pac,
                        'planClase' => $planClase,
                        'cronograma' => $cronograma,
                        'estado' => $estado,
                        'carreras_ids' => $carreras_ids
                    ];
                }

                $materiasData = array_values($materiasGrouped);

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
                $parts = explode(' ', trim($docente->nombre_completo ?? ''));
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
                $carreraId = null;
                $carreraNombre = 'General';

                if ($firstGrupo && $firstGrupo->asignatura) {
                    $carreraId = $firstGrupo->asignatura->carrera_id ?? null;
                    $carreraObj = $firstGrupo->asignatura->carreras->first();
                    $carreraNombre = $carreraObj ? $carreraObj->nombre : 'General';
                }

                return [
                    'id' => $docente->id,
                    'nombre_completo' => $docente->nombre_completo,
                    'ci' => $docente->ci ?? 'N/A',
                    'email' => $docente->email,
                    'iniciales' => $initials ?: 'DC',
                    'carrera' => $carreraId,
                    'carrera_nombre' => $carreraNombre,
                    'materiasData' => $materiasData,
                    'grupos' => $grupos, // Include raw groups for frontend logic
                    'estado' => $docente->estado, // Active/Inactive status
                    'sede' => $docente->sede, // Sede object
                    'sede_id' => $docente->sede_id,
                    'grado_academico' => $docente->grado_academico,
                    'tipo_dedicacion' => $docente->tipo_dedicacion,
                    'telefono' => $docente->telefono,
                    'celular' => $docente->celular,
                    'direccion' => $docente->direccion,
                    'fecha_nacimiento' => $docente->fecha_nacimiento,
                    'fecha_ingreso' => $docente->fecha_ingreso,
                    'genero' => $docente->genero,
                    'estado_civil' => $docente->estado_civil,
                    'horas_semanales' => $docente->horas_semanales,
                    // Stats for top-level cards (calculated from materias)
                    'materias_count' => count($materiasData),
                    'estado_general' => collect($materiasData)->pluck('estado')->contains('Sin documentación') ? 'Sin documentación' : 'Al día',
                    'sede_nombre' => $sede ? $sede->nombre : ($docente->sede ? $docente->sede->nombre : 'Cochabamba')
                ];
            } catch (\Throwable $e) {
                // Return error object instead of crashing
                return [
                    'id' => $docente->id,
                    'nombre_completo' => 'ERROR: ' . $docente->nombre_completo,
                    'ci' => 'ERROR',
                    'email' => $docente->email,
                    'iniciales' => 'ERR',
                    'carrera' => null,
                    'carrera_nombre' => 'Error: ' . $e->getMessage(),
                    'materiasData' => [],
                    'materias_count' => 0,
                    'estado_general' => 'Sin documentación',
                    'sede_nombre' => 'Error'
                ];
            }
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
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function mySubjects(Request $request)
    {
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

    public function sync()
    {
        try {
            // Run the sync command
            \Illuminate\Support\Facades\Artisan::call('sync:docentes');

            return response()->json([
                'message' => 'Sincronización completada exitosamente',
                'output' => \Illuminate\Support\Facades\Artisan::output()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error durante la sincronización: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre_completo' => 'required|string|max:255',
            'ci' => 'required|string|unique:docentes,ci',
            'email' => 'nullable|email',
            'sede_id' => 'required|exists:sedes,id',
            'celular' => 'nullable|string',
            'grado_academico' => 'nullable|string'
        ]);

        $docente = Docente::create($validated);
        return response()->json($docente, 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $docente = Docente::findOrFail($id);

        $validated = $request->validate([
            'nombre_completo' => 'sometimes|required|string|max:255',
            'ci' => 'sometimes|required|string|unique:docentes,ci,' . $id,
            'email' => 'nullable|email',
            'sede_id' => 'sometimes|required|exists:sedes,id',
            'celular' => 'nullable|string',
            'grado_academico' => 'nullable|string',
            'estado' => 'sometimes|boolean'
        ]);

        $docente->update($validated);
        return response()->json($docente);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $docente = Docente::findOrFail($id);
        $docente->delete();
        return response()->json(null, 204);
    }
}
