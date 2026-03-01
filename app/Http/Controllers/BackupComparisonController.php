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
     * Comparar una asignatura específica entre la DB actual y una seleccionada.
     */
    public function compareSubject(Request $request)
    {
        $request->validate([
            'codigo' => 'required|string',
            'backup_db' => 'required|string',
            'user_id' => 'nullable|integer'
        ]);

        $codigo = $request->codigo;
        $backupDb = $request->backup_db;
        $userId = $request->user_id;
        $currentDb = config('database.connections.mysql.database');

        try {
            // Obtener de la DB actual
            $currentData = DB::table('asignaturas')->where('codigo', $codigo)->first();

            // Obtener de la DB de backup
            $backupData = DB::table($backupDb . '.asignaturas')->where('codigo', $codigo)->first();

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
                    'resultado_aprendizaje'
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

            return response()->json([
                'codigo' => $codigo,
                'current_db' => $currentDb,
                'backup_db' => $backupDb,
                'docentes' => $docentes,
                'docente_name' => $userId ? ($docentes->firstWhere('id', $userId)->name ?? 'Docente') : null,
                'comparison' => $comparison,
                'unidades' => $unidadesComparison,
                'found_current' => !!$currentData,
                'found_backup' => !!$backupData
            ]);

        } catch (\Exception $e) {
            Log::error("Error en comparación de backups: " . $e->getMessage());
            return response()->json(['error' => 'Error al acceder a la base de datos de backup. Verifique que exista y tenga los mismos permisos.'], 500);
        }
    }
}
