<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackupComparisonController extends Controller
{
    /**
     * Listar bases de datos que parecen ser backups de 'academico'.
     */
    public function listBackups()
    {
        try {
            $databases = DB::select('SHOW DATABASES');
            $currentDb = config('database.connections.mysql.database');
            
            $backups = collect($databases)
                ->map(fn($db) => $db->Database)
                ->filter(function($name) use ($currentDb) {
                    return str_starts_with($name, $currentDb) || 
                           str_starts_with($name, 'db_' . $currentDb);
                })
                ->values();

            return response()->json([
                'current' => $currentDb,
                'backups' => $backups
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Buscar asignaturas en la DB de backup.
     */
    public function searchBackupSubjects(Request $request)
    {
        $backupDb = $request->backup_db;
        $query = $request->query('q');

        if (!$backupDb) return response()->json(['error' => 'Debe especificar la base de datos de backup'], 400);

        try {
            $subjects = DB::table($backupDb . '.asignaturas')
                ->where(function($q) use ($query) {
                    $q->where('codigo', 'LIKE', "%$query%")
                      ->orWhere('nombre', 'LIKE', "%$query%");
                })
                ->limit(20)
                ->get();

            return response()->json($subjects);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Buscar asignaturas en la DB actual.
     */
    public function searchCurrentSubjects(Request $request)
    {
        $query = $request->query('q');

        try {
            $subjects = DB::table('asignaturas')
                ->where(function($q) use ($query) {
                    $q->where('codigo', 'LIKE', "%$query%")
                      ->orWhere('nombre', 'LIKE', "%$query%");
                })
                ->whereNull('deleted_at')
                ->limit(20)
                ->get();

            return response()->json($subjects);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Comparar una asignatura específica entre la DB actual y una seleccionada.
     */
    public function compareSubject(Request $request)
    {
        $request->validate([
            'backup_db' => 'required|string',
            'codigo' => 'nullable|string',
            'current_id' => 'nullable|integer',
            'backup_id' => 'nullable|integer',
            'user_id' => 'nullable|integer'
        ]);

        $codigo = $request->codigo;
        $backupDb = $request->backup_db;
        $currentId = $request->current_id;
        $backupId = $request->backup_id;
        $userId = $request->user_id;
        $currentDb = config('database.connections.mysql.database');

        try {
            // Obtener de la DB actual
            if ($currentId) {
                $currentData = DB::table('asignaturas')->find($currentId);
            } else {
                $currentData = DB::table('asignaturas')->where('codigo', $codigo)->whereNull('deleted_at')->first();
            }

            // Obtener de la DB de backup
            if ($backupId) {
                $backupData = DB::table($backupDb . '.asignaturas')->find($backupId);
            } else {
                $backupData = DB::table($backupDb . '.asignaturas')->where('codigo', $codigo)->first();
            }

            if (!$currentData && !$backupData) {
                return response()->json(['error' => 'No se encontró la asignatura en ninguna de las bases de datos.'], 404);
            }

            // Obtener lista de docentes con planificaciones personales para esta asignatura
            // Combinamos de ambas bases de datos por si algún docente ya no está o es nuevo
            $currentDocentesIds = $currentData ? DB::table('planificaciones_personales')
                ->join('temas', 'planificaciones_personales.tema_id', '=', 'temas.id')
                ->join('unidades', 'temas.unidad_id', '=', 'unidades.id')
                ->where('unidades.asignatura_id', $currentData->id)
                ->distinct()
                ->pluck('user_id') : collect();

            $backupDocentesIds = $backupData ? DB::table($backupDb . '.planificaciones_personales as pp')
                ->join($backupDb . '.temas as t', 'pp.tema_id', '=', 't.id')
                ->join($backupDb . '.unidades as u', 't.unidad_id', '=', 'u.id')
                ->where('u.asignatura_id', $backupData->id)
                ->distinct()
                ->pluck('pp.user_id') : collect();
            
            $allDocentesIds = $currentDocentesIds->merge($backupDocentesIds)->unique();
            $docentes = DB::table('users')
                ->whereIn('id', $allDocentesIds)
                ->select('id', DB::raw("CONCAT(nombre, ' ', COALESCE(apellido, '')) as name"))
                ->get();

            // Campos de interés para comparar asignatura
            $fields = [
                'nombre', 'descripcion', 'justificacion', 'proposito_general',
                'metodologia_general', 'sistema_evaluacion', 'contenido_minimo',
                'requisitos', 'competencia_asignatura', 'elementos_competencia'
            ];

            $comparison = [];
            foreach ($fields as $field) {
                $valCurrent = $currentData ? ($currentData->$field ?? '') : null;
                $valBackup = $backupData ? ($backupData->$field ?? '') : null;
                
                $comparison[] = [
                    'field' => $field,
                    'current' => $valCurrent,
                    'backup' => $valBackup,
                    'different' => $valCurrent !== $valBackup
                ];
            }

            // Comparar Unidades
            $currentUnidades = $currentData ? DB::table('unidades')
                ->where('asignatura_id', $currentData->id)
                ->orderBy('numero')
                ->get() : collect();

            $backupUnidades = $backupData ? DB::table($backupDb . '.unidades')
                ->where('asignatura_id', $backupData->id)
                ->orderBy('numero')
                ->get() : collect();

            $unidadesComparison = [];
            foreach ($backupUnidades as $bu) {
                $cu = $currentUnidades->firstWhere('titulo', $bu->titulo);
                
                // Comparar Temas de esta Unidad
                $currentTemas = $cu ? DB::table('temas')->where('unidad_id', $cu->id)->orderBy('orden')->get() : collect();
                $backupTemas = DB::table($backupDb . '.temas')->where('unidad_id', $bu->id)->orderBy('orden')->get();

                $temasComp = [];
                $contentFields = [
                    'contenido_conceptual', 
                    'contenido_procedimental', 
                    'contenido_actitudinal', 
                    'resultado_aprendizaje',
                    'contenido_items'
                ];

                foreach ($backupTemas as $bt) {
                    $ct = $currentTemas->firstWhere('titulo', $bt->titulo);
                    
                    $differences = [];
                    $isDifferent = !$ct;

                    if ($ct) {
                        foreach ($contentFields as $cf) {
                            $rawC = $ct->$cf ?? '';
                            $rawB = $bt->$cf ?? '';
                            
                            // Limpiar si son JSON (vienen como ["..."])
                            $decC = json_decode($rawC, true);
                            $valC = is_array($decC) ? implode("\n", $decC) : trim($rawC);
                            
                            $decB = json_decode($rawB, true);
                            $valB = is_array($decB) ? implode("\n", $decB) : trim($rawB);

                            if ($valC !== $valB) {
                                $differences[$cf] = [
                                    'current' => $valC,
                                    'backup' => $valB
                                ];
                                $isDifferent = true;
                            }
                        }

                        // Bibliografías del tema
                        $currentBib = DB::table('tema_bibliografia as tb')
                            ->join('bibliografias as b', 'tb.bibliografia_id', '=', 'b.id')
                            ->where('tb.tema_id', $ct->id)
                            ->select(DB::raw("CONCAT(COALESCE(b.autor, ''), ' ', COALESCE(b.titulo, ''), ' ', COALESCE(b.edicion, '')) as info"))
                            ->pluck('info')
                            ->toArray();
                        
                        $backupBib = DB::table($backupDb . '.tema_bibliografia as tb')
                            ->join($backupDb . '.bibliografias as b', 'tb.bibliografia_id', '=', 'b.id')
                            ->where('tb.tema_id', $bt->id)
                            ->select(DB::raw("CONCAT(COALESCE(b.autor, ''), ' ', COALESCE(b.titulo, ''), ' ', COALESCE(b.edicion, '')) as info"))
                            ->pluck('info')
                            ->toArray();

                        if (count(array_diff($currentBib, $backupBib)) > 0 || count(array_diff($backupBib, $currentBib)) > 0) {
                            $differences['bibliografias'] = [
                                'current' => implode("\n", $currentBib),
                                'backup' => implode("\n", $backupBib)
                            ];
                            $isDifferent = true;
                        }
                    } else {
                        // Si no existe, todos los campos son diferencias (del backup)
                        foreach ($contentFields as $cf) {
                            $rawB = $bt->$cf ?? '';
                            $decB = json_decode($rawB, true);
                            $valB = is_array($decB) ? implode("\n", $decB) : trim($rawB);

                            $differences[$cf] = [
                                'current' => null,
                                'backup' => $valB
                            ];
                        }

                        $backupBib = DB::table($backupDb . '.tema_bibliografia as tb')
                            ->join($backupDb . '.bibliografias as b', 'tb.bibliografia_id', '=', 'b.id')
                            ->where('tb.tema_id', $bt->id)
                            ->select(DB::raw("CONCAT(COALESCE(b.autor, ''), ' ', COALESCE(b.titulo, ''), ' ', COALESCE(b.edicion, '')) as info"))
                            ->pluck('info')
                            ->toArray();
                        
                        $differences['bibliografias'] = [
                            'current' => null,
                            'backup' => implode("\n", $backupBib)
                        ];
                    }

                    // Comparar Logros e Indicadores
                    $backupLogros = DB::table($backupDb . '.logros_esperados')->where('tema_id', $bt->id)->get();
                    $currentLogros = $ct ? DB::table('logros_esperados')->where('tema_id', $ct->id)->get() : collect();
                    
                    $logrosComp = [];
                    foreach ($backupLogros as $bl) {
                        $cl = $currentLogros->firstWhere('descripcion', $bl->descripcion);
                        
                        // Comparar Indicadores de este Logro
                        $backupInd = DB::table($backupDb . '.indicadores')->where('logro_esperado_id', $bl->id)->get();
                        $currentInd = $cl ? DB::table('indicadores')->where('logro_esperado_id', $cl->id)->get() : collect();
                        
                        $indComp = [];
                        foreach ($backupInd as $bi) {
                            $ci = $currentInd->firstWhere('descripcion', $bi->descripcion);
                            if (!$ci) $isDifferent = true;
                            $indComp[] = [
                                'descripcion' => $bi->descripcion,
                                'found_current' => !!$ci
                            ];
                        }

                        if (!$cl) $isDifferent = true;
                        
                        $logrosComp[] = [
                            'descripcion' => $bl->descripcion,
                            'found_current' => !!$cl,
                            'indicadores' => $indComp
                        ];
                    }

                    // Comparar Planificación Personal si se seleccionó un docente
                    $personalComparison = null;
                    if ($userId) {
                        $currentUser = DB::table('users')->find($userId);
                        $backupUserId = null;
                        
                        if ($currentUser) {
                            $backupUser = DB::table($backupDb . '.users')
                                ->where('email', $currentUser->email)
                                ->first();
                            if (!$backupUser) {
                                $backupUser = DB::table($backupDb . '.users')
                                    ->where('nombre', $currentUser->nombre)
                                    ->where('apellido', $currentUser->apellido)
                                    ->first();
                            }
                            $backupUserId = $backupUser ? $backupUser->id : null;
                        }

                        $currentPP = $ct ? DB::table('planificaciones_personales')
                            ->where('tema_id', $ct->id)
                            ->where('user_id', $userId)
                            ->first() : null;

                        $backupPP = ($bt && $backupUserId) ? DB::table($backupDb . '.planificaciones_personales')
                            ->where('tema_id', $bt->id)
                            ->where('user_id', $backupUserId)
                            ->first() : null;

                        if ($backupPP || $currentPP) {
                            $fieldsPP = [
                                'estrategias_metodologicas', 'estrategias_aprendizaje', 
                                'estrategias_recursos', 'evaluacion_formativa', 
                                'evaluacion_sumativa', 'secuencia_didactica'
                            ];

                            $ppDiffs = [];
                            foreach ($fieldsPP as $f) {
                                $vC = $currentPP ? ($currentPP->$f ?? '') : '';
                                $vB = $backupPP ? ($backupPP->$f ?? '') : '';
                                if ($vC !== $vB) {
                                    $ppDiffs[$f] = [
                                        'current' => $vC,
                                        'backup' => $vB
                                    ];
                                    $isDifferent = true;
                                }
                            }

                            $personalComparison = [
                                'current_id' => $currentPP->id ?? null,
                                'backup_id' => $backupPP->id ?? null,
                                'found_current' => !!$currentPP,
                                'found_backup' => !!$backupPP,
                                'differences' => $ppDiffs,
                                'current_data' => $currentPP ? [
                                    'secuencia' => json_decode($currentPP->secuencia_didactica, true),
                                    'recursos' => json_decode($currentPP->estrategias_recursos, true),
                                    'formativa' => json_decode($currentPP->evaluacion_formativa, true),
                                    'sumativa' => json_decode($currentPP->evaluacion_sumativa, true),
                                ] : null,
                                'backup_data' => $backupPP ? [
                                    'secuencia' => json_decode($backupPP->secuencia_didactica, true),
                                    'recursos' => json_decode($backupPP->estrategias_recursos, true),
                                    'formativa' => json_decode($backupPP->evaluacion_formativa, true),
                                    'sumativa' => json_decode($backupPP->evaluacion_sumativa, true),
                                ] : null
                            ];
                        }
                    }

                    $temasComp[] = [
                        'current_id' => $ct->id ?? null,
                        'backup_id' => $bt->id ?? null,
                        'titulo' => $bt->titulo,
                        'found_current' => !!$ct,
                        'different' => $isDifferent,
                        'differences' => $differences,
                        'logros' => $logrosComp,
                        'planificacion_personal' => $personalComparison,
                        // Para compatibilidad
                        'current_conceptual' => $ct ? $ct->contenido_conceptual : null,
                        'backup_conceptual' => $bt->contenido_conceptual
                    ];
                }

                $unidadesComparison[] = [
                    'titulo' => $bu->titulo,
                    'found_current' => !!$cu,
                    'temas' => $temasComp
                ];
            }

            // Comparar Cronogramas (Sesiones del semestre)
            $cronogramasComparison = [];
            $backupCronos = $backupData ? DB::table($backupDb . '.cronogramas')
                ->where('asignatura_id', $backupData->id)
                ->orderBy('numero_sesion')
                ->get() : collect();

            $currentCronos = $currentData ? DB::table('cronogramas')
                ->where('asignatura_id', $currentData->id)
                ->orderBy('numero_sesion')
                ->get() : collect();

            $allSessionsNumbers = $backupCronos->pluck('numero_sesion')
                ->merge($currentCronos->pluck('numero_sesion'))
                ->unique()
                ->sort()
                ->values();

            foreach ($allSessionsNumbers as $num) {
                $bc = $backupCronos->firstWhere('numero_sesion', $num);
                $cc = $currentCronos->firstWhere('numero_sesion', $num);

                $isSessionDifferent = false;
                $sessionDifferences = [];
                $cronFields = [
                    'semana_academica', 'tipo_clase', 'contenido_conceptual',
                    'contenido_procedimental', 'contenido_actitudinal',
                    'criterios_desempeno', 'instrumentos_evaluacion'
                ];

                foreach ($cronFields as $cf) {
                    $rawC = $cc ? ($cc->$cf ?? '') : '';
                    $rawB = $bc ? ($bc->$cf ?? '') : '';

                    // Normalizar contenido (quitar JSON si aplica)
                    $decC = json_decode($rawC, true);
                    $valC = is_array($decC) ? implode("\n", $decC) : trim($rawC);

                    $decB = json_decode($rawB, true);
                    $valB = is_array($decB) ? implode("\n", $decB) : trim($rawB);

                    if ($valC !== $valB) {
                        $sessionDifferences[$cf] = [
                            'current' => $valC,
                            'backup' => $valB
                        ];
                        $isSessionDifferent = true;
                    }
                }

                $cronogramasComparison[] = [
                    'numero_sesion' => $num,
                    'current_id' => $cc->id ?? null,
                    'backup_id' => $bc->id ?? null,
                    'semana' => $cc->semana_academica ?? ($bc->semana_academica ?? '?'),
                    'tipo_clase' => $cc->tipo_clase ?? ($bc->tipo_clase ?? '?'),
                    'found_current' => !!$cc,
                    'found_backup' => !!$bc,
                    'different' => $isSessionDifferent,
                    'differences' => $sessionDifferences
                ];
            }

            // Comparar Seguimientos (Avance de Clase)
            $backupSegs = $backupData ? DB::table($backupDb . '.seguimientos as s')
                ->join($backupDb . '.grupos as g', 's.grupo_id', '=', 'g.id')
                ->where('g.asignatura_id', $backupData->id)
                ->select('s.*', 'g.nombre as grupo_nombre')
                ->orderBy('s.fecha', 'desc')
                ->get() : collect();

            $currentSegs = $currentData ? DB::table('seguimientos as s')
                ->join('grupos as g', 's.grupo_id', '=', 'g.id')
                ->where('g.asignatura_id', $currentData->id)
                ->select('s.*', 'g.nombre as grupo_nombre')
                ->orderBy('s.fecha', 'desc')
                ->get() : collect();

            $seguimientosComparison = [
                'backup_count' => $backupSegs->count(),
                'current_count' => $currentSegs->count(),
                'backup_sample' => $backupSegs->take(10)->map(function($s) {
                    return [
                        'fecha' => $s->fecha,
                        'grupo' => $s->grupo_nombre,
                        'tema' => $s->tema_cumplido,
                        'cumplido' => $s->cumplido
                    ];
                })
            ];

            return response()->json([
                'current_id' => $currentData->id ?? null,
                'backup_id' => $backupData->id ?? null,
                'codigo' => $codigo,
                'current_db' => $currentDb,
                'backup_db' => $backupDb,
                'docentes' => $docentes,
                'docente_name' => $userId ? ($docentes->firstWhere('id', $userId)->name ?? 'Docente') : null,
                'comparison' => $comparison,
                'unidades' => $unidadesComparison,
                'cronogramas' => $cronogramasComparison,
                'seguimientos' => $seguimientosComparison,
                'found_current' => !!$currentData,
                'found_backup' => !!$backupData
            ]);

        } catch (\Exception $e) {
            Log::error("Error en comparación de backups: " . $e->getMessage());
            return response()->json(['error' => 'Error al acceder a la base de datos de backup. Verifique que exista y tenga los mismos permisos.'], 500);
        }
    }

    public function restoreSegment(Request $request)
    {
        $type = $request->type;
        $targetId = $request->target_id; // ID en DB actual
        $backupId = $request->backup_id; // ID en DB backup
        $backupDb = $request->backup_db;
        $field = $request->field; // Campo específico opcional

        if (!$backupDb) {
            return response()->json(['error' => 'Debe especificar la base de datos de backup'], 400);
        }

        try {
            switch ($type) {
                case 'asignatura':
                    // Campos que se comparan en compareSubject
                    $allowedFields = [
                        'nombre', 'descripcion', 'justificacion', 'proposito_general',
                        'metodologia_general', 'sistema_evaluacion', 'contenido_minimo',
                        'requisitos', 'competencia_asignatura', 'elementos_competencia'
                    ];
                    
                    $backup = DB::table($backupDb . '.asignaturas')->where('id', $backupId)->first();
                    if (!$backup) return response()->json(['error' => 'No se encontró el registro en el backup'], 404);
                    
                    $updateData = [];
                    if ($field && in_array($field, $allowedFields)) {
                        $updateData[$field] = $backup->$field;
                    } else {
                        foreach ($allowedFields as $f) {
                            $updateData[$f] = $backup->$f;
                        }
                    }
                    DB::table('asignaturas')->where('id', $targetId)->update($updateData);
                    break;

                case 'tema':
                    $fields = ['contenido_conceptual', 'contenido_procedimental', 'contenido_actitudinal', 'resultado_aprendizaje', 'contenido_items'];
                    $backup = DB::table($backupDb . '.temas')->where('id', $backupId)->first();
                    if (!$backup) return response()->json(['error' => 'No se encontró el tema en el backup'], 404);
                    
                    $updateData = [];
                    foreach ($fields as $f) {
                        $updateData[$f] = $backup->$f;
                    }
                    DB::table('temas')->where('id', $targetId)->update($updateData);

                    // Restaurar Bibliografías del Tema
                    DB::table('tema_bibliografia')->where('tema_id', $targetId)->delete();
                    $backupBibs = DB::table($backupDb . '.tema_bibliografia')->where('tema_id', $backupId)->get();
                    foreach ($backupBibs as $bb) {
                        $originalBib = DB::table($backupDb . '.bibliografias')->where('id', $bb->bibliografia_id)->first();
                        if ($originalBib) {
                            $currentBib = DB::table('bibliografias')
                                ->where('titulo', $originalBib->titulo)
                                ->where('autor', $originalBib->autor)
                                ->where('edicion', $originalBib->edicion)
                                ->first();
                            
                            if ($currentBib) {
                                DB::table('tema_bibliografia')->insert([
                                    'tema_id' => $targetId,
                                    'bibliografia_id' => $currentBib->id,
                                    'pagina_desde' => $bb->pagina_desde,
                                    'pagina_hasta' => $bb->pagina_hasta,
                                    'created_at' => now(),
                                    'updated_at' => now()
                                ]);
                            }
                        }
                    }

                    // Restaurar Logros e Indicadores
                    // 1. Eliminar actuales (cascada manual)
                    $currentLogrosIds = DB::table('logros_esperados')->where('tema_id', $targetId)->pluck('id');
                    DB::table('indicadores')->whereIn('logro_esperado_id', $currentLogrosIds)->delete();
                    DB::table('logros_esperados')->where('tema_id', $targetId)->delete();

                    // 2. Copiar del Backup
                    $backupLogros = DB::table($backupDb . '.logros_esperados')->where('tema_id', $backupId)->get();
                    foreach ($backupLogros as $bl) {
                        $newLogroId = DB::table('logros_esperados')->insertGetId([
                            'tema_id' => $targetId,
                            'descripcion' => $bl->descripcion,
                            'tipo_logro' => $bl->tipo_logro,
                            'periodo' => $bl->periodo,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);

                        $backupInds = DB::table($backupDb . '.indicadores')->where('logro_esperado_id', $bl->id)->get();
                        foreach ($backupInds as $bi) {
                            DB::table('indicadores')->insert([
                                'logro_esperado_id' => $newLogroId,
                                'descripcion' => $bi->descripcion,
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);
                        }
                    }
                    break;

                case 'cronograma':
                    $fields = ['semana_academica', 'tipo_clase', 'contenido_conceptual', 'contenido_procedimental', 'contenido_actitudinal', 'criterios_desempeno', 'instrumentos_evaluacion'];
                    $backup = DB::table($backupDb . '.cronogramas')->where('id', $backupId)->first();
                    if (!$backup) return response()->json(['error' => 'No se encontró la sesión en el backup'], 404);
                    
                    $updateData = [];
                    foreach ($fields as $f) {
                        $updateData[$f] = $backup->$f;
                    }
                    DB::table('cronogramas')->where('id', $targetId)->update($updateData);
                    break;

                case 'cronograma_total':
                    // Aquí targetId y backupId son los IDs de la ASIGNATURA
                    $fields = ['numero_sesion', 'semana_academica', 'tipo_clase', 'contenido_conceptual', 'contenido_procedimental', 'contenido_actitudinal', 'criterios_desempeno', 'instrumentos_evaluacion'];
                    
                    $backupSessions = DB::table($backupDb . '.cronogramas')->where('asignatura_id', $backupId)->get();
                    if ($backupSessions->isEmpty()) return response()->json(['error' => 'No se encontraron sesiones en el backup'], 404);
                    
                    DB::beginTransaction();
                    try {
                        // Limpiar cronograma actual
                        DB::table('cronogramas')->where('asignatura_id', $targetId)->delete();
                        
                        foreach ($backupSessions as $bs) {
                            $newData = ['asignatura_id' => $targetId];
                            foreach ($fields as $f) {
                                $newData[$f] = $bs->$f;
                            }
                            $newData['created_at'] = now();
                            $newData['updated_at'] = now();
                            DB::table('cronogramas')->insert($newData);
                        }
                        DB::commit();
                    } catch (\Exception $e) {
                        DB::rollBack();
                        throw $e;
                    }
                    break;

                case 'unidades_total':
                    // Restauración masiva de Unidades, Temas, Logros, Indicadores y Bibliografía
                    $backupUnits = DB::table($backupDb . '.unidades')->where('asignatura_id', $backupId)->get();
                    if ($backupUnits->isEmpty()) return response()->json(['error' => 'No se encontraron unidades en el backup'], 404);

                    DB::beginTransaction();
                    try {
                        // 1. Identificar IDs actuales para limpieza profunda
                        $currentUnitsIds = DB::table('unidades')->where('asignatura_id', $targetId)->pluck('id');
                        $currentTemasIds = DB::table('temas')->whereIn('unidad_id', $currentUnitsIds)->pluck('id');
                        $currentLogrosIds = DB::table('logros_esperados')->whereIn('tema_id', $currentTemasIds)->pluck('id');

                        // 2. Limpieza en orden de dependencias
                        DB::table('tema_bibliografia')->whereIn('tema_id', $currentTemasIds)->delete();
                        DB::table('indicadores')->whereIn('logro_esperado_id', $currentLogrosIds)->delete();
                        DB::table('logros_esperados')->whereIn('tema_id', $currentTemasIds)->delete();
                        DB::table('temas')->whereIn('unidad_id', $currentUnitsIds)->delete();
                        DB::table('unidades')->where('asignatura_id', $targetId)->delete();

                        // 3. Recrear desde Backup
                        foreach ($backupUnits as $bu) {
                            $newUnitId = DB::table('unidades')->insertGetId([
                                'asignatura_id' => $targetId,
                                'numero' => $bu->numero ?? ($bu->orden ?? null),
                                'titulo' => $bu->titulo,
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);

                            $backupTemas = DB::table($backupDb . '.temas')->where('unidad_id', $bu->id)->get();
                            foreach ($backupTemas as $bt) {
                                $newTemaId = DB::table('temas')->insertGetId([
                                    'unidad_id' => $newUnitId,
                                    'orden' => $bt->orden ?? ($bt->numero ?? null),
                                    'titulo' => $bt->titulo,
                                    'contenido_conceptual' => $bt->contenido_conceptual,
                                    'contenido_procedimental' => $bt->contenido_procedimental,
                                    'contenido_actitudinal' => $bt->contenido_actitudinal,
                                    'resultado_aprendizaje' => $bt->resultado_aprendizaje,
                                    'contenido_items' => $bt->contenido_items,
                                    'created_at' => now(),
                                    'updated_at' => now()
                                ]);

                                // Restaurar Bibliografía
                                $backupBibLinks = DB::table($backupDb . '.tema_bibliografia')->where('tema_id', $bt->id)->get();
                                foreach ($backupBibLinks as $bbl) {
                                    $origBib = DB::table($backupDb . '.bibliografias')->where('id', $bbl->bibliografia_id)->first();
                                    if ($origBib) {
                                        $currBib = DB::table('bibliografias')
                                            ->where('titulo', $origBib->titulo)
                                            ->where('autor', $origBib->autor)
                                            ->where('edicion', $origBib->edicion)
                                            ->first();
                                        if ($currBib) {
                                            DB::table('tema_bibliografia')->insert([
                                                'tema_id' => $newTemaId,
                                                'bibliografia_id' => $currBib->id,
                                                'pagina_desde' => $bbl->pagina_desde,
                                                'pagina_hasta' => $bbl->pagina_hasta,
                                                'created_at' => now(),
                                                'updated_at' => now()
                                            ]);
                                        }
                                    }
                                }

                                // Restaurar Logros e Indicadores
                                $backupLogros = DB::table($backupDb . '.logros_esperados')->where('tema_id', $bt->id)->get();
                                foreach ($backupLogros as $bl) {
                                    $newLogroId = DB::table('logros_esperados')->insertGetId([
                                        'tema_id' => $newTemaId,
                                        'descripcion' => $bl->descripcion,
                                        'created_at' => now(),
                                        'updated_at' => now()
                                    ]);

                                    $backupInds = DB::table($backupDb . '.indicadores')->where('logro_esperado_id', $bl->id)->get();
                                    foreach ($backupInds as $bi) {
                                        DB::table('indicadores')->insert([
                                            'logro_esperado_id' => $newLogroId,
                                            'descripcion' => $bi->descripcion,
                                            'created_at' => now(),
                                            'updated_at' => now()
                                        ]);
                                    }
                                }

                                // Restaurar Planificaciones Personales
                                $backupPPs = DB::table($backupDb . '.planificaciones_personales')->where('tema_id', $bt->id)->get();
                                foreach ($backupPPs as $bpp) {
                                    $bUser = DB::table($backupDb . '.users')->where('id', $bpp->user_id)->first();
                                    if ($bUser) {
                                        // Buscar usuario local por email (más confiable) o nombre
                                        $cUser = DB::table('users')->where('email', $bUser->email)->first();
                                        if (!$cUser) {
                                            $cUser = DB::table('users')
                                                ->where('nombre', $bUser->nombre)
                                                ->where('apellido', $bUser->apellido)
                                                ->first();
                                        }

                                        if ($cUser) {
                                            DB::table('planificaciones_personales')->insert([
                                                'tema_id' => $newTemaId,
                                                'user_id' => $cUser->id,
                                                'estrategias_metodologicas' => $bpp->estrategias_metodologicas,
                                                'estrategias_aprendizaje' => $bpp->estrategias_aprendizaje,
                                                'estrategias_recursos' => $bpp->estrategias_recursos,
                                                'evaluacion_formativa' => $bpp->evaluacion_formativa,
                                                'evaluacion_sumativa' => $bpp->evaluacion_sumativa,
                                                'secuencia_didactica' => $bpp->secuencia_didactica,
                                                'created_at' => now(),
                                                'updated_at' => now()
                                            ]);
                                        }
                                    }
                                }
                            }
                        }

                        DB::commit();
                    } catch (\Exception $e) {
                        DB::rollBack();
                        throw $e;
                    }
                    break;

                case 'planificacion_personal':
                    $fields = ['secuencia_didactica', 'estrategias_metodologicas', 'estrategias_aprendizaje', 'estrategias_recursos', 'evaluacion_formativa', 'evaluacion_sumativa'];
                    $backup = DB::table($backupDb . '.planificaciones_personales')->where('id', $backupId)->first();
                    if (!$backup) return response()->json(['error' => 'No se encontró la planificación en el backup'], 404);
                    
                    $updateData = [];
                    foreach ($fields as $f) {
                        $updateData[$f] = $backup->$f;
                    }
                    DB::table('planificaciones_personales')->where('id', $targetId)->update($updateData);
                    break;
                
                case 'seguimientos_total':
                    // targetId y backupId son IDs de ASIGNATURA
                    $gruposC = DB::table('grupos')->where('asignatura_id', $targetId)->get();
                    $gruposB = DB::table($backupDb . '.grupos')->where('asignatura_id', $backupId)->get();

                    DB::beginTransaction();
                    try {
                        foreach ($gruposC as $gc) {
                            $gb = $gruposB->firstWhere('nombre', $gc->nombre);
                            if (!$gb) continue;

                            $seguimientosB = DB::table($backupDb . '.seguimientos')->where('grupo_id', $gb->id)->get();
                            foreach ($seguimientosB as $sb) {
                                $arr = (array)$sb; unset($arr['id']);
                                $arr['grupo_id'] = $gc->id;
                                
                                // Intentar mapear cronograma_id si existe
                                if ($sb->cronograma_id) {
                                    $cb = DB::table($backupDb . '.cronogramas')->find($sb->cronograma_id);
                                    if ($cb) {
                                        $cc = DB::table('cronogramas')
                                            ->where('grupo_id', $gc->id)
                                            ->where('numero_sesion', $cb->numero_sesion)
                                            ->first();
                                        if ($cc) $arr['cronograma_id'] = $cc->id;
                                        else $arr['cronograma_id'] = null;
                                    }
                                }

                                // Evitar duplicados por grupo, fecha y tema cumplido
                                $exists = DB::table('seguimientos')
                                    ->where('grupo_id', $gc->id)
                                    ->where('fecha', $sb->fecha)
                                    ->where('tema_cumplido', $sb->tema_cumplido)
                                    ->exists();
                                
                                if (!$exists) {
                                    DB::table('seguimientos')->insert($arr);
                                }
                            }
                        }
                        DB::commit();
                    } catch (\Exception $e) {
                        DB::rollBack();
                        throw $e;
                    }
                    break;
                
                default:
                    return response()->json(['error' => 'Tipo de restauración no soportado'], 400);
            }

            return response()->json(['message' => 'Restauración completada correctamente']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error en restauración: ' . $e->getMessage()], 500);
        }
    }
}
