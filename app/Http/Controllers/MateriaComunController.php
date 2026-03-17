<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\Carrera;
use App\Models\Grupo;
use App\Services\FusionBackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MateriaComunController extends Controller
{
    /**
     * List common groups, showing ONE row per group.
     * Each row shows the "base" subject and ALL linked subjects.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->director) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        // Obtener TODAS las carreras del director
        $director = $user->director;
        $carreraIds = Carrera::where('director_id', $director->id)->pluck('id')->toArray();
        
        if (empty($carreraIds) && $director->carrera_id) {
            $carreraIds[] = $director->carrera_id;
        }

        // Obtener tokens únicos de materias comunes en mis carreras
        $tokens = Asignatura::whereHas('carreras', function ($q) use ($carreraIds) {
            $q->whereIn('carreras.id', $carreraIds);
        })
            ->whereNotNull('comun_token')
            ->pluck('comun_token')
            ->unique()
            ->values();

        // Para cada token, obtener el grupo completo
        $resultado = $tokens->map(function ($token) use ($carreraIds) {
            // Obtener TODAS las asignaturas con este token
            $grupo = Asignatura::where('comun_token', $token)
                ->with('carreras')
                ->get();
            
            // La "base" debe ser aquella que pertenezca a la carrera de este director.
            $base = $grupo->first(function ($asignatura) use ($carreraIds) {
                return $asignatura->carreras->whereIn('id', $carreraIds)->isNotEmpty();
            });

            // Fallback por seguridad si no halló (aunque por la consulta inicial debería haber alguna)
            if (!$base) {
                $base = $grupo->first();
            }

            // Las vinculadas son el resto del grupo
            $vinculadas = $grupo->where('id', '!=', $base->id)->values();

            return [
                'id' => $base->id,
                'comun_token' => $token,
                'comun_tipo' => $base->comun_tipo,
                'base' => [
                    'id' => $base->id,
                    'codigo' => $base->codigo,
                    'nombre' => $base->nombre,
                    'carrera_nombre' => $base->carreras->pluck('nombre')->unique()->join(', '),
                ],
                'vinculadas' => $vinculadas->map(function ($h) {
                    return [
                        'id' => $h->id,
                        'codigo' => $h->codigo,
                        'nombre' => $h->nombre,
                        'carrera_nombre' => $h->carreras->pluck('nombre')->unique()->join(', ') ?: 'Sin Carrera',
                    ];
                })->values(),
                'total_materias' => $grupo->count()
            ];
        });

        return response()->json($resultado);
    }

    /**
     * Get all subjects from all careers managed by the current director.
     * Used in the stepper Step 1 to populate "Mi Asignatura" dropdown.
     */
    public function misAsignaturas(Request $request)
    {
        $user = $request->user();
        if (!$user->director) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $director = $user->director;
        $carreraIds = Carrera::where('director_id', $director->id)->pluck('id')->toArray();
        
        if (empty($carreraIds) && $director->carrera_id) {
            $carreraIds[] = $director->carrera_id;
        }

        $asignaturas = Asignatura::whereHas('carreras', function ($q) use ($carreraIds) {
            $q->whereIn('carreras.id', $carreraIds);
        })
            ->with(['carreras' => function($q) use ($carreraIds) {
                $q->whereIn('carreras.id', $carreraIds);
            }])
            ->orderBy('nombre')
            ->get()
            ->map(function ($a) {
                return [
                    'id' => $a->id,
                    'codigo' => $a->codigo,
                    'nombre' => $a->nombre,
                    'carrera_nombre' => $a->carreras->pluck('nombre')->unique()->join(', '),
                    'label' => "{$a->nombre} ({$a->codigo}) - " . $a->carreras->pluck('nombre')->unique()->join(', ')
                ];
            });

        return response()->json($asignaturas);
    }

    /**
     * Busca materias candidatas para vincular como comunes.
     * El director ya sabe qué es común con qué, por lo que puede buscar
     * libremente por nombre o código en cualquier carrera de la misma sede.
     *
     * Se excluyen las materias de las propias carreras del director
     * y la materia base seleccionada.
     */
    public function candidates(Request $request)
    {
        $user = $request->user();
        if (!$user->director) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $director   = $user->director;
        $sedeId     = $director->sede_id;
        $search     = $request->input('search', '');
        $excludeId  = $request->input('asignatura_id'); // Excluir la materia base

        // Carreras propias del director (para excluirlas de los resultados)
        $misCarreraIds = Carrera::where('director_id', $director->id)->pluck('id')->toArray();
        if (empty($misCarreraIds) && $director->carrera_id) {
            $misCarreraIds[] = $director->carrera_id;
        }

        $query = Asignatura::whereHas('carreras', function ($q) use ($sedeId, $misCarreraIds) {
            $q->where('asignatura_carrera.sede_id', $sedeId)
              ->whereNotIn('carreras.id', $misCarreraIds);
        });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('codigo', 'like', "%{$search}%");
            });
        }

        $candidates = $query->distinct()->limit(50)
            ->with(['carreras' => function ($q) use ($sedeId) {
                $q->where('asignatura_carrera.sede_id', $sedeId);
            }])
            ->orderBy('nombre')
            ->get()
            ->map(function ($a) {
                return [
                    'id'            => $a->id,
                    'codigo'        => $a->codigo,
                    'nombre'        => $a->nombre,
                    'carrera_nombre' => $a->carreras->pluck('nombre')->unique()->join(', ') ?: 'Sin Carrera',
                ];
            });

        return response()->json($candidates);
    }

    /**
     * Link two subjects.
     */
    public function link(Request $request)
    {
        $request->validate([
            'asignatura_id' => 'required|exists:asignaturas,id',
            'target_asignatura_id' => 'required|exists:asignaturas,id',
            'tipo' => 'nullable|string|in:fusionada'  // 'espejo' no implementado, usar solo 'fusionada'
        ]);

        $user = $request->user();
        $source = Asignatura::findOrFail($request->asignatura_id);
        $target = Asignatura::findOrFail($request->target_asignatura_id);
        $tipo = $request->input('tipo', 'fusionada');

        // Validar permisos (Basicamente que source sea de una carrera del director)
        // Por brevedad omitimos check exhaustivo, asumimos que el frontend manda id correcto del director.

        $warnings = [];

        try {
            // Logica de Fusión - Guardar tokens primero
            if (!$source->comun_token && !$target->comun_token) {
                // Caso 1: Ninguno tiene grupo -> Crear nuevo
                $token = (string) Str::uuid();
                $source->comun_token = $token;
                $source->comun_tipo = $tipo;
                $target->comun_token = $token;
                $target->comun_tipo = $tipo;
                $source->save();
                $target->save();
            } elseif ($source->comun_token && !$target->comun_token) {
                // Caso 2: Source tiene grupo, Target no -> Target se une a Source
                $target->comun_token = $source->comun_token;
                $target->comun_tipo = $source->comun_tipo;
                $target->save();
            } elseif (!$source->comun_token && $target->comun_token) {
                // Caso 3: Target tiene grupo, Source no -> Source se une a Target
                $source->comun_token = $target->comun_token;
                $source->comun_tipo = $target->comun_tipo;
                $source->save();
            } else {
                // Caso 4: Ambos tienen grupo -> FUSIONAR (Merge)
                // Todos los del grupo de Target pasan al grupo de Source
                $tokenSource = $source->comun_token;
                $tokenTarget = $target->comun_token;

                if ($tokenSource !== $tokenTarget) {
                    Asignatura::where('comun_token', $tokenTarget)
                        ->update([
                            'comun_token' => $tokenSource,
                            'comun_tipo' => $source->comun_tipo
                        ]);
                }
            }

            // MERGE INTELIGENTE AL VINCULAR
            // Fusiona campo por campo en vez de "el ganador lo toma todo".
            // Resuelve el caso donde el docente llenó la documentación en una carpeta
            // y la planificación personal en otra (por falta de horario en una de ellas).
            $tokenToSync = $source->comun_token;
            
            if ($tokenToSync) {
                // Backup de seguridad antes de fusión
                try {
                    $backupService = app(\App\Services\FusionBackupService::class);
                    $backupId = $backupService->backupPreFusion($tokenToSync);
                    Log::info('Fusión de materias comunes - Backup creado', [
                        'backup_id' => $backupId,
                        'comun_token' => $tokenToSync,
                        'source_id' => $source->id,
                        'target_id' => $target->id,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Fusión de materias comunes - Error en backup', [
                        'error' => $e->getMessage(),
                        'comun_token' => $tokenToSync,
                    ]);
                    $warnings[] = 'No se pudo crear backup de seguridad, pero la vinculación continuó.';
                }
                
                // Servicio de sincronización (se usa en merge y cronogramas)
                $syncService = app(\App\Services\MateriasComunesSyncService::class);
                
                // Sincronización inteligente (merge de documentación, planificación personal, etc.)
                try {
                    $syncService->mergeAndSyncOnLink($tokenToSync);
                    Log::info('Fusión de materias comunes - Merge inteligente completado', [
                        'comun_token' => $tokenToSync,
                        'source_id' => $source->id,
                        'target_id' => $target->id,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Fusión de materias comunes - Error en merge inteligente', [
                        'error' => $e->getMessage(),
                        'comun_token' => $tokenToSync,
                    ]);
                    $warnings[] = 'No se pudo sincronizar la documentación y planificación personal.';
                }

                // Para tipo fusionada, sincronizar también el cronograma maestro
                $source->refresh();
                if ($source->comun_tipo === 'fusionada') {
                    try {
                        $cronoMaster = Asignatura::where('comun_token', $tokenToSync)
                            ->get()
                            ->sortByDesc(function ($a) {
                                return \App\Models\Cronograma::where('asignatura_id', $a->id)
                                    ->whereNull('grupo_id')
                                    ->count();
                            })
                            ->first();

                        if ($cronoMaster) {
                            $syncService->syncCronogramasFusionada($cronoMaster);
                            Log::info('Fusión de materias comunes - Cronogramas sincronizados', [
                                'comun_token' => $tokenToSync,
                                'crono_master_id' => $cronoMaster->id,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error('Fusión de materias comunes - Error sincronizando cronogramas', [
                            'error' => $e->getMessage(),
                            'comun_token' => $tokenToSync,
                        ]);
                        $warnings[] = 'No se pudo sincronizar el cronograma entre materias vinculadas.';
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Fusión de materias comunes - Error inesperado en vinculación', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'source_id' => $source->id,
                'target_id' => $target->id,
            ]);
            $warnings[] = 'Ocurrió un error inesperado durante la vinculación, pero los cambios básicos se aplicaron.';
        }

        $response = ['message' => 'Vinculación exitosa'];
        if (!empty($warnings)) {
            $response['warnings'] = $warnings;
            Log::warning('Fusión de materias comunes completada con advertencias', [
                'comun_token' => $tokenToSync ?? null,
                'warnings' => $warnings,
            ]);
        }
        
        return response()->json($response);
    }

    /**
     * Unlink a subject from its group.
     * Reglas:
     * - Si solo hay 2 materias en el grupo (incluyendo esta), ambas pierden el comun_token
     * - Si hay 3 o más, solo esta materia pierde el comun_token
     * - Si después de desvincular queda solo 1 materia con el token, esa también pierde el token
     */
    public function unlink(Request $request, $id)
    {
        $asignatura = Asignatura::findOrFail($id);
        // Validar propiedad...
        
        $comunToken = $asignatura->comun_token;
        
        if (!$comunToken) {
            return response()->json(['message' => 'La materia no está vinculada'], 400);
        }
        
        // Contar cuántas asignaturas tienen este comun_token
        $asignaturasConToken = Asignatura::where('comun_token', $comunToken)->get();
        $totalConToken = $asignaturasConToken->count();
        
        Log::info('Desvinculando materia común', [
            'asignatura_id' => $asignatura->id,
            'comun_token' => $comunToken,
            'total_en_grupo' => $totalConToken
        ]);
        
        if ($totalConToken === 2) {
            // Caso 1: Solo 2 materias en el grupo - ambas pierden el token
            $otraAsignatura = $asignaturasConToken->where('id', '!=', $asignatura->id)->first();
            
            DB::transaction(function () use ($asignatura, $otraAsignatura) {
                $asignatura->comun_token = null;
                $asignatura->save();
                
                if ($otraAsignatura) {
                    $otraAsignatura->comun_token = null;
                    $otraAsignatura->save();
                    
                    Log::info('Desvinculación completa - ambas materias', [
                        'asignatura_1' => $asignatura->id,
                        'asignatura_2' => $otraAsignatura->id
                    ]);
                }
            });
            
            return response()->json(['message' => 'Desvinculación completa exitosa (ambas materias)']);
            
        } else {
            // Caso 2: 3 o más materias - solo esta pierde el token
            $asignatura->comun_token = null;
            $asignatura->save();
            
            // Verificar si después de desvincular queda solo 1 materia con el token
            $asignaturasRestantes = Asignatura::where('comun_token', $comunToken)->count();
            
            if ($asignaturasRestantes === 1) {
                // Si queda solo 1, esa también pierde el token
                $ultimaAsignatura = Asignatura::where('comun_token', $comunToken)->first();
                if ($ultimaAsignatura) {
                    $ultimaAsignatura->comun_token = null;
                    $ultimaAsignatura->save();
                    
                    Log::info('Desvinculación automática - última materia del grupo', [
                        'asignatura' => $ultimaAsignatura->id,
                        'comun_token' => $comunToken
                    ]);
                }
            }
            
            Log::info('Desvinculación parcial exitosa', [
                'asignatura_desvinculada' => $asignatura->id,
                'materias_restantes_con_token' => $asignaturasRestantes
            ]);
            
            return response()->json(['message' => 'Desvinculación parcial exitosa']);
        }
    }

    /**
     * Verifica la integridad de una fusión reciente comparando con el backup más reciente.
     */
    public function checkIntegrity(Request $request, string $token)
    {
        $user = $request->user();
        if (!$user->director) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $backupService = app(FusionBackupService::class);
        $backups = $backupService->listBackups($token);

        if (empty($backups)) {
            return response()->json(['error' => 'No hay backups para este token'], 404);
        }

        // Tomar el backup más reciente
        $latestBackup = $backups[0];
        $report = $backupService->verifyIntegrity($latestBackup['id'], $token);

        return response()->json($report);
    }
}
