<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\Grupo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GrupoController extends Controller
{
    /**
     * Lista grupos reales (modelo Grupo) con relaciones para el CRUD admin.
     * GET /api/grupos-flat
     */
    public function flatIndex(Request $request)
    {
        // Si el admin quiere ver todos los estados (para gestionar inactivos),
        // usar withoutGlobalScope. Por defecto solo muestra ACTIVOS.
        $baseQuery = $request->boolean('mostrar_inactivos')
            ? Grupo::withoutGlobalScope('activo')
            : Grupo::query();

        $query = $baseQuery->with(['asignatura', 'carrera', 'docente', 'sede'])
            ->whereHas('asignatura') // Excluir grupos huérfanos cuya asignatura fue eliminada
            ->orderBy('id', 'desc');

        if ($request->filled('sede_id')) {
            $query->where('sede_id', $request->sede_id);
        }
        if ($request->filled('carrera_id')) {
            $carreraId = $request->carrera_id;
            $sedeId    = $request->sede_id;
            $query->where('carrera_id', $carreraId);
            // Además, asegurar que la asignatura pertenezca a esta carrera (via pivot asignatura_carrera)
            // Esto evita que aparezcan asignaturas de otras carreras (ej. MED en BYF)
            $query->whereHas('asignatura.carreras', function ($q) use ($carreraId, $sedeId) {
                $q->where('carreras.id', $carreraId);
                if ($sedeId) {
                    $q->where('asignatura_carrera.sede_id', $sedeId);
                }
            });
        }
        if ($request->filled('asignatura_id')) {
            $query->where('asignatura_id', $request->asignatura_id);
        }
        if ($request->filled('plan_estudios')) {
            $query->where('plan_estudios', $request->plan_estudios);
        }
        if ($request->filled('gestion')) {
            $query->where('gestion', $request->gestion);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%$search%")
                  ->orWhereHas('asignatura', fn($q2) => $q2->where('nombre', 'like', "%$search%")->orWhere('codigo', 'like', "%$search%"))
                  ->orWhereHas('docente', fn($q2) => $q2->where('nombre_completo', 'like', "%$search%"));
            });
        }

        $perPage = $request->per_page ?? 50;
        $grupos = $query->paginate($perPage);

        $data = $grupos->getCollection()->map(fn($g) => [
            'id'                    => $g->id,
            'nombre'                => $g->nombre,
            'asignatura_id'         => $g->asignatura_id,
            'asignatura_nombre'     => $g->asignatura?->nombre,
            'asignatura_codigo'     => $g->asignatura?->codigo,
            'asignatura_plan'       => $g->asignatura?->plan_estudios,
            'carrera_id'            => $g->carrera_id,
            'carrera_nombre'        => $g->carrera?->nombre,
            'docente_id'            => $g->docente_id,
            'docente_nombre'        => $g->docente?->nombre_completo,
            'sede_id'               => $g->sede_id,
            'sede_nombre'           => $g->sede?->nombre,
            'gestion'               => $g->gestion,
            'tipo'                  => $g->tipo,
            'turno'                 => $g->turno,
            'estado'                => $g->estado,
            'plan_estudios'         => $g->plan_estudios,
            'id_horario_api'        => $g->id_horario_api,
            'modificado_localmente' => (bool) $g->modificado_localmente,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $grupos->currentPage(),
                'last_page'    => $grupos->lastPage(),
                'total'        => $grupos->total(),
            ],
        ]);
    }


    /**
     * Display a listing of subjects with their groups (schedules).
     * Filters by Sede, Carrera, Gestion, Semestre.
     */
    public function index(Request $request)
    {
        $query = Asignatura::query();
        $sedeId = $request->sede_id ?? $request->sede; // Allow alias
        $carreraId = $request->carrera_id ?? $request->carrera;
        $gestion = $request->gestion ?? '1-2026';
        $semestre = $request->semestre;

        // SECURITY: If user is Docente, force filter by their ID
        $user = $request->user();
        // Check role via relationship (lazy load if needed)
        if ($user && $user->rol && $user->rol->codigo === 'DOCENTE' && $user->docente) {
            $query->whereHas('grupos', function ($q) use ($user) {
                $q->where('docente_id', $user->docente->id);
            });
        }

        // 1. Filter by Carrera/Sede via pivot
        if ($carreraId) {
            $query->whereHas('carreras', function ($q) use ($carreraId, $sedeId) {
                $q->where('carreras.id', $carreraId);
                // If sede is provided, ensure the relationship matches that sede too
                if ($sedeId) {
                    $q->where('asignatura_carrera.sede_id', $sedeId);
                }
            });
        } elseif ($sedeId) {
            $query->whereHas('carreras', function ($q) use ($sedeId) {
                $q->where('asignatura_carrera.sede_id', $sedeId);
            });
        }

        // 2. Filter by Semestre (using pivot)
        if ($semestre && $carreraId) {
            $query->whereHas('carreras', function ($q) use ($carreraId, $semestre) {
                $q->where('carreras.id', $carreraId)
                    ->where('asignatura_carrera.semestre', $semestre);
            });
        }

        // 3. Eager Loading
        $query->with([
            'carreras' => function ($q) use ($carreraId, $sedeId) {
                if ($carreraId) $q->where('carreras.id', $carreraId);
                if ($sedeId) $q->where('asignatura_carrera.sede_id', $sedeId);
            },
            'grupos' => function ($q) use ($gestion, $user) {
                $q->where('gestion', $gestion);
                
                // If Docente, only load their own groups for this subject
                if ($user && $user->rol && $user->rol->codigo === 'DOCENTE' && $user->docente) {
                    $q->where('docente_id', $user->docente->id);
                }
                
                $q->with(['docente', 'horarios.aula.bloque']);
            }
        ]);

        // Pagination
        $materias = $query->paginate($request->per_page ?? 20);

        // Transformation
        $transformed = $materias->getCollection()->map(function ($materia) use ($request) {
            $pivotContext = $materia->carreras->first();

            $carreraNombre = $pivotContext ? $pivotContext->nombre : 'N/A';
            $sedeId = $pivotContext ? $pivotContext->pivot->sede_id : null;
            $semestre = $pivotContext ? $pivotContext->pivot->semestre : null;

            $gruposList = [];
            foreach ($materia->grupos as $grupo) {
                foreach ($grupo->horarios as $horario) {
                    $gruposList[] = [
                        'grupo_id' => $grupo->id,
                        'grupo' => $grupo->nombre,
                        'tipo_clase' => ucwords(strtolower($grupo->tipo ?? 'TEORICO')),
                        'dia' => $horario->dia,
                        'hora_inicio' => substr($horario->hora_inicio, 0, 5),
                        'hora_fin' => substr($horario->hora_fin, 0, 5),
                        'docente' => $grupo->docente ? $grupo->docente->nombre_completo : 'Sin Asignar',
                        'aula' => $horario->aula ? $horario->aula->nombre : 'Sin Aula',
                        'bloque' => ($horario->aula && $horario->aula->bloque) ? $horario->aula->bloque->nombre : '-',
                        'capacidad' => $horario->aula ? $horario->aula->capacidad : 0,
                        'pupitres' => $horario->aula ? $horario->aula->pupitres : 0,
                    ];
                }
                if ($grupo->horarios->isEmpty()) {
                    $gruposList[] = [
                        'grupo_id' => $grupo->id,
                        'grupo' => $grupo->nombre,
                        'tipo_clase' => ucwords(strtolower($grupo->tipo ?? 'TEORICO')),
                        'dia' => '-',
                        'hora_inicio' => '-',
                        'hora_fin' => '-',
                        'docente' => $grupo->docente ? $grupo->docente->nombre_completo : 'Sin Asignar',
                        'aula' => '-',
                        'bloque' => '-',
                        'capacidad' => 0,
                        'pupitres' => 0,
                    ];
                }
            }

            return [
                'id' => $materia->id,
                'codigo' => $materia->codigo,
                'nombre' => $materia->nombre,
                'carrera' => $carreraNombre,
                'sede_nombre' => 'Sede ' . $sedeId,
                'semestre' => $semestre,
                'gestion' => $request->gestion,
                'comun_token' => $materia->comun_token,
                'comun_tipo' => $materia->comun_tipo,
                'grupos' => $gruposList
            ];
        });

        $meta = [
            'current_page' => $materias->currentPage(),
            'last_page' => $materias->lastPage(),
            'total' => $materias->total(),
            'total_materias' => $materias->total(),
            'carrera' => $request->carrera_id ? 'Carrera ID ' . $request->carrera_id : 'Todas',
            'gestion' => $gestion
        ];

        return response()->json([
            'data' => $transformed,
            'meta' => $meta
        ]);
    }
    public function show($id)
    {
        $grupo = \App\Models\Grupo::withoutGlobalScope('activo')->with([
            'asignatura.carreras.sedes', // To find context
            'asignatura.carreras.director',
            'docente.sede',
            'horarios.aula.bloque'
        ])->findOrFail($id);

        $asignatura = $grupo->asignatura;
        $docente = $grupo->docente;

        // Context Logic: prefer the academic assignment stored on the group itself.
        $sedeIdFromGroup = $grupo->sede_id ?: $grupo->horarios->first()?->aula?->bloque?->sede_id;

        // Find the Carrera context that matches the group's academic assignment.
        $carreraPivot = $asignatura->carreras->first(function ($c) use ($grupo, $sedeIdFromGroup) {
            return (int) $c->id === (int) $grupo->carrera_id
                && (int) $c->pivot->sede_id === (int) $sedeIdFromGroup;
        });

        if (!$carreraPivot && $grupo->carrera_id) {
            $carreraPivot = $asignatura->carreras->firstWhere('id', $grupo->carrera_id);
        }

        if (!$carreraPivot && $sedeIdFromGroup) {
            $carreraPivot = $asignatura->carreras->first(function ($c) use ($sedeIdFromGroup) {
                return (int) $c->pivot->sede_id === (int) $sedeIdFromGroup;
            });
        }

        // Fallback to first if not found by sede
        if (!$carreraPivot) {
            $carreraPivot = $asignatura->carreras->first();
        }

        $carrera = $carreraPivot;
        $area = $carrera ? $carrera->area : 'ÁREA NO DEFINIDA';
        $semestre = $carreraPivot ? $carreraPivot->pivot->semestre : 'N/A';
        $sedeId = $carreraPivot ? $carreraPivot->pivot->sede_id : $sedeIdFromGroup;
        $sede = $sedeId ? \App\Models\Sede::find($sedeId)?->nombre : 'SEDE NO DEFINIDA';

        // Load full related data for PDF generation
        $asignatura->load([
            'unidades' => function ($query) {
                $query->orderByRaw('CAST(numero AS UNSIGNED), numero')
                    ->with(['temas' => function ($temaQuery) {
                        $temaQuery->orderBy('orden')->orderBy('id');
                    }]);
            },
            'bibliografias' => function ($query) {
                $query->orderByRaw("CASE WHEN LOWER(tipo) LIKE 'bas%' THEN 0 ELSE 1 END")
                    ->orderBy('id');
            }
        ]);

        $horasTeoricas = (int) ($asignatura->horas_teoricas ?? 0);
        $horasPracticas = (int) ($asignatura->horas_practicas ?? 0);
        $planEstudios = strtoupper((string) ($asignatura->plan_estudios ?: $grupo->plan_estudios ?: 'N'));
        $hrsSemestreImpresion = $this->calcularHrsSemestreImpresion($asignatura);

        $unidadesImpresion = $asignatura->unidades->map(function ($unidad) {
            return [
                'id' => $unidad->id,
                'numero' => $unidad->numero,
                'titulo' => $unidad->titulo,
                'temas' => $unidad->temas->map(function ($tema) {
                    return [
                        'id' => $tema->id,
                        'orden' => $tema->orden,
                        'titulo' => $tema->titulo,
                        'contenido_impresion' => $this->construirContenidoImpresionTema($tema),
                        'contenido_items' => $this->normalizarFragmentosTexto($tema->contenido_items),
                        'horas_teoricas' => (int) ($tema->horas_teoricas ?? 0),
                        'horas_practicas' => (int) ($tema->horas_practicas ?? 0),
                    ];
                })->values(),
            ];
        })->values();

        $bibliografiasImpresion = $asignatura->bibliografias->map(function ($bibliografia) {
            return [
                'id' => $bibliografia->id,
                'tipo' => $bibliografia->tipo,
                'titulo' => $bibliografia->titulo,
                'descripcion' => $bibliografia->descripcion,
                'autor' => $bibliografia->autor,
                'editorial' => $bibliografia->editorial,
                'edicion' => $bibliografia->edicion,
                'anio' => $bibliografia->anio,
                'texto' => $this->formatearBibliografiaImpresion($bibliografia),
            ];
        })->values();

        $horarios = $grupo->horarios->map(function ($h) {
            return [
                'dia' => $h->dia,
                'hora_inicio' => substr($h->hora_inicio, 0, 5),
                'hora_fin' => substr($h->hora_fin, 0, 5),
                'aula' => $h->aula->nombre ?? 'N/A'
            ];
        });

        $examenes = \App\Models\RolExamen::where('materia_codigo', $asignatura->codigo)
            ->where('gestion', $grupo->gestion)
            ->where(function ($q) use ($grupo) {
                $q->where('grupo', $grupo->nombre)
                    ->orWhereNull('grupo');
            })
            ->orderBy('semana')
            ->get()
            ->map(function ($e) {
                return [
                    'tipo' => $e->tipo_examen,
                    'fecha' => $e->fecha ? $e->fecha->format('d/m/Y') : '-',
                    'hora' => substr($e->hora_inicio, 0, 5) . ' - ' . substr($e->hora_fin, 0, 5),
                    'aula' => $e->aula ?? '-'
                ];
            });

        return response()->json([
            'area' => $area,
            'carrera' => $carrera ? $carrera->nombre : 'Sin Carrera',
            'carrera_obj' => $carrera,
            'mision' => $carrera ? $carrera->mision : 'Misión no definida',
            'vision' => $carrera ? $carrera->vision : 'Visión no definida',
            'perfil_profesional' => $carrera ? $carrera->perfil_profesional : 'Perfil no definido',
            'sede' => $sede,
            'docente_nombre' => $docente ? $docente->nombre_completo : 'Sin Docente',
            'asignatura' => $asignatura->nombre,
            'asignatura_obj' => [
                'id' => $asignatura->id,
                'nombre' => $asignatura->nombre,
                'codigo' => $asignatura->codigo,
                'creditos' => $asignatura->creditos,
                'semestre' => $semestre,
                'plan_estudios' => $planEstudios,
                'horas_teoricas' => $horasTeoricas,
                'horas_practicas' => $horasPracticas,
                'hrs_semestre_impresion' => $hrsSemestreImpresion,
                'unidades' => $unidadesImpresion,
                'bibliografias' => $bibliografiasImpresion,
                'bibliografia' => $bibliografiasImpresion->pluck('texto')->filter()->values(),
                'objetivo_general' => $asignatura->objetivo_general ?? 'Desarrollar competencias profesionales en el área.'
            ],
            'codigo_asignatura' => $asignatura->codigo,
            'semestre' => $semestre,
            'grupo' => $grupo->nombre,
            'gestion' => $grupo->gestion,
            'horarios' => $horarios,
            'examenes' => $examenes
        ]);
    }

    private function calcularHrsSemestreImpresion(Asignatura $asignatura): int
    {
        $horasSemanales = (int) ($asignatura->horas_teoricas ?? 0) + (int) ($asignatura->horas_practicas ?? 0);

        if ($horasSemanales > 0) {
            return $horasSemanales * 20;
        }

        $cargaHoraria = (int) ($asignatura->carga_horaria_total ?? 0);
        if ($cargaHoraria > 0) {
            return $cargaHoraria;
        }

        return 0;
    }

    private function construirContenidoImpresionTema($tema): string
    {
        $partes = [];

        foreach ($this->normalizarFragmentosTexto($tema->contenido_items) as $fragmento) {
            $partes[] = $fragmento;
        }

        $texto = implode('. ', array_unique(array_filter($partes)));
        $texto = preg_replace('/\s+/', ' ', trim((string) $texto));

        if ($texto === '') {
            return '';
        }

        return rtrim($texto, '. ') . '.';
    }

    private function normalizarFragmentosTexto($valor): array
    {
        if (is_string($valor)) {
            $decodificado = json_decode($valor, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodificado)) {
                return $this->normalizarFragmentosTexto($decodificado);
            }

            $limpio = trim(preg_replace('/\s+/', ' ', preg_replace('/^[\p{Pd}\p{Po}\x{2022}o]+\s*/u', '', strip_tags($valor))));
            return $limpio !== '' ? [$limpio] : [];
        }

        if (is_array($valor)) {
            $resultado = [];
            foreach ($valor as $item) {
                $resultado = array_merge($resultado, $this->normalizarFragmentosTexto($item));
            }
            return $resultado;
        }

        return [];
    }

    private function formatearBibliografiaImpresion($bibliografia): string
    {
        $descripcion = trim((string) ($bibliografia->descripcion ?? ''));
        if ($descripcion !== '') {
            return $descripcion;
        }

        $partes = array_filter([
            trim((string) ($bibliografia->autor ?? '')),
            trim((string) ($bibliografia->titulo ?? '')),
            trim((string) ($bibliografia->editorial ?? '')),
            trim((string) ($bibliografia->edicion ?? '')),
            trim((string) ($bibliografia->anio ?? '')),
        ]);

        return implode('. ', $partes);
    }

    /**
     * Crear un nuevo grupo.
     * POST /api/grupos
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre'               => 'required|string|max:50',
            'asignatura_id'        => 'required|exists:asignaturas,id',
            'docente_id'           => 'nullable|exists:docentes,id',
            'carrera_id'           => 'nullable|exists:carreras,id',
            'sede_id'              => 'nullable|exists:sedes,id',
            'gestion'              => 'required|string|max:20',
            'tipo'                 => 'nullable|string|max:20',
            'turno'                => 'nullable|string|max:20',
            'estado'               => 'nullable|string|max:20',
            'plan_estudios'        => 'nullable|string|in:N,A',
            'id_horario_api'       => 'nullable|integer',
            'modificado_localmente'=> 'nullable|boolean',
        ]);

        // Establecer modificado_localmente en true por defecto para creación local
        if (!isset($validated['modificado_localmente'])) {
            $validated['modificado_localmente'] = true;
        }

        $grupo = \App\Models\Grupo::create($validated);

        return response()->json($grupo, 201);
    }

    /**
     * Actualizar un grupo existente.
     * PUT /api/grupos/{id}
     */
    public function update(Request $request, $id)
    {
        $grupo = \App\Models\Grupo::withoutGlobalScope('activo')->withTrashed()->findOrFail($id);

        $validated = $request->validate([
            'nombre'               => 'sometimes|string|max:50',
            'asignatura_id'        => 'sometimes|exists:asignaturas,id',
            'docente_id'           => 'nullable|exists:docentes,id',
            'carrera_id'           => 'nullable|exists:carreras,id',
            'sede_id'              => 'nullable|exists:sedes,id',
            'gestion'              => 'sometimes|string|max:20',
            'tipo'                 => 'nullable|string|max:20',
            'turno'                => 'nullable|string|max:20',
            'estado'               => 'nullable|string|max:20',
            'plan_estudios'        => 'nullable|string|in:N,A',
            'id_horario_api'       => 'nullable|integer',
            'modificado_localmente'=> 'nullable|boolean',
        ]);

        // Marcar como modificado localmente al actualizar
        $validated['modificado_localmente'] = true;

        $grupo->update($validated);

        return response()->json($grupo);
    }

    /**
     * Eliminar un grupo.
     * DELETE /api/grupos/{id}
     */
    public function destroy($id)
    {
        $grupo = \App\Models\Grupo::withoutGlobalScope('activo')->withTrashed()->findOrFail($id);
        $grupo->delete();

        return response()->json(null, 204);
    }
}
