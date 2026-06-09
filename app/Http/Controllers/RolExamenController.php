<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateRolExamenPackageJob;
use App\Models\BancoPregunta;
use App\Models\RolExamen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class RolExamenController extends Controller
{
    private const VISUALIZADOR_EVALUACIONES_ROLES = [
        'VISUALIZADOR_EVALUACIONES_GLOBAL',
        'VISUALIZADOR_EVALUACIONES_SEDE',
    ];

    /**
     * Listar exámenes por gestión y carrera
     */
    public function index(Request $request)
    {
        $query = RolExamen::query()
            ->select(
                'rol_examenes.*',
                DB::raw('COALESCE(MAX(rol_examenes.materia_nombre), MAX(asignaturas.nombre)) as materia'),
                DB::raw('MAX(carreras.nombre) as carrera'),
                DB::raw('MAX(sedes.nombre) as sede'),
                DB::raw('COALESCE(MAX(grupos.asignatura_id), MAX(asignaturas.id)) as asignatura_id'),
                DB::raw('MAX(docentes.id) as docente_id'),
                DB::raw('MAX(docentes.nombre_completo) as docente'),
                DB::raw('MAX(asignatura_carrera.semestre) as semestre'),
                DB::raw("(SELECT COUNT(*) FROM banco_preguntas 
                           WHERE banco_preguntas.asignatura_id = COALESCE(MAX(grupos.asignatura_id), MAX(asignaturas.id))
                           AND banco_preguntas.sede_id = rol_examenes.sede_id
                           AND (banco_preguntas.docente_id = MAX(docentes.id) OR MAX(docentes.id) IS NULL)
                           AND banco_preguntas.parcial = rol_examenes.tipo_examen 
                           AND (
                               banco_preguntas.grupoTeorico = rol_examenes.grupo 
                               OR banco_preguntas.grupoTeorico LIKE CONCAT('%', rol_examenes.grupo, '%')
                               OR rol_examenes.grupo LIKE CONCAT('%', banco_preguntas.grupoTeorico, '%')
                               OR REPLACE(REPLACE(REPLACE(REPLACE(UPPER(rol_examenes.grupo), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = 
                                  REPLACE(REPLACE(REPLACE(REPLACE(UPPER(banco_preguntas.grupoTeorico), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '')
                               OR (
                                  (banco_preguntas.grupoTeorico IS NULL OR banco_preguntas.grupoTeorico = '')
                                  AND banco_preguntas.grupo = rol_examenes.grupo
                               )
                           )
                          ) as total_banco"),
                DB::raw("(SELECT con_cartilla FROM banco_preguntas_configuraciones 
                           WHERE banco_preguntas_configuraciones.asignatura_id = COALESCE(MAX(grupos.asignatura_id), MAX(asignaturas.id))
                           AND banco_preguntas_configuraciones.sede_id = rol_examenes.sede_id
                           AND banco_preguntas_configuraciones.parcial = rol_examenes.tipo_examen 
                           AND (
                               REPLACE(REPLACE(REPLACE(REPLACE(UPPER(rol_examenes.grupo), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = 
                               REPLACE(REPLACE(REPLACE(REPLACE(UPPER(banco_preguntas_configuraciones.grupo_teorico), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '')
                           )
                           LIMIT 1
                          ) as con_cartilla")
            )
            ->join('carreras', 'rol_examenes.carrera_id', '=', 'carreras.id')
            ->join('sedes', 'rol_examenes.sede_id', '=', 'sedes.id')
            ->join('asignaturas', function ($join) {
                $join->on('rol_examenes.materia_codigo', '=', 'asignaturas.codigo')
                    ->where('asignaturas.estado', '!=', 'cancelado')
                    ->whereRaw("(rol_examenes.sede_id != 1 OR (rol_examenes.sede_id = 1 AND asignaturas.plan_estudios = 'N'))");
            })
            ->join('asignatura_carrera', function ($join) {
                $join->on('asignaturas.id', '=', 'asignatura_carrera.asignatura_id')
                    ->on('rol_examenes.carrera_id', '=', 'asignatura_carrera.carrera_id')
                    ->on('rol_examenes.sede_id', '=', 'asignatura_carrera.sede_id');
            })
            ->leftJoin('grupos', function ($join) {
                $join->on('rol_examenes.sede_id', '=', 'grupos.sede_id')
                    ->on('rol_examenes.carrera_id', '=', 'grupos.carrera_id')
                    ->on('asignaturas.id', '=', 'grupos.asignatura_id')
                    ->where('grupos.estado', 'ACTIVO')
                    ->whereNull('grupos.deleted_at')
                    ->where(function ($q) {
                        $q->whereColumn('rol_examenes.grupo', '=', 'grupos.nombre')
                            ->orWhereRaw("REPLACE(REPLACE(REPLACE(UPPER(rol_examenes.grupo), 'GRUPO ', ''), 'G-', ''), 'G', '') = 
                                        REPLACE(REPLACE(REPLACE(UPPER(grupos.nombre), 'GRUPO ', ''), 'G-', ''), 'G', '')");
                    });
            })
            ->leftJoin('docentes', 'grupos.docente_id', '=', 'docentes.id');

        // Filtros
        if ($request->has('gestion')) {
            $query->where('rol_examenes.gestion', $request->gestion);
        }

        if ($request->has('carrera_id')) {
            $carreraId = $request->carrera_id;
            $user = auth()->user();
            if ($user && isset($user->rol) && $user->rol->codigo === 'DIRECTOR_CARRERA') {
                $carreraIds = [];
                if ($user->director) {
                    if ($user->director->carrera_id) {
                        $carreraIds[] = $user->director->carrera_id;
                    }
                    // Incluir carreras de la relación legacy HasMany (carreras.director_id)
                    if ($user->director->carreras) {
                        $carreraIds = array_merge($carreraIds, $user->director->carreras->pluck('id')->toArray());
                    }
                    // Incluir carreras de la nueva relación muchos-a-muchos (tabla pivot)
                    $carreraIds = array_merge($carreraIds, $user->director->carreras()->pluck('carrera_id')->toArray());
                }
                if (! in_array($carreraId, array_unique($carreraIds))) {
                    return response()->json(['message' => 'No tiene permiso para ver esta carrera'], 403);
                }
            }
            $query->where('rol_examenes.carrera_id', $carreraId);
        }

        // Restricción de Sede para Directores y Campus para Evaluaciones
        $user = auth()->user();
        $rolCodigo = $user?->rol?->codigo;

        if ($user && $rolCodigo === 'VISUALIZADOR_EVALUACIONES_GLOBAL') {
            if ($request->has('sede_id')) {
                $query->where('rol_examenes.sede_id', $request->sede_id);
            }
        } elseif ($user && $rolCodigo === 'VISUALIZADOR_EVALUACIONES_SEDE') {
            $this->aplicarAlcanceSedesAsignadas($query, $user, $request, 'Visualizador evaluaciones');
        } elseif ($user && $user->rol && $user->rol->codigo === 'RESPONSABLE_EVALUACIONES') {
            // Acceso Global: No aplicar filtros de sede/campus automáticos
            if ($request->has('sede_id')) {
                $query->where('rol_examenes.sede_id', $request->sede_id);
            }
        } elseif ($user && $user->rol && in_array($user->rol->codigo, ['DIRECTOR_CARRERA', 'VICERRECTORADO', 'VICERRECTOR_SEDE', 'DIRECCION_ACADEMICA', 'DIRECCIÓN ACADÉMICA'])) {
            $sedeId = $user->director?->sede_id ?? $user->docente?->sede_id ?? $user->sede_id;
            if ($sedeId) {
                $query->where('rol_examenes.sede_id', $sedeId);
                Log::info("Filtrando RolExamen por sede de Autoridad ({$user->rol->codigo}): {$sedeId}");
            }

            if ($user->rol->codigo === 'DIRECTOR_CARRERA') {
                $carreraIds = $this->carreraIdsDirector($user);

                if (empty($carreraIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('rol_examenes.carrera_id', $carreraIds);
                }
            }
        } elseif ($user && $user->load('rol') && $user->rol->codigo === 'PLATAFORMA') {
            $sedeIds = collect([$user->sede_id])->filter();

            if (Schema::hasTable('campus_user')) {
                $campusIds = DB::table('campus_user')
                    ->where('user_id', $user->id)
                    ->pluck('campus_id');

                $sedeIds = $sedeIds->merge(
                    DB::table('campus')
                        ->whereIn('id', $campusIds)
                        ->pluck('sede_id')
                );
            }

            $sedeIds = $sedeIds->map(fn ($id) => (int) $id)->filter()->unique()->values();
            $requestedSedeId = $request->filled('sede_id') ? (int) $request->sede_id : null;

            if ($requestedSedeId && ! $sedeIds->contains($requestedSedeId)) {
                $query->whereRaw('1 = 0');
            } elseif ($requestedSedeId) {
                $query->where('rol_examenes.sede_id', $requestedSedeId);
            } elseif ($sedeIds->isNotEmpty()) {
                $query->whereIn('rol_examenes.sede_id', $sedeIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif ($user && $user->load('rol') && $user->rol->codigo === 'EVALUACIONES') {
            $campusIds = collect([$user->campus_id])->filter();

            if (Schema::hasTable('campus_user')) {
                $campusIds = $campusIds->merge(
                    DB::table('campus_user')
                        ->where('user_id', $user->id)
                        ->pluck('campus_id')
                );
            }

            $campusIds = $campusIds->map(function ($id) {
                return (int) $id;
            })->filter()->unique()->values();

            if ($campusIds->isNotEmpty()) {
                $requestedSedeId = $request->filled('sede_id') ? (int) $request->sede_id : null;
                $campusRows = DB::table('campus')
                    ->whereIn('id', $campusIds)
                    ->select('id', 'sede_id')
                    ->get();

                if ($requestedSedeId) {
                    $campusIds = $campusRows
                        ->where('sede_id', $requestedSedeId)
                        ->pluck('id')
                        ->values();
                }

                if ($campusIds->isEmpty()) {
                    $query->whereRaw('1 = 0');
                    Log::info(
                        'Evaluador intento filtrar una sede sin campus asignado',
                        ['user_id' => $user->id, 'sede_id' => $requestedSedeId]
                    );
                }

                $carreraIds = DB::table('campus_carrera')
                    ->whereIn('campus_id', $campusIds)
                    ->pluck('carrera_id')
                    ->unique()
                    ->values();

                $sedeIds = $requestedSedeId
                    ? collect([$requestedSedeId])
                    : $campusRows->pluck('sede_id')->filter()->unique()->values();

                if (! $requestedSedeId && $user->sede_id) {
                    $sedeIds = $sedeIds->push($user->sede_id)->unique()->values();
                }

                $query->whereIn('rol_examenes.carrera_id', $carreraIds);
                if ($sedeIds->isNotEmpty()) {
                    $query->whereIn('rol_examenes.sede_id', $sedeIds);
                }

                Log::info(
                    'Filtrando RolExamen por campus del Evaluador',
                    ['campus_ids' => $campusIds->all(), 'sede_ids' => $sedeIds->all()]
                );
            }
        } elseif ($request->has('sede_id')) {
            $query->where('rol_examenes.sede_id', $request->sede_id);
        }

        if ($request->filled('fecha_inicio') || $request->filled('fecha_fin')) {
            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            if ($fechaInicio && $fechaFin && $fechaInicio > $fechaFin) {
                [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];
            }

            if ($fechaInicio) {
                $query->whereDate('rol_examenes.fecha', '>=', $fechaInicio);
            }

            if ($fechaFin) {
                $query->whereDate('rol_examenes.fecha', '<=', $fechaFin);
            }
        } elseif ($request->filled('fecha')) {
            $query->whereDate('rol_examenes.fecha', $request->fecha);
        }

        if ($request->has('materia_codigo')) {
            $query->where('rol_examenes.materia_codigo', $request->materia_codigo);
        }

        if ($request->filled('modalidad')) {
            $query->where('rol_examenes.modalidad', $request->modalidad);
        }

        if ($request->has('estado')) {
            $estados = is_array($request->estado) ? $request->estado : explode(',', $request->estado);
            if (! empty($estados) && $estados[0] !== 'Todos' && $estados[0] !== '') {
                $query->whereIn('rol_examenes.estado', $estados);
            }
        }

        $examenes = $query->groupBy('rol_examenes.id')
            ->orderBy('rol_examenes.semana')
            ->orderBy('rol_examenes.fecha')
            ->orderBy('rol_examenes.hora_inicio')
            ->get();

        $examenes->transform(function ($examen) {
            $statsBanco = $this->calcularStatsBancoRolExamen($examen);

            $examen->total_banco = $statsBanco['total'];
            $examen->banco_facil = $statsBanco['facil'];
            $examen->banco_medio = $statsBanco['medio'];
            $examen->banco_dificil = $statsBanco['dificil'];
            $examen->banco_g1 = $statsBanco['g1'];
            $examen->banco_g2 = $statsBanco['g2'];
            $examen->banco_g3 = $statsBanco['g3'];
            $examen->banco_por_tipo = $statsBanco['por_tipo'];
            $examen->banco_por_grupo_tipo = $statsBanco['por_grupo_tipo'];
            $examen->banco_stats = $statsBanco;
            $examen->puede_restaurar_generacion = $this->canRestoreGeneratedPackage($examen);

            return $examen;
        });

        return response()->json([
            'data' => $examenes,
            'meta' => [
                'total' => $examenes->count(),
                'gestion' => $request->gestion,
            ],
        ]);
    }

    private function carreraIdsDirector($user): array
    {
        if (! $user?->director) {
            return [];
        }

        $user->loadMissing('director.carreras');

        return collect([
            $user->director->carrera_id,
            $user->carrera_id ?? null,
        ])->merge($user->director->carreras?->pluck('id') ?? collect())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Calcular disponibilidad del banco para la fila del rol.
     */
    private function calcularStatsBancoRolExamen($examen): array
    {
        $grupo = trim((string) $examen->grupo);
        $grupoNormalizado = $this->normalizarGrupoBanco($grupo);

        $query = DB::table('banco_preguntas')
            ->where('asignatura_id', $examen->asignatura_id)
            ->where('sede_id', $examen->sede_id)
            ->where('parcial', $examen->tipo_examen)
            ->where(function ($q) use ($examen) {
                $q->where('docente_id', $examen->docente_id);

                if (! $examen->docente_id) {
                    $q->orWhereNull('docente_id');
                }
            })
            ->where(function ($q) use ($grupo, $grupoNormalizado) {
                $q->where('grupoTeorico', $grupo)
                    ->orWhere('grupoTeorico', 'LIKE', "%{$grupo}%")
                    ->orWhereRaw('? LIKE CONCAT("%", grupoTeorico, "%")', [$grupo])
                    ->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupoTeorico), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                        [$grupoNormalizado]
                    )
                    ->orWhere(function ($legacy) use ($grupo, $grupoNormalizado) {
                        $legacy->where(function ($emptyGrupoTeorico) {
                            $emptyGrupoTeorico->whereNull('grupoTeorico')
                                ->orWhere('grupoTeorico', '');
                        })->where(function ($legacyGrupo) use ($grupo, $grupoNormalizado) {
                            $legacyGrupo->where('grupo', $grupo)
                                ->orWhere('grupo', 'LIKE', "%{$grupo}%")
                                ->orWhereRaw(
                                    "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupo), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                                    [$grupoNormalizado]
                                );
                        });
                    });
            });

        $stats = [
            'total' => 0,
            'facil' => 0,
            'medio' => 0,
            'dificil' => 0,
            'g1' => 0,
            'g2' => 0,
            'g3' => 0,
            'por_tipo' => [],
            'por_grupo_tipo' => ['g1' => 0, 'g2' => 0, 'g3' => 0],
        ];

        foreach ($query->get(['tipo', 'dificultad']) as $pregunta) {
            $stats['total']++;

            $dificultad = $this->normalizarDificultadBanco($pregunta->dificultad);
            if (in_array($dificultad, ['FACIL', '1'], true)) {
                $stats['facil']++;
            } elseif (in_array($dificultad, ['MEDIA', 'MEDIO', '2'], true)) {
                $stats['medio']++;
            } elseif (in_array($dificultad, ['DIFICIL', '3'], true)) {
                $stats['dificil']++;
            }

            $tipoNormalizado = $this->normalizarTipoBanco($pregunta->tipo);
            $grupoTipo = $this->resolverGrupoTipoBanco($tipoNormalizado);
            if ($grupoTipo) {
                $stats[$grupoTipo]++;
                $stats['por_grupo_tipo'][$grupoTipo]++;
                $stats['por_tipo'][$tipoNormalizado] = ($stats['por_tipo'][$tipoNormalizado] ?? 0) + 1;
            }
        }

        return $stats;
    }

    private function calcularStatsBancoRolExamenParaGeneracion(RolExamen $examen): array
    {
        $grupoNormalizado = $this->normalizarGrupoBanco($examen->grupo);
        $partial = $this->obtenerParcialFuenteBanco($examen->tipo_examen);
        $asignaturaIds = DB::table('asignaturas')
            ->join('asignatura_carrera', 'asignaturas.id', '=', 'asignatura_carrera.asignatura_id')
            ->where('asignaturas.codigo', $examen->materia_codigo)
            ->where('asignatura_carrera.carrera_id', $examen->carrera_id)
            ->where('asignatura_carrera.sede_id', $examen->sede_id)
            ->where('asignaturas.estado', '!=', 'cancelado')
            ->pluck('asignaturas.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($asignaturaIds->isEmpty()) {
            return $this->statsBancoVacios();
        }

        $rows = DB::table('banco_preguntas')
            ->select('asignatura_id', 'docente_id', DB::raw('COUNT(*) as total'))
            ->whereIn('asignatura_id', $asignaturaIds->all())
            ->where('sede_id', $examen->sede_id)
            ->where('parcial', $partial)
            ->where(function ($query) use ($grupoNormalizado) {
                $query->whereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupoTeorico), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                    [$grupoNormalizado]
                )->orWhere(function ($legacy) use ($grupoNormalizado) {
                    $legacy->where(function ($emptyGrupoTeorico) {
                        $emptyGrupoTeorico->whereNull('grupoTeorico')
                            ->orWhere('grupoTeorico', '');
                    })->whereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupo), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                        [$grupoNormalizado]
                    );
                });
            })
            ->groupBy('asignatura_id', 'docente_id')
            ->orderByDesc('total')
            ->get();

        if ($rows->isEmpty()) {
            return $this->statsBancoVacios();
        }

        $row = $rows->first();

        return $this->calcularStatsBancoPorContexto(
            (int) $row->asignatura_id,
            $row->docente_id ? (int) $row->docente_id : null,
            (int) $examen->sede_id,
            $grupoNormalizado,
            $partial
        );
    }

    private function calcularStatsBancoPorContexto(
        int $asignaturaId,
        ?int $docenteId,
        int $sedeId,
        string $grupoNormalizado,
        string $partial
    ): array {
        $query = DB::table('banco_preguntas')
            ->where('asignatura_id', $asignaturaId)
            ->where('sede_id', $sedeId)
            ->where('parcial', $partial)
            ->when($docenteId, function ($query) use ($docenteId) {
                $query->where('docente_id', $docenteId);
            })
            ->where(function ($query) use ($grupoNormalizado) {
                $query->whereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupoTeorico), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                    [$grupoNormalizado]
                )->orWhere(function ($legacy) use ($grupoNormalizado) {
                    $legacy->where(function ($emptyGrupoTeorico) {
                        $emptyGrupoTeorico->whereNull('grupoTeorico')
                            ->orWhere('grupoTeorico', '');
                    })->whereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupo), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                        [$grupoNormalizado]
                    );
                });
            });

        return $this->calcularStatsDesdePreguntas($query->get(['tipo', 'dificultad']));
    }

    private function statsBancoVacios(): array
    {
        return [
            'total' => 0,
            'facil' => 0,
            'medio' => 0,
            'dificil' => 0,
            'g1' => 0,
            'g2' => 0,
            'g3' => 0,
            'por_tipo' => [],
            'por_grupo_tipo' => ['g1' => 0, 'g2' => 0, 'g3' => 0],
        ];
    }

    private function calcularStatsDesdePreguntas($preguntas): array
    {
        $stats = $this->statsBancoVacios();

        foreach ($preguntas as $pregunta) {
            $stats['total']++;

            $dificultad = $this->normalizarDificultadBanco($pregunta->dificultad);
            if (in_array($dificultad, ['FACIL', '1'], true)) {
                $stats['facil']++;
            } elseif (in_array($dificultad, ['MEDIA', 'MEDIO', '2'], true)) {
                $stats['medio']++;
            } elseif (in_array($dificultad, ['DIFICIL', '3'], true)) {
                $stats['dificil']++;
            }

            $tipoNormalizado = $this->normalizarTipoBanco($pregunta->tipo);
            $grupoTipo = $this->resolverGrupoTipoBanco($tipoNormalizado);
            if ($grupoTipo) {
                $stats[$grupoTipo]++;
                $stats['por_grupo_tipo'][$grupoTipo]++;
                $stats['por_tipo'][$tipoNormalizado] = ($stats['por_tipo'][$tipoNormalizado] ?? 0) + 1;
            }
        }

        return $stats;
    }

    private function normalizarDificultadBanco($valor): string
    {
        $texto = $this->normalizarTextoBanco($valor);
        $texto = strtr($texto, [
            'FµCIL' => 'FACIL',
            'FÁCIL' => 'FACIL',
            'INTERMEDIA' => 'MEDIA',
            'INTERMEDIO' => 'MEDIO',
            'DIFÖCIL' => 'DIFICIL',
            'DIFÍCIL' => 'DIFICIL',
        ]);

        return $texto;
    }

    private function normalizarTextoBanco($valor): string
    {
        $texto = mb_strtoupper(trim((string) $valor));
        $texto = strtr($texto, [
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
            'Ü' => 'U',
            'Ñ' => 'N',
        ]);

        return preg_replace('/\s+/', ' ', $texto) ?? $texto;
    }

    private function normalizarGrupoBanco($grupo): string
    {
        $grupo = $this->normalizarTextoBanco($grupo);

        return str_replace(['G. ', 'GRUPO ', 'G-', 'G'], '', $grupo);
    }

    private function normalizarTipoBanco($tipo): string
    {
        $tipo = $this->normalizarTextoBanco($tipo);
        $tipo = str_replace([' ', '-'], '_', $tipo);

        $mapping = [
            'FV' => 'FALSO_VERDADERO',
            'FALSO_VERDADERO' => 'FALSO_VERDADERO',
            'FALSO_O_VERDADERO' => 'FALSO_VERDADERO',
            'VERDADERO_O_FALSO' => 'FALSO_VERDADERO',
            'VERDADERO_O_FALSO_SIMPLE' => 'FALSO_VERDADERO',
            'SM' => 'RESPUESTA_COMPUESTA',
            'SELECCION_MULTIPLE' => 'RESPUESTA_COMPUESTA',
            'RESPUESTA_COMPUESTA' => 'RESPUESTA_COMPUESTA',
            'RESPUESTA_A/B/AMBAS/NINGUNA' => 'RESPUESTA_COMPUESTA',
            'PREGUNTA_CON_CLAVE' => 'PREGUNTA_CON_CLAVE',
            'VERDADERO_O_FALSO_COMPLEJAS' => 'PREGUNTA_CON_CLAVE',
            'SS' => 'SELECCION_SIMPLE',
            'SU' => 'SELECCION_SIMPLE',
            'SELECCION_UNICA' => 'SELECCION_SIMPLE',
            'SELECCION_SIMPLE' => 'SELECCION_SIMPLE',
            'SELECCION_DE_LA_MEJOR_RESPUESTA' => 'SELECCION_SIMPLE',
            'SP' => 'SUBPROBLEMA',
            'SUBPREGUNTA' => 'SUBPROBLEMA',
            'SUBITEM_DE_CASO_O_PROBLEMA' => 'SUBPROBLEMA',
            'SUBPROBLEMA' => 'SUBPROBLEMA',
            'SUB_PROBLEMA' => 'SUBPROBLEMA',
            'OPCION_EMPAREJAMIENTO' => 'OPCION_EMPAREJAMIENTO',
            'OPCION_DE_EMPAREJAMIENTO' => 'OPCION_EMPAREJAMIENTO',
            'OPCION_EMPAREJAMIENTO_AMPLIADO' => 'OPCION_EMPAREJAMIENTO',
            'OPCION_DE_EMPAREJAMIENTO_AMPLIADO' => 'OPCION_EMPAREJAMIENTO',
        ];

        return $mapping[$tipo] ?? $tipo;
    }

    private function resolverGrupoTipoBanco($tipo): ?string
    {
        if (in_array($tipo, ['FALSO_VERDADERO', 'PREGUNTA_CON_CLAVE', 'RESPUESTA_COMPUESTA'], true)) {
            return 'g1';
        }

        if ($tipo === 'SELECCION_SIMPLE') {
            return 'g2';
        }

        if (in_array($tipo, ['SUBPROBLEMA', 'OPCION_EMPAREJAMIENTO'], true)) {
            return 'g3';
        }

        return null;
    }

    /**
     * Obtener exámenes de una materia específica.
     */
    public function getByMateria(Request $request, $materiaId)
    {
        $gestion = $request->get('gestion', date('Y').'-I');

        $user = auth()->user();
        $query = RolExamen::where(function ($q) use ($materiaId) {
            $q->where('materia_codigo', $materiaId)
                ->orWhereRaw('UPPER(materia_codigo) = ?', [strtoupper($materiaId)]);
        })->where('gestion', $gestion);

        // Restricción por Sede para Directores y Autoridades
        if ($user && $user->rol && in_array($user->rol->codigo, ['DIRECTOR_CARRERA', 'VICERRECTORADO', 'VICERRECTOR_SEDE', 'DIRECCION_ACADEMICA', 'DIRECCIÓN ACADÉMICA'])) {
            $sedeId = $user->director?->sede_id ?? $user->docente?->sede_id ?? $user->sede_id;
            if ($sedeId) {
                $query->where('sede_id', $sedeId);
            }
        } elseif ($request->has('sede_id')) {
            $query->where('sede_id', $request->sede_id);
        }

        // Filtro por docente_id (mostrar solo los exámenes asignados a los grupos del docente)
        if ($request->has('docente_id') && ($user && $user->rol && $user->rol->codigo === 'DOCENTE')) {
            $docenteId = $request->docente_id;

            $gruposDocente = DB::table('grupos')
                ->join('asignaturas', 'grupos.asignatura_id', '=', 'asignaturas.id')
                ->where('grupos.docente_id', $docenteId)
                ->where('grupos.estado', 'ACTIVO')
                ->whereNull('grupos.deleted_at')
                ->where(function ($q) use ($materiaId) {
                    $q->where('asignaturas.codigo', $materiaId)
                        ->orWhereRaw('UPPER(asignaturas.codigo) = ?', [strtoupper($materiaId)]);
                })
                ->pluck('grupos.nombre')
                ->toArray();

            $query->where(function ($q) use ($gruposDocente) {
                $q->whereIn('grupo', $gruposDocente)
                    ->orWhereNull('grupo')
                    ->orWhere('grupo', '');
            });
        }

        $examenes = $query->orderBy('semana')->get();

        return response()->json([
            'data' => $examenes,
        ]);
    }

    /**
     * Subir Excel con rol de exámenes (bulk import)
     */
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls|max:5120',
            'carrera_id' => 'required|exists:carreras,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Archivo inválido', 'errors' => $validator->errors()], 422);
        }

        $gestion = $request->get('gestion', date('Y').'-I');
        $carreraId = $request->get('carrera_id');
        $sedeId = $request->get('sede_id');
        $grupoTeorico = $request->get('grupoTeorico'); // Opcional, por si se quiere asignar a todo

        $user = auth()->user();
        if ($user) {
            if ($user->rol && $user->rol->codigo === 'DIRECTOR_CARRERA') {
                // Priorizar sede_id del Director
                if ($user->director && $user->director->sede_id) {
                    $sedeId = $user->director->sede_id;
                } else {
                    $sedeId = $user->sede_id;
                }
                Log::info("Subida de RolExamen: Usando sede_id ({$sedeId}) del Director autenticado: {$user->username}");
            } elseif (! $sedeId) {
                // Fallback si no viene en el request
                if ($user->docente && $user->docente->sede_id) {
                    $sedeId = $user->docente->sede_id;
                } else {
                    $sedeId = $user->sede_id;
                }
                Log::info("Subida de RolExamen: Usando sede_id ({$sedeId}) por defecto del usuario: {$user->username}");
            }
        }

        if (! $sedeId) {
            $sedeId = 1; // Default fallback final si nada funciona
            Log::warning('Subida de RolExamen: No se pudo determinar sede_id, usando default 1.');
        }

        try {
            $file = $request->file('file');
            $spreadsheet = IOFactory::load($file->getPathname());

            $sheet = $spreadsheet->getSheetByName('ROL GENERAL');
            if (! $sheet) {
                $sheet = $spreadsheet->getActiveSheet();
            }

            // Obtener el año de la gestión desde la celda B7 (Fila 7, Columna B)
            $gestionAño = 2026; // Default fallback
            $celdaB7 = $sheet->getCell('B7')->getValue();
            if ($celdaB7 && preg_match('/\d{4}/', $celdaB7, $matches)) {
                $gestionAño = $matches[0];
            }

            $rows = $sheet->toArray();

            // Los registros inician en el registro 10 (indice 9)
            $rowsProcessed = array_slice($rows, 9);

            $imported = 0;
            $errors = [];
            $warnings = [];

            DB::beginTransaction();

            // Lógica de LIMPIEZA PREVIA (Cleanup)
            // Borrar exámenes existentes para esta gestión, carrera y sede antes de importar
            if ($sedeId) {
                RolExamen::where('gestion', $gestion)
                    ->where('carrera_id', $carreraId)
                    ->where('sede_id', $sedeId)
                    ->delete();
                Log::info("Limpieza de RolExamen completada para carrera {$carreraId}, sede {$sedeId}, gestión {$gestion}");
            }

            foreach ($rowsProcessed as $index => $row) {
                $rowNumber = $index + 10;

                // C: Código Materia (indice 2)
                $codigo = trim($row[2] ?? '');

                // B: Asignatura (indice 1)
                $nombreMateriaExcel = trim($row[1] ?? '');

                // E: Grupo (indice 4)
                $grupo = trim($row[4] ?? '');

                if (empty($codigo) || trim(strtoupper($codigo)) === '#REF!') {
                    continue;
                }

                // 1. Validar Materia
                // Buscar la materia asegurando que pertenezca a la carrera seleccionada para obtener su nombre correcto
                $asignaturaQuery = \App\Models\Asignatura::where('codigo', $codigo)
                    ->whereHas('carreras', function ($q) use ($carreraId, $sedeId) {
                        $q->where('asignatura_carrera.carrera_id', $carreraId)
                            ->where('asignatura_carrera.sede_id', $sedeId);
                    });

                if ((int) $sedeId === 1) {
                    $asignaturaQuery->where('plan_estudios', 'N');
                }

                $asignatura = $asignaturaQuery->first();

                // Fallback por si no está vinculada pero existe
                if (! $asignatura) {
                    $fallbackQuery = \App\Models\Asignatura::where('codigo', $codigo);
                    if ((int) $sedeId === 1) {
                        $fallbackQuery->where('plan_estudios', 'N');
                    }
                    $asignatura = $fallbackQuery->first();
                }

                if (! $asignatura) {
                    $errors[] = "Fila {$rowNumber}: No se encontró la materia con código '{$codigo}'";

                    continue;
                }

                // 2. Definir bloques de exámenes a procesar: [Tipo, FechaCol, HoraCol]
                $bloques = [
                    ['1er Parcial', 6, 7],   // G, H
                    ['2do Parcial', 8, 9],   // I, J
                    ['Final', 10, 11],        // K, L
                ];

                foreach ($bloques as $bloque) {
                    [$tipo, $fechaIdx, $horaIdx] = $bloque;

                    $fechaRaw = $row[$fechaIdx] ?? '';
                    $horaRaw = $row[$horaIdx] ?? '';

                    if (empty($fechaRaw) || $fechaRaw === 'A') {
                        continue;
                    }

                    try {
                        $fecha = $this->parseDate($fechaRaw, $gestionAño);
                        $horaInicio = $this->parseTime($horaRaw);
                        $horaFin = date('H:i', strtotime($horaInicio.' +90 minutes'));

                        if (! $fecha) {
                            continue;
                        }

                        // Automatización de semana por defecto
                        $semanasDefault = [
                            '1er Parcial' => 8,
                            '2do Parcial' => 15,
                            'Final' => 19,
                            '2da Instancia' => 22,
                        ];
                        $semana = $semanasDefault[$tipo] ?? 1;

                        // Validar reglas
                        $validation = $this->validateExamRules($carreraId, $codigo, $grupo, $semana, $fecha, $tipo);

                        if (! empty($validation['errors'])) {
                            $errors[] = "Fila {$rowNumber} - Materia {$codigo} ({$tipo}): ".implode(', ', $validation['errors']);

                            continue;
                        }

                        $conflictos = $validation['warnings'] ?? [];
                        $conflictosData = [];
                        foreach ($conflictos as $w) {
                            $wLower = mb_strtolower($w, 'UTF-8');
                            if (str_contains($wLower, 'semana')) {
                                $conflictosData['semana'] = $w;
                            }
                            // Detección insensible a acentos para "día" o "clase"
                            if (str_contains($wLower, 'clase') || str_contains($wLower, 'dia') || str_contains($wLower, 'día')) {
                                $conflictosData['horario'] = $w;
                            }
                        }

                        if (! empty($conflictos)) {
                            $warnings[] = "Fila {$rowNumber} - Materia {$codigo} ({$tipo}): ".implode(', ', $conflictos);
                        }

                        // Crear o actualizar
                        RolExamen::updateOrCreate(
                            [
                                'gestion' => $gestion,
                                'carrera_id' => $carreraId,
                                'materia_codigo' => $codigo,
                                'tipo_examen' => $tipo,
                                'grupo' => $grupo ?: null,
                                'sede_id' => $sedeId,
                            ],
                            [
                                'grupoTeorico' => $grupoTeorico ?: $grupo,
                                'materia_nombre' => ! empty($nombreMateriaExcel) ? $nombreMateriaExcel : $asignatura->nombre,
                                'semana' => $semana,
                                'fecha' => $fecha,
                                'hora_inicio' => $horaInicio,
                                'hora_fin' => $horaFin,
                                'created_by' => auth()->id(),
                                'conflictos' => ! empty($conflictosData) ? $conflictosData : null,
                            ]
                        );

                        $imported++;

                    } catch (\Exception $e) {
                        $errors[] = "Fila {$rowNumber} ({$tipo}): ".$e->getMessage();
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => "Se procesaron {$imported} registros de exámenes",
                'imported' => $imported,
                'errors' => $errors,
                'warnings' => $warnings,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error crítico procesando archivo: '.$e->getMessage(),
            ], 500);
        }
    }

    private function validateExamRules($carreraId, $codigo, $grupo, $semana, $fecha, $tipo)
    {
        $result = ['errors' => [], 'warnings' => []];

        // 1. Validar Semana vs Tipo (Error Blocking)
        $ranges = [
            '1er Parcial' => [7, 9],
            '2do Parcial' => [14, 16],
            'Final' => [18, 20],
            '2da Instancia' => [21, 25],
        ];

        if (isset($ranges[$tipo])) {
            [$min, $max] = $ranges[$tipo];
            if ($semana < $min || $semana > $max) {
                $result['warnings'][] = "Fuera de semana sugerida (Semanas {$min}-{$max})";
            }
        }

        // 2. Validar Dia de Clase (Warning Non-Blocking)
        // Solo si tenemos fecha y grupo
        if ($fecha && $grupo) {
            $asignatura = \App\Models\Asignatura::where('codigo', $codigo)
                ->whereHas('carreras', function ($q) use ($carreraId) {
                    $q->where('asignatura_carrera.carrera_id', $carreraId);
                })->first() ?? \App\Models\Asignatura::where('codigo', $codigo)->first();

            if ($asignatura) {
                // Buscar grupo por nombre vinculado a la asignatura
                // VALIDACION: Solo buscar en grupos TEORICOS (numerales)
                $grupoModel = $asignatura->grupos()
                    ->where('nombre', $grupo)
                    ->where('tipo', 'TEORICO')
                    ->first();

                if ($grupoModel) {
                    $diaExamen = date('N', strtotime($fecha)); // 1 (Mon) - 7 (Sun)

                    // Asumiendo que Horario tiene 'dia' (1-7 o string)
                    // Necesitamos verificar como se guarda 'dia' en Horario.
                    // Generalmente es 1-7 o 'LUNES', etc.
                    // Vamos a asumir 1-7 por ahora o verificar.

                    $diasClaseRaw = $grupoModel->horarios()->pluck('dia')->toArray(); // array of strings e.g. "Lunes", "Miercoles"

                    // Map keys to standard date('N') 1-7
                    $dayMap = [
                        'lunes' => 1,
                        'lun' => 1,
                        'martes' => 2,
                        'mar' => 2,
                        'miercoles' => 3,
                        'miércoles' => 3,
                        'mie' => 3,
                        'mié' => 3,
                        'jueves' => 4,
                        'jue' => 4,
                        'viernes' => 5,
                        'vie' => 5,
                        'sabado' => 6,
                        'sábado' => 6,
                        'sab' => 6,
                        'domingo' => 7,
                        'dom' => 7,
                    ];

                    $diasClase = [];
                    foreach ($diasClaseRaw as $dia) {
                        // Simple normalization
                        $key = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], strtolower($dia));
                        if (isset($dayMap[$key])) {
                            $diasClase[] = $dayMap[$key];
                        } elseif (is_numeric($dia)) {
                            $diasClase[] = (int) $dia;
                        }
                    }

                    if (! empty($diasClase) && ! in_array($diaExamen, $diasClase)) {
                        $nombresDias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
                        $diaNombre = $nombresDias[$diaExamen] ?? $diaExamen;
                        $result['warnings'][] = "El examen es el {$diaNombre}, pero el grupo no tiene horario teórico ese día.";
                    }
                }
            }
        }

        // 3. Validar Colisión de Exámenes (Mismo Semestre, Misma Carrera, Mismo Día)
        if ($fecha) {
            $asignatura = \App\Models\Asignatura::where('codigo', $codigo)
                ->whereHas('carreras', function ($q) use ($carreraId) {
                    $q->where('asignatura_carrera.carrera_id', $carreraId);
                })->first() ?? \App\Models\Asignatura::where('codigo', $codigo)->first();

            if ($asignatura) {
                // Obtener semestre via pivot table
                $pivot = \Illuminate\Support\Facades\DB::table('asignatura_carrera')
                    ->where('asignatura_id', $asignatura->id)
                    ->where('carrera_id', $carreraId)
                    ->first();

                $semestre = $pivot ? $pivot->semestre : null;

                if ($semestre) {
                    $collision = \App\Models\RolExamen::where('carrera_id', $carreraId)
                        ->whereDate('fecha', $fecha)
                        ->where('materia_codigo', '!=', $codigo) // Diferente materia
                        ->whereHas('asignatura', function ($q) use ($carreraId, $semestre) {
                            $q->whereHas('carreras', function ($cq) use ($carreraId, $semestre) {
                                $cq->where('carrera_id', $carreraId)
                                    ->where('semestre', $semestre);
                            });
                        })
                        ->exists();

                    if ($collision) {
                        $result['error'] = "Ya existe otro examen programado para el semestre {$semestre} en la fecha {$fecha}. (Restricción: Máx 1 examen por día para el mismo semestre)";

                        return $result;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Crear examen manualmente
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gestion' => 'required|string|max:20',
            'carrera_id' => 'required|exists:carreras,id',
            'materia_codigo' => 'required|string|max:50',
            'materia_nombre' => 'required|string|max:255',
            'tipo_examen' => 'required|in:1er Parcial,2do Parcial,Final,2da Instancia',
            'semana' => 'required|integer|min:1|max:25',
            'fecha' => 'required|date',
            'hora_inicio' => 'required',
            'hora_fin' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
        }

        $data = $request->all();
        $user = auth()->user();

        if ($user) {
            if ($user->rol && $user->rol->codigo === 'DIRECTOR_CARRERA') {
                if ($user->director && $user->director->sede_id) {
                    $data['sede_id'] = $user->director->sede_id;
                } else {
                    $data['sede_id'] = $user->sede_id;
                }
            } elseif (! isset($data['sede_id'])) {
                $data['sede_id'] = $user->sede_id ?: 1;
            }
        }

        $examen = RolExamen::create([
            ...$data,
            'created_by' => auth()->id(),
        ]);

        return response()->json($examen, 201);
    }

    public function update(Request $request, $id)
    {
        $examen = RolExamen::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'tipo_examen' => 'sometimes|in:1er Parcial,2do Parcial,Final,2da Instancia',
            'semana' => 'sometimes|integer|min:1|max:25',
            'fecha' => 'sometimes|date',
            'hora_inicio' => 'sometimes',
            'hora_fin' => 'sometimes',
            'grupo' => 'sometimes|string|max:50',
            'modalidad' => 'sometimes|in:PRESENCIAL_CON_CARTILLA,PRESENCIAL_SIN_CARTILLA,VIRTUAL',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
        }

        if ($response = $this->authorizeRolExamenAccess($examen)) {
            return $response;
        }

        $data = $request->all();
        $currentEstado = strtolower(trim((string) ($examen->estado ?: 'programados')));
        $targetEstado = strtolower(trim((string) ($data['estado'] ?? '')));
        $currentIsProgramado = in_array($currentEstado, ['programado', 'programados'], true);
        $isEditingExamDetails = collect([
            'tipo_examen',
            'semana',
            'fecha',
            'hora_inicio',
            'hora_fin',
            'grupo',
        ])->contains(fn ($field) => $request->has($field));

        if ($isEditingExamDetails && ! $currentIsProgramado) {
            return response()->json([
                'message' => 'Solo se pueden editar examenes que esten en estado PROGRAMADO.',
            ], 422);
        }

        $isResettingGeneratedExam = isset($data['estado'])
            && in_array($targetEstado, ['programado', 'programados'], true)
            && ! $currentIsProgramado;

        if ($isResettingGeneratedExam) {
            if ($response = $this->authorizeAdminRestore($examen)) {
                return $response;
            }

            $config = $examen->config_generacion ?? [];
            $resetBackup = $this->buildGenerationResetBackup($examen);

            if ($resetBackup) {
                $config['reset_backup'] = $resetBackup;
                $config['job_status'] = 'reset';
                $config['job_error'] = null;
                $config['reset_at'] = now()->toISOString();
                $config['reset_by'] = auth()->id();
                unset($config['pattern_audit']);

                $data['config_generacion'] = $config;
            } else {
                $data['config_generacion'] = null;
            }

            $data['variantes'] = [];
            $data['patrones'] = [];
        }

        $examen->update($data);

        return response()->json($examen);
    }

    public function restoreGeneratedPackage($id)
    {
        $examen = RolExamen::findOrFail($id);

        if ($response = $this->authorizeAdminRestore($examen)) {
            return $response;
        }

        if ($examen->estado !== 'programados') {
            return response()->json([
                'message' => 'Solo se puede restablecer un examen que fue retornado a PROGRAMADO.',
            ], 422);
        }

        $config = $examen->config_generacion ?? [];
        $backup = $config['reset_backup'] ?? $this->discoverGeneratedPackageForResetExam($examen);

        if (! $this->backupHasGeneratedFiles($backup)) {
            return response()->json([
                'message' => 'No se encontro una generacion previa disponible para restablecer.',
            ], 422);
        }

        $previousConfig = is_array($backup['config_generacion'] ?? null)
            ? $backup['config_generacion']
            : [];
        $timestamps = $examen->timestamps_proceso ?? [];
        $restoredAt = now()->toISOString();
        $estadoRestaurado = $backup['estado'] ?? 'generados';

        $previousConfig['job_status'] = 'completed';
        $previousConfig['job_error'] = null;
        $previousConfig['restored_from_reset_at'] = $restoredAt;
        $previousConfig['restored_from_reset_by'] = auth()->id();

        $timestamps['generacion_restaurada'] = $restoredAt;
        if (empty($timestamps[$estadoRestaurado])) {
            $timestamps[$estadoRestaurado] = $restoredAt;
        }

        $examen->update([
            'estado' => $estadoRestaurado,
            'variantes' => $backup['variantes'] ?? [],
            'patrones' => $backup['patrones'] ?? [],
            'config_generacion' => $previousConfig,
            'timestamps_proceso' => $timestamps,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Generacion restablecida correctamente.',
            'examen' => $examen->fresh(),
        ]);
    }

    public function generatePackage(Request $request, $id)
    {
        $examen = RolExamen::findOrFail($id);
        $tipoExamenNormalizado = $this->normalizarTipoExamen($examen->tipo_examen)
            ?? trim((string) $examen->tipo_examen);

        if ($examen->estado !== 'programados') {
            return response()->json([
                'message' => 'Solo se puede iniciar la generación desde el estado PROGRAMADO.',
            ], 422);
        }

        if (! in_array($tipoExamenNormalizado, ['2do Parcial', 'Final', '2da Instancia'], true)) {
            return response()->json([
                'message' => 'La generación asincrónica consolidada está habilitada solo para 2do Parcial, Examen Final y 2da Instancia.',
            ], 422);
        }

        if ($tipoExamenNormalizado === 'Final') {
            $statsBanco = $this->calcularStatsBancoRolExamenParaGeneracion($examen);
            if ((int) ($statsBanco['total'] ?? 0) < 120) {
                return response()->json([
                    'message' => 'El Examen Final requiere un minimo de 120 preguntas en el banco antes de generar.',
                    'total_detectado' => (int) ($statsBanco['total'] ?? 0),
                ], 422);
            }

            if (
                (int) ($statsBanco['facil'] ?? 0) < 30 ||
                (int) ($statsBanco['medio'] ?? 0) < 60 ||
                (int) ($statsBanco['dificil'] ?? 0) < 30
            ) {
                return response()->json([
                    'message' => 'El Examen Final requiere 30 faciles, 60 medias y 30 dificiles antes de generar.',
                    'stats_detectadas' => [
                        'facil' => (int) ($statsBanco['facil'] ?? 0),
                        'medio' => (int) ($statsBanco['medio'] ?? 0),
                        'dificil' => (int) ($statsBanco['dificil'] ?? 0),
                    ],
                ], 422);
            }
        }

        $request->validate([
            'cantVariantes' => 'required|integer|min:1|max:5',
            'facil' => 'required|integer|min:0',
            'medio' => 'required|integer|min:0',
            'dificil' => 'required|integer|min:0',
            'formatoHoja' => 'nullable|string|max:60',
            'fontFamily' => 'nullable|string|max:40',
            'fontSize' => 'nullable|numeric|min:8|max:20',
            'lineSpacing' => 'nullable|numeric|min:0.7|max:1.5',
            'aleatorizarSecciones' => 'nullable|boolean',
            'modalidad' => 'nullable|in:PRESENCIAL_CON_CARTILLA,PRESENCIAL_SIN_CARTILLA,VIRTUAL',
        ]);

        $config = array_merge($examen->config_generacion ?? [], $request->only([
            'cantVariantes',
            'facil',
            'medio',
            'dificil',
            'formatoHoja',
            'fontFamily',
            'fontSize',
            'lineSpacing',
            'aleatorizarSecciones',
            'modalidad',
        ]));

        $config['job_status'] = 'queued';
        $config['job_error'] = null;

        $timestamps = $examen->timestamps_proceso ?? [];
        $timestamps['generacion_solicitada'] = now()->toISOString();

        $examen->update([
            'config_generacion' => $config,
            'timestamps_proceso' => $timestamps,
            'modalidad' => $request->input('modalidad', $examen->modalidad ?? 'PRESENCIAL_CON_CARTILLA'),
        ]);

        GenerateRolExamenPackageJob::dispatch($examen->id, $config, auth()->id());

        return response()->json([
            'success' => true,
            'message' => 'La generación fue enviada a la cola.',
            'job_status' => 'queued',
        ], 202);
    }

    /**
     * Subir PDF de examen para una variante
     */
    public function uploadExamen(Request $request, $id)
    {
        $examen = RolExamen::findOrFail($id);

        $request->validate([
            'archivo' => 'required|file|mimes:pdf|max:5120',
            'variante' => 'required|string',
            'filename' => 'required|string',
        ]);

        $file = $request->file('archivo');
        $filename = $request->filename;

        $path = $file->storeAs('examenes', $filename, 'public');

        // Actualizar la columna 'variantes' (JSON)
        $variantes = $examen->variantes ?? [];

        // Si antes era un array de strings, normalizar a objetos
        if (count($variantes) > 0 && is_string($variantes[0])) {
            $variantes = array_map(fn ($v) => ['letra' => $v, 'archivo' => null], $variantes);
        }

        $letra = $request->variante;
        $found = false;
        foreach ($variantes as &$v) {
            if ($v['letra'] === $letra) {
                $v['archivo'] = $filename;
                $found = true;
            }
        }

        if (! $found) {
            $variantes[] = ['letra' => $letra, 'archivo' => $filename];
        }

        $examen->variantes = $variantes;
        $examen->save();

        $user = auth()->user();
        if ($user && $user->rol && $user->rol->codigo === 'DIRECTOR_CARRERA') {
            $sedeId = $user->director?->sede_id ?? $user->sede_id;
            if ($sedeId && $examen->sede_id != $sedeId) {
                return response()->json(['message' => 'No tiene permiso para subir archivos a este examen de otra sede'], 403);
            }
        }

        return response()->json([
            'success' => true,
            'url' => asset('storage/'.$path),
            'examen' => $examen,
        ]);
    }

    /**
     * Subir patrón PDF o XLSX para una variante
     */
    public function uploadPatron(Request $request, $id)
    {
        $examen = RolExamen::findOrFail($id);

        $user = auth()->user();
        if ($user && $user->rol && $user->rol->codigo === 'DIRECTOR_CARRERA') {
            $sedeId = $user->director?->sede_id ?? $user->sede_id;
            if ($sedeId && $examen->sede_id != $sedeId) {
                return response()->json(['message' => 'No tiene permiso para subir archivos a este examen de otra sede'], 403);
            }
        }

        $request->validate([
            'archivo' => 'required|file|max:5120',
            'variante' => 'required|string',
            'tipo' => 'required|in:pdf,xlsx',
            'filename' => 'required|string',
        ]);

        $file = $request->file('archivo');
        $filename = $request->filename;

        $path = $file->storeAs('patrones', $filename, 'public');

        // Actualizar la columna 'patrones' (JSON)
        $patrones = $examen->patrones ?? [];

        // Si antes era un array de strings, normalizar a objetos
        if (count($patrones) > 0 && is_string($patrones[0])) {
            $patrones = array_map(fn ($p) => ['letra' => $p, 'pdf' => null, 'xlsx' => null], $patrones);
        }

        $letra = $request->variante;
        $tipo = $request->tipo;
        $found = false;
        foreach ($patrones as &$p) {
            if ($p['letra'] === $letra) {
                $p[$tipo] = $filename;
                $found = true;
            }
        }

        if (! $found) {
            $patrones[] = [
                'letra' => $letra,
                'pdf' => ($tipo === 'pdf' ? $filename : null),
                'xlsx' => ($tipo === 'xlsx' ? $filename : null),
            ];
        }

        $examen->patrones = $patrones;
        $examen->save();

        return response()->json([
            'success' => true,
            'url' => asset('storage/'.$path),
            'examen' => $examen,
        ]);
    }

    /**
     * Eliminar examen
     */
    public function destroy($id)
    {
        $examen = RolExamen::findOrFail($id);

        if ($response = $this->authorizeRolExamenAccess($examen)) {
            return $response;
        }

        $examen->delete();

        return response()->json(['message' => 'Examen eliminado']);
    }

    public function downloadExamen($id, Request $request)
    {
        if ($blocked = $this->denyVisualizadorDocumentAccess()) {
            return $blocked;
        }

        $examen = RolExamen::findOrFail($id);
        $filename = $request->query('file');

        if (! $filename) {
            return response()->json(['message' => 'Archivo no especificado'], 422);
        }

        $variant = collect($examen->variantes ?? [])->first(function ($item) use ($filename) {
            return is_array($item) && ($item['archivo'] ?? null) === $filename;
        });

        if (! $variant) {
            return response()->json(['message' => 'Archivo no registrado para este examen'], 404);
        }

        return $this->downloadManagedFile($variant['path'] ?? null, $variant['archivo'] ?? null, 'examenes');
    }

    public function signedExamenUrl($id, Request $request)
    {
        if ($blocked = $this->denyVisualizadorDocumentAccess()) {
            return $blocked;
        }

        $examen = RolExamen::findOrFail($id);
        $filename = $request->query('file');

        if (! $filename) {
            return response()->json(['message' => 'Archivo no especificado'], 422);
        }

        $variant = $this->findRegisteredVariant($examen, $filename);

        if (! $variant) {
            return response()->json(['message' => 'Archivo no registrado para este examen'], 404);
        }

        return response()->json([
            'url' => URL::temporarySignedRoute(
                'rol-examenes.preview-examen',
                now()->addMinutes(10),
                ['id' => $examen->id, 'filename' => $filename],
            ),
        ]);
    }

    public function previewExamen($id, string $filename)
    {
        if ($blocked = $this->denyVisualizadorDocumentAccess()) {
            return $blocked;
        }

        $examen = RolExamen::findOrFail($id);
        $variant = $this->findRegisteredVariant($examen, $filename);

        if (! $variant) {
            return response()->json(['message' => 'Archivo no registrado para este examen'], 404);
        }

        return $this->respondManagedFile(
            $variant['path'] ?? null,
            $variant['archivo'] ?? $filename,
            'examenes',
            'inline',
        );
    }

    public function downloadPatron($id, Request $request)
    {
        if ($blocked = $this->denyVisualizadorDocumentAccess()) {
            return $blocked;
        }

        $examen = RolExamen::findOrFail($id);
        $filename = $request->query('file');
        $tipo = $request->query('tipo');

        if (! $filename || ! in_array($tipo, ['pdf', 'xlsx'], true)) {
            return response()->json(['message' => 'Parámetros inválidos'], 422);
        }

        $pattern = collect($examen->patrones ?? [])->first(function ($item) use ($filename, $tipo) {
            return is_array($item) && ($item[$tipo] ?? null) === $filename;
        });

        if (! $pattern) {
            return response()->json(['message' => 'Archivo no registrado para este examen'], 404);
        }

        $managedPath = $tipo === 'pdf'
            ? ($pattern['pdf_path'] ?? null)
            : ($pattern['xlsx_path'] ?? null);

        return $this->downloadManagedFile($managedPath, $filename, 'patrones');
    }

    public function signedPatronUrl($id, Request $request)
    {
        if ($blocked = $this->denyVisualizadorDocumentAccess()) {
            return $blocked;
        }

        $examen = RolExamen::findOrFail($id);
        $filename = $request->query('file');
        $tipo = $request->query('tipo');

        if (! $filename || ! in_array($tipo, ['pdf', 'xlsx'], true)) {
            return response()->json(['message' => 'Parámetros inválidos'], 422);
        }

        $pattern = $this->findRegisteredPattern($examen, $filename, $tipo);

        if (! $pattern) {
            return response()->json(['message' => 'Archivo no registrado para este examen'], 404);
        }

        return response()->json([
            'url' => URL::temporarySignedRoute(
                'rol-examenes.preview-patron',
                now()->addMinutes(10),
                ['id' => $examen->id, 'tipo' => $tipo, 'filename' => $filename],
            ),
        ]);
    }

    public function previewPatron($id, string $tipo, string $filename)
    {
        if ($blocked = $this->denyVisualizadorDocumentAccess()) {
            return $blocked;
        }

        $examen = RolExamen::findOrFail($id);

        if (! in_array($tipo, ['pdf', 'xlsx'], true)) {
            return response()->json(['message' => 'Parámetros inválidos'], 422);
        }

        $pattern = $this->findRegisteredPattern($examen, $filename, $tipo);

        if (! $pattern) {
            return response()->json(['message' => 'Archivo no registrado para este examen'], 404);
        }

        $managedPath = $tipo === 'pdf'
            ? ($pattern['pdf_path'] ?? null)
            : ($pattern['xlsx_path'] ?? null);

        return $this->respondManagedFile(
            $managedPath,
            $pattern[$tipo] ?? $filename,
            'patrones',
            $tipo === 'pdf' ? 'inline' : 'attachment',
        );
    }

    public function patternVerifier(Request $request, $id)
    {
        $examen = RolExamen::findOrFail($id);

        if ($response = $this->authorizeRolExamenAccess($examen)) {
            return $response;
        }

        $allowedStatuses = [
            'generado',
            'generados',
            'impreso',
            'impresos',
            'entregado',
            'entregados',
            'devuelto',
            'devueltos',
            'revisado',
            'revisados',
            'subido',
            'subidos',
        ];

        if (! in_array(strtolower(trim((string) $examen->estado)), $allowedStatuses, true)) {
            return response()->json([
                'message' => 'El verificador de patrones solo est\u00e1 disponible desde la etapa Generado en adelante.',
            ], 422);
        }

        $request->validate([
            'archivo' => 'required|file|mimes:pdf|max:20480',
        ]);

        $variants = $this->resolvePatternVariants($examen);

        if (empty($variants)) {
            return response()->json([
                'message' => 'No se encontraron patrones registrados para este examen.',
            ], 404);
        }

        $uploadedFile = $request->file('archivo');
        $verification = $this->verifyPatternAgainstUploadedPdf($examen, $uploadedFile->getRealPath(), $variants);
        $variants = $verification['variants'];
        $registeredExamNames = collect($examen->variantes ?? [])
            ->map(function ($item) {
                if (is_array($item)) {
                    return $item['archivo'] ?? null;
                }

                return is_string($item) ? $item : null;
            })
            ->filter()
            ->unique()
            ->values();

        $patternDownloads = collect($examen->patrones ?? [])->first(fn ($item) => is_array($item)) ?? [];

        return response()->json([
            'exam' => [
                'id' => $examen->id,
                'codigo' => $examen->materia_codigo,
                'materia' => $examen->materia_nombre,
                'grupo' => $examen->grupo,
                'parcial' => $examen->tipo_examen,
                'estado' => $examen->estado,
                'fecha' => optional($examen->fecha)?->format('Y-m-d'),
                'sede_id' => $examen->sede_id,
                'carrera_id' => $examen->carrera_id,
            ],
            'uploaded_file' => [
                'name' => $uploadedFile->getClientOriginalName(),
                'size' => $uploadedFile->getSize(),
                'matches_registered_name' => $registeredExamNames->contains($uploadedFile->getClientOriginalName()),
                'registered_names' => $registeredExamNames->all(),
            ],
            'downloads' => [
                'exam' => $registeredExamNames->first(),
                'patron_pdf' => $patternDownloads['pdf'] ?? null,
                'patron_xlsx' => $patternDownloads['xlsx'] ?? null,
            ],
            'verification' => $verification['summary'],
            'variants' => $variants,
        ]);
    }

    private function downloadManagedFile(?string $managedPath, ?string $filename, string $publicDir)
    {
        return $this->respondManagedFile($managedPath, $filename, $publicDir, 'attachment');
    }

    private function respondManagedFile(?string $managedPath, ?string $filename, string $publicDir, string $disposition)
    {
        $absolutePath = $this->resolveManagedAbsolutePath($managedPath, $filename, $publicDir);

        if (! $absolutePath) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }

        if ($disposition === 'inline') {
            return response()->file($absolutePath, [
                'Content-Type' => $this->guessFileMimeType($filename),
                'Content-Disposition' => $this->buildContentDisposition('inline', $filename ?: basename($absolutePath)),
            ]);
        }

        return response()->download($absolutePath, $filename ?: basename($absolutePath));
    }

    private function guessFileMimeType(?string $filename): string
    {
        return strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION)) === 'pdf'
            ? 'application/pdf'
            : 'application/octet-stream';
    }

    private function buildContentDisposition(string $disposition, string $filename): string
    {
        $asciiFilename = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?: 'archivo.pdf';

        return sprintf(
            "%s; filename=\"%s\"; filename*=UTF-8''%s",
            $disposition,
            str_replace('"', '', $asciiFilename),
            rawurlencode($filename),
        );
    }

    private function findRegisteredVariant(RolExamen $examen, string $filename): ?array
    {
        return collect($examen->variantes ?? [])->first(function ($item) use ($filename) {
            return is_array($item) && ($item['archivo'] ?? null) === $filename;
        });
    }

    private function findRegisteredPattern(RolExamen $examen, string $filename, string $tipo): ?array
    {
        return collect($examen->patrones ?? [])->first(function ($item) use ($filename, $tipo) {
            return is_array($item) && ($item[$tipo] ?? null) === $filename;
        });
    }

    private function aplicarAlcanceSedesAsignadas($query, $user, Request $request, string $contexto): void
    {
        $sedeIds = $this->sedeIdsAsignadasUsuario($user);
        $requestedSedeId = $request->filled('sede_id') ? (int) $request->sede_id : null;

        if ($requestedSedeId && ! $sedeIds->contains($requestedSedeId)) {
            $query->whereRaw('1 = 0');
            Log::info(
                "{$contexto} intento filtrar una sede no asignada",
                ['user_id' => $user->id, 'sede_id' => $requestedSedeId]
            );
            return;
        }

        if ($requestedSedeId) {
            $query->where('rol_examenes.sede_id', $requestedSedeId);
            return;
        }

        if ($sedeIds->isNotEmpty()) {
            $query->whereIn('rol_examenes.sede_id', $sedeIds->all());
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function sedeIdsAsignadasUsuario($user)
    {
        $sedeIds = collect([$user->sede_id])->filter();

        if (Schema::hasTable('campus_user')) {
            $campusIds = DB::table('campus_user')
                ->where('user_id', $user->id)
                ->pluck('campus_id');

            if ($campusIds->isNotEmpty() && Schema::hasTable('campus')) {
                $sedeIds = $sedeIds->merge(
                    DB::table('campus')
                        ->whereIn('id', $campusIds)
                        ->pluck('sede_id')
                );
            }
        }

        return $sedeIds->map(fn ($id) => (int) $id)->filter()->unique()->values();
    }

    private function denyVisualizadorDocumentAccess()
    {
        $role = auth()->user()?->rol?->codigo;

        if (in_array($role, self::VISUALIZADOR_EVALUACIONES_ROLES, true)) {
            return response()->json([
                'message' => 'Este rol solo puede visualizar el seguimiento de evaluaciones, sin acceso a documentos.',
            ], 403);
        }

        return null;
    }

    private function authorizeRolExamenAccess(RolExamen $examen)
    {
        $user = auth()->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        if ($user->rol && $user->rol->codigo === 'DIRECTOR_CARRERA') {
            $allowedSedeIds = $this->directorAllowedSedeIds($user);
            if (! empty($allowedSedeIds) && ! in_array((int) $examen->sede_id, $allowedSedeIds, true)) {
                return response()->json(['message' => 'No tiene permiso para acceder a este examen'], 403);
            }

            $allowedCareerIds = $this->directorAllowedCareerIds($user);
            if (! empty($allowedCareerIds) && ! in_array((int) $examen->carrera_id, $allowedCareerIds, true)) {
                return response()->json(['message' => 'No tiene permiso para acceder a este examen'], 403);
            }
        }

        return null;
    }

    private function directorAllowedSedeIds($user): array
    {
        $sedeIds = collect([
            $user->director?->sede_id,
            $user->docente?->sede_id,
            $user->sede_id,
        ]);

        if ($user->campus_id && Schema::hasTable('campus')) {
            $sedeIds->push(DB::table('campus')->where('id', $user->campus_id)->value('sede_id'));
        }

        if (Schema::hasTable('campus_user')) {
            $campusIds = DB::table('campus_user')
                ->where('user_id', $user->id)
                ->pluck('campus_id')
                ->filter();

            if ($campusIds->isNotEmpty() && Schema::hasTable('campus')) {
                $sedeIds = $sedeIds->merge(
                    DB::table('campus')->whereIn('id', $campusIds)->pluck('sede_id')
                );
            }
        }

        return $sedeIds->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }

    private function directorAllowedCareerIds($user): array
    {
        $careerIds = collect();

        if ($user->director) {
            $careerIds->push($user->director->carrera_id);
            $careerIds = $careerIds->merge(
                $user->director->carreras()->pluck('carrera_id')
            );
        }

        return $careerIds->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }

    private function authorizeAdminRestore(RolExamen $examen)
    {
        $user = auth()->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $role = $user->rol?->codigo;
        if (! in_array($role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return response()->json(['message' => 'Solo administradores pueden restablecer una generacion.'], 403);
        }

        return null;
    }

    private function buildGenerationResetBackup(RolExamen $examen): ?array
    {
        $variantes = array_values(array_filter($examen->variantes ?? [], 'is_array'));
        $patrones = array_values(array_filter($examen->patrones ?? [], 'is_array'));
        $config = $examen->config_generacion ?? [];

        if (empty($variantes) && empty($patrones)) {
            return null;
        }

        return [
            'estado' => $examen->estado ?: 'generados',
            'variantes' => $variantes,
            'patrones' => $patrones,
            'config_generacion' => $config,
            'timestamps_proceso' => $examen->timestamps_proceso ?? [],
            'reset_at' => now()->toISOString(),
            'reset_by' => auth()->id(),
        ];
    }

    private function canRestoreGeneratedPackage(RolExamen $examen): bool
    {
        if ($examen->estado !== 'programados') {
            return false;
        }

        $backup = ($examen->config_generacion ?? [])['reset_backup'] ?? null;

        return $this->backupHasGeneratedFiles($backup)
            || $this->backupHasGeneratedFiles($this->discoverGeneratedPackageForResetExam($examen));
    }

    private function discoverGeneratedPackageForResetExam(RolExamen $examen): ?array
    {
        $timestamps = $examen->timestamps_proceso ?? [];
        if (
            $examen->estado !== 'programados'
            || (empty($timestamps['generados']) && empty($timestamps['generacion_completada']))
        ) {
            return null;
        }

        $sede = DB::table('sedes')->where('id', $examen->sede_id)->value('nombre') ?: '';
        $prefix = implode('_', [
            $this->filenameToken($examen->materia_codigo ?: 'EXAM'),
            $this->filenameToken($sede),
            'G'.$this->filenameToken($examen->grupo ?: '1'),
            $this->filenameToken($examen->tipo_examen ?: ''),
        ]);

        $exam = $this->findGeneratedFileCandidate('examenes', $prefix, '_Examen.pdf');
        $patternPdf = $this->findGeneratedFileCandidate('patrones', $prefix, '_Patron.pdf');
        $patternXlsx = $this->findGeneratedFileCandidate('patrones', $prefix, '_Remark.xlsx');
        $referenceName = $exam['archivo'] ?? $patternPdf['archivo'] ?? $patternXlsx['archivo'] ?? null;

        if (! $referenceName) {
            return null;
        }

        $letters = $this->extractVariantLetters($referenceName);
        if (empty($letters)) {
            $letters = ['A'];
        }

        $variants = $exam
            ? collect($letters)->map(fn ($letter) => [
                'letra' => $letter,
                'archivo' => $exam['archivo'],
                'path' => $exam['path'],
            ])->values()->all()
            : [];

        $patterns = collect($letters)->map(fn ($letter) => [
            'letra' => $letter,
            'pdf' => $patternPdf['archivo'] ?? null,
            'pdf_path' => $patternPdf['path'] ?? null,
            'xlsx' => $patternXlsx['archivo'] ?? null,
            'xlsx_path' => $patternXlsx['path'] ?? null,
        ])->values()->all();

        return [
            'estado' => 'generados',
            'variantes' => $variants,
            'patrones' => $patterns,
            'config_generacion' => $examen->config_generacion ?? [],
            'timestamps_proceso' => $examen->timestamps_proceso ?? [],
            'reset_at' => now()->toISOString(),
            'reset_by' => auth()->id(),
            'source' => 'storage_scan',
        ];
    }

    private function filenameToken(?string $value): string
    {
        return preg_replace('/\s+/', '', trim((string) $value));
    }

    private function findGeneratedFileCandidate(string $publicDir, string $prefix, string $suffix): ?array
    {
        $locations = [
            ['base' => storage_path('app/tmp/'.$publicDir), 'managed_prefix' => 'tmp/'.$publicDir.'/'],
            ['base' => storage_path('app/public/'.$publicDir), 'managed_prefix' => null],
        ];

        foreach ($locations as $location) {
            $pattern = rtrim($location['base'], DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$prefix.'_Var*'.$suffix;
            $matches = glob($pattern) ?: [];
            rsort($matches);

            foreach ($matches as $absolutePath) {
                if (! is_file($absolutePath)) {
                    continue;
                }

                $filename = basename($absolutePath);

                return [
                    'archivo' => $filename,
                    'path' => $location['managed_prefix'] ? $location['managed_prefix'].$filename : null,
                ];
            }
        }

        return null;
    }

    private function extractVariantLetters(string $filename): array
    {
        if (! preg_match('/_Var([A-Z]+)/i', $filename, $matches)) {
            return [];
        }

        return str_split(strtoupper($matches[1]));
    }

    private function backupHasGeneratedFiles($backup): bool
    {
        if (! is_array($backup)) {
            return false;
        }

        $variants = collect($backup['variantes'] ?? [])->filter(function ($item) {
            return is_array($item)
                && ! empty($item['archivo'])
                && $this->resolveManagedAbsolutePath($item['path'] ?? null, $item['archivo'], 'examenes');
        });

        $patterns = collect($backup['patrones'] ?? [])->filter(function ($item) {
            if (! is_array($item)) {
                return false;
            }

            $hasPdf = ! empty($item['pdf'])
                && $this->resolveManagedAbsolutePath($item['pdf_path'] ?? null, $item['pdf'], 'patrones');
            $hasXlsx = ! empty($item['xlsx'])
                && $this->resolveManagedAbsolutePath($item['xlsx_path'] ?? null, $item['xlsx'], 'patrones');

            return $hasPdf || $hasXlsx;
        });

        return $variants->isNotEmpty() || $patterns->isNotEmpty();
    }

    private function deleteManagedFile(?string $managedPath, ?string $filename, string $publicDir): void
    {
        if ($managedPath) {
            $absolutePath = storage_path('app/'.ltrim($managedPath, '/'));
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }

        if ($filename) {
            Storage::disk('public')->delete($publicDir.'/'.$filename);
        }
    }

    private function verifyPatternAgainstUploadedPdf(RolExamen $examen, string $pdfPath, array $patternVariants): array
    {
        $bankQuestions = $this->loadBankQuestionsForPatternVerification($examen);
        $pdfText = $this->extractPdfText($pdfPath);
        $pdfVariants = $this->parsePdfVariants($pdfText);

        $summary = [
            'mode' => 'pdf_vs_banco',
            'bank_questions' => $bankQuestions->count(),
            'pdf_variants_detected' => count($pdfVariants),
            'matched' => 0,
            'correct' => 0,
            'mismatched' => 0,
            'unmatched' => 0,
            'without_pattern' => 0,
        ];

        $verifiedVariants = collect($patternVariants)->map(function ($variant) use ($bankQuestions, $pdfVariants, &$summary) {
            $letter = (string) ($variant['letra'] ?? '');
            $answers = $variant['answers'] ?? [];
            $pdfQuestions = $pdfVariants[$letter] ?? [];
            $usedQuestionIds = [];
            $registeredQuestions = collect($variant['questions'] ?? [])
                ->filter(fn ($item) => is_array($item))
                ->keyBy(fn ($item) => (int) ($item['number'] ?? 0));

            $checks = collect(range(1, 100))->map(function ($number) use (
                $answers,
                $pdfQuestions,
                $bankQuestions,
                $registeredQuestions,
                &$usedQuestionIds,
                &$summary
            ) {
                $patternAnswer = trim((string) ($answers[$number - 1] ?? ''));
                $pdfBlock = $pdfQuestions[$number] ?? null;
                $registeredQuestion = $this->formatStoredVerifierQuestion(
                    $registeredQuestions->get($number),
                    $number,
                    $patternAnswer
                );

                if (! $patternAnswer && ! $pdfBlock) {
                    return [
                        'number' => $number,
                        'answer' => '',
                        'status' => 'empty',
                        'match_score' => null,
                        'expected_answer' => '',
                        'question' => null,
                    ];
                }

                if (! $patternAnswer) {
                    $summary['without_pattern']++;
                }

                if (! $pdfBlock) {
                    $summary['unmatched']++;

                    return [
                        'number' => $number,
                        'answer' => $patternAnswer,
                        'status' => 'not_found_in_pdf',
                        'match_score' => null,
                        'expected_answer' => '',
                        'question' => $registeredQuestion,
                    ];
                }

                $match = $this->findBestBankQuestionMatch($pdfBlock, $bankQuestions, $usedQuestionIds);

                if (! $match) {
                    $summary['unmatched']++;

                    return [
                        'number' => $number,
                        'answer' => $patternAnswer,
                        'status' => 'unmatched',
                        'match_score' => null,
                        'expected_answer' => '',
                        'question' => $registeredQuestion
                            ? array_merge($registeredQuestion, ['pdf_text' => $pdfBlock])
                            : [
                                'number' => $number,
                                'enunciado' => $pdfBlock,
                                'tipo' => 'No identificado',
                                'source' => 'pdf',
                            ],
                    ];
                }

                $question = $match['question'];
                $usedQuestionIds[] = (int) $question->id;
                $expectedAnswer = $this->deriveExpectedAnswerFromPdfBlock($question, $pdfBlock);
                $status = $this->answersMatch($patternAnswer, $expectedAnswer) ? 'correct' : 'mismatch';

                $summary['matched']++;
                if ($status === 'correct') {
                    $summary['correct']++;
                } else {
                    $summary['mismatched']++;
                }

                return [
                    'number' => $number,
                    'answer' => $patternAnswer,
                    'status' => $status,
                    'match_score' => $match['score'],
                    'expected_answer' => $expectedAnswer,
                    'question' => $this->formatBancoQuestionForVerifier($question, $number, $expectedAnswer, $pdfBlock),
                ];
            })->values()->all();

            $answeredChecks = collect($checks)->filter(fn ($item) => ($item['status'] ?? '') !== 'empty')->values();

            return array_merge($variant, [
                'source' => 'pdf_vs_banco',
                'questions' => $answeredChecks->all(),
                'answered_count' => $answeredChecks->count(),
                'verification' => [
                    'correct' => $answeredChecks->where('status', 'correct')->count(),
                    'mismatched' => $answeredChecks->where('status', 'mismatch')->count(),
                    'unmatched' => $answeredChecks
                        ->filter(fn ($item) => in_array($item['status'], ['unmatched', 'not_found_in_pdf'], true))
                        ->count(),
                ],
            ]);
        })->values()->all();

        return [
            'summary' => $summary,
            'variants' => $verifiedVariants,
        ];
    }

    private function extractPdfText(string $pdfPath): string
    {
        $parser = new PdfParser;
        $pdf = $parser->parseFile($pdfPath);

        return preg_replace('/[ \t]+/', ' ', str_replace("\r", "\n", $pdf->getText())) ?? '';
    }

    private function parsePdfVariants(string $text): array
    {
        $lines = $this->normalizePdfLines($text);
        $segments = [];
        $currentLetter = null;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (preg_match('/TIPO\s+DE\s+EXAMEN:.*VAR\s*([A-E])?\s*$/i', $line, $match)) {
                $letter = strtoupper((string) ($match[1] ?? ''));

                if ($letter === '') {
                    $nextIndex = $i + 1;
                    while ($nextIndex < count($lines) && trim($lines[$nextIndex]) === '') {
                        $nextIndex++;
                    }

                    if ($nextIndex < count($lines) && preg_match('/^[A-E]$/i', trim($lines[$nextIndex]))) {
                        $letter = strtoupper(trim($lines[$nextIndex]));
                        $i = $nextIndex;
                    }
                }

                if ($letter !== '') {
                    $currentLetter = $letter;
                    $segments[$currentLetter] = [];

                    continue;
                }
            }

            if ($currentLetter) {
                $segments[$currentLetter][] = $line;
            }
        }

        if (empty($segments)) {
            return ['A' => $this->parseQuestionBlocks($lines)];
        }

        return collect($segments)
            ->map(fn ($segmentLines) => $this->parseQuestionBlocks($segmentLines))
            ->filter(fn ($questions) => ! empty($questions))
            ->all();
    }

    private function parseQuestionBlocks(string|array $text): array
    {
        $lines = is_array($text) ? $text : $this->normalizePdfLines($text);
        $questions = [];
        $currentNumber = null;
        $currentLines = [];
        $section = '';
        $expectedNumber = 1;

        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line) ?? $line);
            if ($line === '') {
                continue;
            }

            if ($this->isPdfBoilerplateLine($line)) {
                continue;
            }

            $section = $this->detectPdfQuestionSection($line, $section);

            if (preg_match('/^(\d{1,3})\.\s+(.*)$/u', $line, $match)) {
                $number = (int) $match[1];
                $rest = trim($match[2] ?? '');

                if ($this->isPrimaryPdfQuestionLine($number, $expectedNumber, $rest, $section)) {
                    if ($currentNumber !== null && ! empty($currentLines)) {
                        $questions[$currentNumber] = trim(implode(' ', $currentLines));
                    }

                    $currentNumber = $number;
                    $currentLines = [$rest];
                    $expectedNumber = $number + 1;

                    continue;
                }
            }

            if ($currentNumber !== null) {
                $currentLines[] = $line;
            }
        }

        if ($currentNumber !== null && ! empty($currentLines)) {
            $questions[$currentNumber] = trim(implode(' ', $currentLines));
        }

        return $questions;
    }

    private function isPdfBoilerplateLine(string $line): bool
    {
        $normalized = $this->normalizeComparableText($line);

        return $normalized === ''
            || str_starts_with($normalized, 'universidad tecnica privada cosmos')
            || str_starts_with($normalized, 'gestion ')
            || str_starts_with($normalized, 'evaluacion teorica')
            || str_starts_with($normalized, 'nombre codigo')
            || str_starts_with($normalized, 'carrera ')
            || str_starts_with($normalized, 'docente ')
            || str_starts_with($normalized, 'materia ')
            || str_starts_with($normalized, 'semestre ')
            || str_starts_with($normalized, 'importante ')
            || str_starts_with($normalized, 'pagina en blanco');
    }

    private function normalizePdfLines(string $text): array
    {
        $text = str_replace("\r", "\n", $text);

        return collect(preg_split('/\n+/', $text) ?: [])
            ->map(fn ($line) => trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line))
            ->filter(fn ($line) => $line !== '')
            ->values()
            ->all();
    }

    private function detectPdfQuestionSection(string $line, string $current): string
    {
        $normalized = $this->normalizeComparableText($line);

        if (str_contains($normalized, 'verdadero o falso complejas')) {
            return 'complex';
        }

        if (str_contains($normalized, 'respuesta a b ambas ninguna')) {
            return 'complex';
        }

        if (str_contains($normalized, 'verdadero o falso simple')) {
            return 'complex';
        }

        if (str_contains($normalized, 'emparejamiento ampliado')) {
            return 'matching';
        }

        if (str_contains($normalized, 'seleccion de la mejor respuesta')) {
            return 'selection';
        }

        if (str_contains($normalized, 'items agrupados') || str_contains($normalized, 'caso clinico')) {
            return 'selection';
        }

        return $current;
    }

    private function isPrimaryPdfQuestionLine(int $number, int $expectedNumber, string $text, string $section): bool
    {
        if ($number !== $expectedNumber || $number < 1 || $number > 100) {
            return false;
        }

        if ($section === 'complex') {
            return str_contains($text, '____');
        }

        return true;
    }

    private function loadBankQuestionsForPatternVerification(RolExamen $examen)
    {
        $asignaturaIds = $this->resolveRolExamenAsignaturaIds($examen);
        $normalizedGroup = $this->normalizeVerifierGroup($examen->grupo);
        $partial = $this->normalizeVerifierPartial($examen->tipo_examen);

        return BancoPregunta::query()
            ->whereIn('asignatura_id', $asignaturaIds)
            ->where('parcial', $partial)
            ->where(function ($query) use ($normalizedGroup) {
                $query->whereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupoTeorico), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                    [$normalizedGroup]
                )->orWhere(function ($legacy) use ($normalizedGroup) {
                    $legacy->where(function ($emptyGrupoTeorico) {
                        $emptyGrupoTeorico->whereNull('grupoTeorico')
                            ->orWhere('grupoTeorico', '');
                    })->whereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupo), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                        [$normalizedGroup]
                    );
                });
            })
            ->get()
            ->filter(fn (BancoPregunta $question) => ! $this->isMacroPatternHeader($question->tipo))
            ->values();
    }

    private function findBestBankQuestionMatch(string $pdfBlock, $bankQuestions, array $usedQuestionIds): ?array
    {
        $pdfComparable = $this->normalizeComparableText($pdfBlock);
        $best = null;

        foreach ($bankQuestions as $question) {
            if (in_array((int) $question->id, $usedQuestionIds, true)) {
                continue;
            }

            $statementComparable = $this->normalizeComparableText((string) $question->enunciado);
            if ($statementComparable === '') {
                continue;
            }

            similar_text($pdfComparable, $statementComparable, $percent);

            if (str_contains($pdfComparable, $statementComparable) || str_contains($statementComparable, $pdfComparable)) {
                $percent = max($percent, 92);
            }

            if (! $best || $percent > $best['score']) {
                $best = ['question' => $question, 'score' => round($percent, 2)];
            }
        }

        return $best && $best['score'] >= 38 ? $best : null;
    }

    private function deriveExpectedAnswerFromPdfBlock(BancoPregunta $question, string $pdfBlock): string
    {
        $type = $this->normalizarTipoBanco($question->tipo);

        if (in_array($type, ['SELECCION_SIMPLE', 'SUBPROBLEMA'], true)) {
            $visibleOptions = $this->extractVisibleOptionsFromPdfBlock($pdfBlock);
            $correctOptionText = $this->resolveCorrectOptionText($question);

            if ($correctOptionText !== '' && ! empty($visibleOptions)) {
                $correctComparable = $this->normalizeComparableText($correctOptionText);
                $bestLetter = '';
                $bestScore = 0;

                foreach ($visibleOptions as $letter => $text) {
                    similar_text($this->normalizeComparableText($text), $correctComparable, $score);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestLetter = $letter;
                    }
                }

                if ($bestLetter && $bestScore >= 50) {
                    return $bestLetter;
                }
            }
        }

        return $this->normalizeVerifierAnswer($question->respuesta_correcta, $type);
    }

    private function extractVisibleOptionsFromPdfBlock(string $pdfBlock): array
    {
        $options = [];
        preg_match_all('/\b([A-E])\)\s*(.*?)(?=\s+[A-E]\)\s*|$)/u', $pdfBlock, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $letter = strtoupper($match[1]);
            $text = trim($match[2] ?? '');
            if ($text !== '') {
                $options[$letter] = $text;
            }
        }

        return $options;
    }

    private function resolveCorrectOptionText(BancoPregunta $question): string
    {
        $answers = $this->answerToArray($question->respuesta_correcta);
        $correctKey = strtoupper(trim((string) ($answers[0] ?? '')));

        foreach ($this->normalizeQuestionOptions($question->opciones) as $index => $option) {
            $optionId = strtoupper(trim((string) (is_array($option) ? ($option['id'] ?? '') : '')));
            $optionLetter = chr(65 + $index);

            if ($correctKey !== '' && in_array($correctKey, [$optionId, $optionLetter], true)) {
                return $this->optionText($option);
            }
        }

        return '';
    }

    private function formatBancoQuestionForVerifier(
        BancoPregunta $question,
        int $number,
        string $expectedAnswer,
        string $pdfBlock
    ): array {
        return [
            'number' => $number,
            'id' => $question->id,
            'tipo' => $question->tipo,
            'enunciado' => $question->enunciado,
            'opciones' => $this->normalizeQuestionOptions($question->opciones),
            'respuesta_correcta' => $question->respuesta_correcta,
            'expected_answer' => $expectedAnswer,
            'dificultad' => $question->dificultad,
            'grupo' => $question->grupoTeorico ?: $question->grupo,
            'pdf_text' => $pdfBlock,
            'imagen_url' => ! empty($question->imagen) ? asset('storage/preguntas/'.$question->imagen) : null,
            'source' => 'banco',
        ];
    }

    private function formatStoredVerifierQuestion($question, int $number, string $patternAnswer = ''): ?array
    {
        if (! is_array($question)) {
            return null;
        }

        return array_merge($question, [
            'number' => $number,
            'pattern_answer' => $patternAnswer,
            'expected_answer' => $question['expected_answer'] ?? '',
            'match_score' => $question['match_score'] ?? null,
            'source' => $question['source'] ?? 'audit',
        ]);
    }

    private function normalizeVerifierAnswer($answer, string $type = ''): string
    {
        $values = collect($this->answerToArray($answer))
            ->map(fn ($value) => strtoupper(trim(str_replace(['"', "'", ';'], ['', '', ','], (string) $value))))
            ->filter()
            ->values();

        if ($type === 'FALSO_VERDADERO') {
            $first = $values->first();
            if (in_array($first, ['VERDADERO', 'V', 'TRUE', '1'], true)) {
                return 'A';
            }
            if (in_array($first, ['FALSO', 'F', 'FALSE', '0'], true)) {
                return 'B';
            }
        }

        return $values->implode(',');
    }

    private function answersMatch(string $patternAnswer, string $expectedAnswer): bool
    {
        $left = strtoupper(trim(str_replace([' ', ';'], ['', ','], $patternAnswer)));
        $right = strtoupper(trim(str_replace([' ', ';'], ['', ','], $expectedAnswer)));

        return $left !== '' && $left === $right;
    }

    private function normalizeComparableText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text);
        $text = strtr($text, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
        ]);
        $text = preg_replace('/\b[a-e]\)\s*/', ' ', $text) ?? $text;
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function answerToArray($answer): array
    {
        if (is_array($answer)) {
            return $answer;
        }

        if (is_string($answer)) {
            $decoded = json_decode($answer, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            return preg_split('/[,;]+/', $answer) ?: [$answer];
        }

        return $answer === null ? [] : [$answer];
    }

    private function optionText($option): string
    {
        if (is_array($option)) {
            return (string) ($option['text'] ?? $option['texto'] ?? $option['label'] ?? $option['enunciado'] ?? '');
        }

        return (string) $option;
    }

    private function resolveRolExamenAsignaturaIds(RolExamen $examen): array
    {
        $query = DB::table('asignaturas')->where('codigo', $examen->materia_codigo);

        if (Schema::hasColumn('asignaturas', 'sede_id') && $examen->sede_id) {
            $query->where('sede_id', $examen->sede_id);
        }

        return $query->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeVerifierGroup(?string $value): string
    {
        $value = strtoupper(trim((string) $value));

        return str_replace(['G. ', 'GRUPO ', 'G-', 'G'], '', $value);
    }

    private function normalizeVerifierPartial(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return [
            '1er parcial' => '1er Parcial',
            'primer parcial' => '1er Parcial',
            '1 parcial' => '1er Parcial',
            '2do parcial' => '2do Parcial',
            'segundo parcial' => '2do Parcial',
            '2 parcial' => '2do Parcial',
            'final' => 'Final',
            'examen final' => 'Final',
            '2da instancia' => '2da Instancia',
            'segunda instancia' => '2da Instancia',
        ][$value] ?? (string) $value;
    }

    private function resolvePatternVariants(RolExamen $examen): array
    {
        $config = $examen->config_generacion ?? [];
        $auditVariants = $config['pattern_audit'] ?? $config['audit'] ?? null;

        if (is_array($auditVariants) && ! empty($auditVariants)) {
            return collect($auditVariants)
                ->filter(fn ($item) => is_array($item) && ! empty($item['letra']))
                ->map(function ($item) {
                    $answers = collect($item['patron_respuestas'] ?? [])
                        ->map(fn ($value) => is_scalar($value) ? (string) $value : '')
                        ->take(100)
                        ->pad(100, '')
                        ->values()
                        ->all();

                    $questions = $this->buildVariantQuestionDetails($item, $answers);

                    return [
                        'letra' => (string) $item['letra'],
                        'answers' => $answers,
                        'answered_count' => count(array_filter($answers, fn ($value) => trim((string) $value) !== '')),
                        'source' => 'audit',
                        'questions' => $questions,
                    ];
                })
                ->values()
                ->all();
        }

        return $this->resolvePatternVariantsFromXlsx($examen);
    }

    private function resolvePatternVariantsFromXlsx(RolExamen $examen): array
    {
        $patternEntry = collect($examen->patrones ?? [])->first(function ($item) {
            return is_array($item) && (! empty($item['xlsx_path']) || ! empty($item['xlsx']));
        });

        if (! $patternEntry) {
            return [];
        }

        $xlsxPath = $this->resolveManagedAbsolutePath(
            $patternEntry['xlsx_path'] ?? null,
            $patternEntry['xlsx'] ?? null,
            'patrones'
        );

        if (! $xlsxPath || ! is_file($xlsxPath)) {
            return [];
        }

        $spreadsheet = IOFactory::load($xlsxPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, false, false, false);

        if (count($rows) < 2) {
            return [];
        }

        return collect(array_slice($rows, 1))
            ->filter(fn ($row) => ! empty($row[1]))
            ->map(function ($row) {
                $answers = collect(array_slice($row, 3, 100))
                    ->map(fn ($value) => trim((string) $value))
                    ->pad(100, '')
                    ->values()
                    ->all();

                return [
                    'letra' => trim((string) ($row[1] ?? '')),
                    'answers' => $answers,
                    'answered_count' => count(array_filter($answers, fn ($value) => $value !== '')),
                    'source' => 'xlsx',
                    'questions' => [],
                ];
            })
            ->filter(fn ($item) => $item['letra'] !== '')
            ->values()
            ->all();
    }

    private function resolveManagedAbsolutePath(?string $managedPath, ?string $filename, string $publicDir): ?string
    {
        if ($managedPath) {
            $absolutePath = storage_path('app/'.ltrim($managedPath, '/'));
            if (is_file($absolutePath)) {
                return $absolutePath;
            }
        }

        if ($filename) {
            $publicPath = storage_path('app/public/'.trim($publicDir, '/').'/'.$filename);
            if (is_file($publicPath)) {
                return $publicPath;
            }
        }

        return null;
    }

    private function buildVariantQuestionDetails(array $auditVariant, array $answers): array
    {
        $auditQuestions = collect($auditVariant['preguntas'] ?? [])
            ->filter(fn ($item) => is_array($item) && ! $this->isMacroPatternHeader($item['tipo'] ?? null))
            ->values();

        if ($auditQuestions->isEmpty()) {
            return [];
        }

        $questionIds = $auditQuestions
            ->pluck('id')
            ->filter(fn ($id) => ! empty($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $bankQuestions = BancoPregunta::query()
            ->whereIn('id', $questionIds)
            ->get()
            ->keyBy('id');

        return $auditQuestions
            ->take(100)
            ->map(function ($question, $index) use ($bankQuestions, $answers) {
                $bankQuestion = ! empty($question['id']) ? $bankQuestions->get((int) $question['id']) : null;
                $resolved = $bankQuestion ?: null;

                return [
                    'number' => $index + 1,
                    'answer' => $answers[$index] ?? '',
                    'id' => $resolved?->id ?? ($question['id'] ?? null),
                    'tipo' => $resolved?->tipo ?? ($question['tipo'] ?? ''),
                    'enunciado' => $resolved?->enunciado ?? ($question['enunciado'] ?? ''),
                    'opciones' => $this->normalizeQuestionOptions($resolved?->opciones ?? ($question['opciones'] ?? [])),
                    'respuesta_correcta' => $this->normalizeCorrectAnswer(
                        $resolved?->respuesta_correcta ?? ($question['respuesta_correcta'] ?? [])
                    ),
                    'dificultad' => $resolved?->dificultad ?? ($question['dificultad'] ?? ''),
                    'grupo' => $resolved?->grupoTeorico ?: $resolved?->grupo ?: ($question['grupo'] ?? ''),
                    'imagen_url' => ! empty($resolved?->imagen)
                        ? asset('storage/preguntas/'.$resolved->imagen)
                        : null,
                    'source' => $resolved ? 'banco' : 'audit',
                ];
            })
            ->values()
            ->all();
    }

    private function isMacroPatternHeader(?string $tipo): bool
    {
        return in_array($this->normalizarTipoBanco($tipo), ['PROBLEMA', 'EMPAREJAMIENTO'], true);
    }

    private function normalizeQuestionOptions($options): array
    {
        if (is_array($options)) {
            return array_values($options);
        }

        if (is_string($options)) {
            $decoded = json_decode($options, true);
            if (is_array($decoded)) {
                return array_values($decoded);
            }
        }

        return [];
    }

    private function normalizeCorrectAnswer($answer)
    {
        if (is_array($answer)) {
            return $answer;
        }

        if (is_string($answer)) {
            $decoded = json_decode($answer, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return $answer;
    }

    /**
     * Eliminar todos los exámenes de una gestión y carrera
     */
    public function destroyAll(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gestion' => 'required|string|max:20',
            'carrera_id' => 'required|exists:carreras,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
        }

        $query = RolExamen::where('gestion', $request->gestion)
            ->where('carrera_id', $request->carrera_id);

        $user = auth()->user();
        if ($user && $user->rol && $user->rol->codigo === 'DIRECTOR_CARRERA') {
            $sedeId = $user->director?->sede_id ?? $user->sede_id;
            if ($sedeId) {
                $query->where('sede_id', $sedeId);
            }
        }

        $count = $query->delete();

        return response()->json(['message' => "Se eliminaron {$count} exámenes correctamente.", 'count' => $count]);
    }

    /**
     * Descargar plantilla Excel
     */
    public function template()
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // 1. Set Headers
        $headers = ['Código Materia', 'Tipo Examen', 'Grupo (Teórico)', 'Fecha', 'Hora Inicio'];
        $sheet->fromArray($headers, null, 'A1');

        // 2. Add Formatting
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']], // Indigo
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // 3. Add Sample Data
        $samples = [
            ['FIS101', '1er Parcial', '1', date('Y-m-d'), '08:00'],
            ['MAT101', '2do Parcial', '1', date('Y-m-d', strtotime('+7 days')), '10:00'],
            ['QMC101', 'Final', '2', date('Y-m-d', strtotime('+14 days')), '14:00'],
            ['INF101', '2da Instancia', '1', date('Y-m-d', strtotime('+30 days')), '08:00'],
        ];

        $row = 2;
        foreach ($samples as $sample) {
            $sheet->fromArray($sample, null, 'A'.$row);
            $row++;
        }

        // 4. Add Validation/Comments (Optional but helpful)
        $sheet->getComment('C1')->getText()->createTextRun('Opciones: 1er Parcial, 2do Parcial, Final, 2da Instancia');
        $sheet->getComment('D1')->getText()->createTextRun('Número del Grupo Teórico (ej: 1, 2)');

        // 5. Stream Download
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'plantilla_rol_examenes.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function parseDate($value, $añoDefault = 2025)
    {
        if (empty($value)) {
            return null;
        }

        // Si es número (Excel date serial)
        if (is_numeric($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
        }

        // Si es string, limpiar y normalizar
        try {
            $value = strtolower(trim($value));

            // Eliminar conectores comunes en español
            $value = str_replace([' de ', ' del '], ' ', $value);

            // Mapeo extendido de meses (incluyendo variaciones)
            $meses = [
                'ene' => 'Jan', 'feb' => 'Feb', 'mar' => 'Mar',
                'abr' => 'Apr', 'may' => 'May', 'jun' => 'Jun',
                'jul' => 'Jul', 'ago' => 'Aug', 'sep' => 'Sep', 'set' => 'Sep',
                'oct' => 'Oct', 'nov' => 'Nov', 'dic' => 'Dec',
            ];

            foreach ($meses as $es => $en) {
                if (str_contains($value, $es)) {
                    $value = str_replace($es, $en, $value);
                    break;
                }
            }

            // Asegurar año si no está presente
            if (! preg_match('/\d{4}/', $value)) {
                $value .= ' '.$añoDefault;
            }

            $timestamp = strtotime($value);

            return $timestamp ? date('Y-m-d', $timestamp) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function parseTime($value)
    {
        if (empty($value)) {
            return '00:00';
        }

        // Si es número decimal (Excel time)
        if (is_numeric($value) && $value < 1) {
            $hours = floor($value * 24);
            $minutes = round(($value * 24 - $hours) * 60);

            return sprintf('%02d:%02d', $hours, $minutes);
        }

        // Si es string, limpiar
        return date('H:i', strtotime($value));
    }

    private function normalizarTipoExamen($tipo)
    {
        $tipo = strtolower(trim($tipo));

        $mapping = [
            '1er parcial' => '1er Parcial',
            'primer parcial' => '1er Parcial',
            '1 parcial' => '1er Parcial',
            '2do parcial' => '2do Parcial',
            'segundo parcial' => '2do Parcial',
            '2 parcial' => '2do Parcial',
            'final' => 'Final',
            'examen final' => 'Final',
            '2da instancia' => '2da Instancia',
            'segunda instancia' => '2da Instancia',
            'segunda' => '2da Instancia',
        ];

        return $mapping[$tipo] ?? null;
    }

    private function obtenerParcialFuenteBanco($tipo)
    {
        $tipoNormalizado = $this->normalizarTipoExamen($tipo);

        return $tipoNormalizado === '2da Instancia' ? 'Final' : $tipoNormalizado;
    }
}
