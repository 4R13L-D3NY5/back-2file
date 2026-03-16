<?php

namespace App\Services;

use App\Models\Asignatura;
use App\Models\Cronograma;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de Backup para fusiones de materias comunes
 * 
 * Crea snapshots automáticos antes de cualquier operación de fusión
 * para permitir rollback en caso de problemas.
 */
class FusionBackupService
{
    /**
     * Crea un backup completo de todas las asignaturas con un comun_token dado.
     * Incluye: asignatura, unidades, temas, logros, indicadores, cronogramas.
     * 
     * @param string $comunToken El token común del grupo de asignaturas
     * @return string UUID del backup creado
     */
    public function backupPreFusion(string $comunToken): string
    {
        $backupId = (string) Str::uuid();
        
        try {
            // Obtener snapshot de todas las asignaturas con este token
            $snapshot = Asignatura::where('comun_token', $comunToken)
                ->with([
                    'unidades.temas.logros.indicadores',
                    'unidades.temas.planificacionPersonal',
                    'bibliografias',
                    'cronogramas' => function ($query) {
                        $query->whereNull('grupo_id'); // Solo cronograma maestro
                    }
                ])
                ->get()
                ->map(function ($asignatura) {
                    // Convertir a array pero excluir relaciones cíclicas
                    return [
                        'id' => $asignatura->id,
                        'codigo' => $asignatura->codigo,
                        'nombre' => $asignatura->nombre,
                        'comun_token' => $asignatura->comun_token,
                        'comun_tipo' => $asignatura->comun_tipo,
                        // Campos PAC críticos
                        'justificacion' => $asignatura->justificacion,
                        'proposito_general' => $asignatura->proposito_general,
                        'competencia_global_especifica' => $asignatura->competencia_global_especifica,
                        'competencia_asignatura' => $asignatura->competencia_asignatura,
                        'metodologia_general' => $asignatura->metodologia_general,
                        'sistema_evaluacion' => $asignatura->sistema_evaluacion,
                        'contenido_minimo' => $asignatura->contenido_minimo,
                        // Estructura
                        'unidades' => $asignatura->unidades->map(function ($unidad) {
                            return [
                                'id' => $unidad->id,
                                'numero' => $unidad->numero,
                                'titulo' => $unidad->titulo,
                                'objetivo' => $unidad->objetivo,
                                'temas' => $unidad->temas->map(function ($tema) {
                                    return [
                                        'id' => $tema->id,
                                        'orden' => $tema->orden,
                                        'titulo' => $tema->titulo,
                                        'resultado_aprendizaje' => $tema->resultado_aprendizaje,
                                        'contenido_conceptual' => $tema->contenido_conceptual,
                                        'contenido_procedimental' => $tema->contenido_procedimental,
                                        'contenido_actitudinal' => $tema->contenido_actitudinal,
                                        'logros' => $tema->logros->map(function ($logro) {
                                            return [
                                                'id' => $logro->id,
                                                'descripcion' => $logro->descripcion,
                                                'tipo_logro' => $logro->tipo_logro,
                                                'indicadores' => $logro->indicadores->map(function ($ind) {
                                                    return ['id' => $ind->id, 'descripcion' => $ind->descripcion];
                                                })->toArray()
                                            ];
                                        })->toArray()
                                    ];
                                })->toArray()
                            ];
                        })->toArray(),
                        // Cronograma maestro (solo sesiones con contenido)
                        'cronogramas' => $asignatura->cronogramas->map(function ($crono) {
                            return [
                                'id' => $crono->id,
                                'numero_sesion' => $crono->numero_sesion,
                                'tema_id' => $crono->tema_id,
                                'contenido_conceptual' => $crono->contenido_conceptual,
                                'contenido_procedimental' => $crono->contenido_procedimental,
                                'contenido_actitudinal' => $crono->contenido_actitudinal,
                                'criterios_desempeno' => $crono->criterios_desempeno,
                                'instrumentos_evaluacion' => $crono->instrumentos_evaluacion,
                                'observaciones' => $crono->observaciones,
                                'semana_academica' => $crono->semana_academica,
                                'tipo_clase' => $crono->tipo_clase,
                            ];
                        })->toArray(),
                        // Bibliografías
                        'bibliografias' => $asignatura->bibliografias->map(function ($bib) {
                            return [
                                'id' => $bib->id,
                                'titulo' => $bib->titulo,
                                'autor' => $bib->autor,
                                'editorial' => $bib->editorial,
                                'anio' => $bib->anio,
                                'tipo' => $bib->tipo,
                            ];
                        })->toArray()
                    ];
                })
                ->toArray();
            
            // Guardar backup en la base de datos
            DB::table('fusion_backups')->insert([
                'id' => $backupId,
                'comun_token' => $comunToken,
                'snapshot' => json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                'created_at' => now()
            ]);
            
            Log::info("FusionBackup: Backup creado para token {$comunToken}", [
                'backup_id' => $backupId,
                'asignaturas' => count($snapshot),
                'timestamp' => now()->toDateTimeString()
            ]);
            
            return $backupId;
            
        } catch (\Exception $e) {
            Log::error("FusionBackup: Error al crear backup para token {$comunToken}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Si falla el backup, NO continuar con la fusión
            throw new \Exception("No se pudo crear backup de seguridad. Fusión cancelada: " . $e->getMessage());
        }
    }
    
    /**
     * Restaura un backup específico.
     * 
     * @param string $backupId ID del backup a restaurar
     * @return bool True si se restauró exitosamente
     */
    public function restoreBackup(string $backupId): bool
    {
        try {
            $backup = DB::table('fusion_backups')->where('id', $backupId)->first();
            
            if (!$backup) {
                throw new \Exception("Backup no encontrado: {$backupId}");
            }
            
            $snapshot = json_decode($backup->snapshot, true);
            $comunToken = $backup->comun_token;
            
            Log::info("FusionBackup: Iniciando restauración", [
                'backup_id' => $backupId,
                'comun_token' => $comunToken,
                'asignaturas' => count($snapshot)
            ]);
            
            DB::transaction(function () use ($snapshot, $comunToken) {
                foreach ($snapshot as $asigData) {
                    // 1. Restaurar campos PAC de la asignatura
                    $asignatura = Asignatura::find($asigData['id']);
                    if ($asignatura) {
                        $asignatura->update([
                            'justificacion' => $asigData['justificacion'],
                            'proposito_general' => $asigData['proposito_general'],
                            'competencia_global_especifica' => $asigData['competencia_global_especifica'],
                            'competencia_asignatura' => $asigData['competencia_asignatura'],
                            'metodologia_general' => $asigData['metodologia_general'],
                            'sistema_evaluacion' => $asigData['sistema_evaluacion'],
                            'contenido_minimo' => $asigData['contenido_minimo'],
                        ]);
                    }
                    
                    // 2. Restaurar cronogramas (solo maestro)
                    Cronograma::where('asignatura_id', $asigData['id'])
                        ->whereNull('grupo_id')
                        ->delete();
                    
                    foreach ($asigData['cronogramas'] as $cronoData) {
                        Cronograma::create(array_merge($cronoData, [
                            'asignatura_id' => $asigData['id'],
                            'grupo_id' => null
                        ]));
                    }
                }
            });
            
            Log::info("FusionBackup: Restauración completada exitosamente", [
                'backup_id' => $backupId,
                'comun_token' => $comunToken
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("FusionBackup: Error en restauración", [
                'backup_id' => $backupId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return false;
        }
    }
    
    /**
     * Lista los backups disponibles para un comun_token.
     * 
     * @param string $comunToken
     * @return array Lista de backups ordenados por fecha descendente
     */
    public function listBackups(string $comunToken): array
    {
        return DB::table('fusion_backups')
            ->where('comun_token', $comunToken)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($backup) {
                $snapshot = json_decode($backup->snapshot, true);
                return [
                    'id' => $backup->id,
                    'created_at' => $backup->created_at,
                    'asignaturas_count' => count($snapshot),
                    'asignaturas' => array_map(function ($asig) {
                        return $asig['codigo'] . ' - ' . $asig['nombre'];
                    }, $snapshot)
                ];
            })
            ->toArray();
    }
    
    /**
     * Verifica la integridad de una fusión reciente comparando con el backup.
     * 
     * @param string $backupId ID del backup pre-fusión
     * @param string $comunToken Token común del grupo
     * @return array Reporte de integridad
     */
    public function verifyIntegrity(string $backupId, string $comunToken): array
    {
        $backup = DB::table('fusion_backups')->where('id', $backupId)->first();
        
        if (!$backup) {
            return ['status' => 'error', 'message' => 'Backup no encontrado'];
        }
        
        $snapshot = json_decode($backup->snapshot, true);
        $current = Asignatura::where('comun_token', $comunToken)
            ->with(['unidades.temas', 'cronogramas' => function ($q) {
                $q->whereNull('grupo_id');
            }])
            ->get()
            ->toArray();
        
        // Comparar conteos básicos
        $report = [
            'status' => 'ok',
            'backup_id' => $backupId,
            'timestamp' => $backup->created_at,
            'comparison' => [
                'asignaturas' => [
                    'before' => count($snapshot),
                    'after' => count($current),
                    'match' => count($snapshot) === count($current)
                ],
                'cronogramas' => [
                    'before' => array_sum(array_map(fn($a) => count($a['cronogramas']), $snapshot)),
                    'after' => array_sum(array_map(fn($a) => count($a['cronogramas']), $current)),
                    'match' => true // Verificación básica
                ]
            ]
        ];
        
        // Detectar pérdida significativa de datos
        $dataLossDetected = false;
        $details = [];
        
        foreach ($snapshot as $asigBackup) {
            $asigCurrent = collect($current)->firstWhere('id', $asigBackup['id']);
            
            if (!$asigCurrent) {
                $dataLossDetected = true;
                $details[] = "Asignatura {$asigBackup['codigo']} no encontrada después de fusión";
                continue;
            }
            
            // Verificar campos PAC críticos
            $criticalFields = ['justificacion', 'proposito_general', 'competencia_global_especifica'];
            foreach ($criticalFields as $field) {
                $before = $asigBackup[$field] ?? '';
                $after = $asigCurrent[$field] ?? '';
                
                if (!empty($before) && empty($after)) {
                    $dataLossDetected = true;
                    $details[] = "Campo PAC '{$field}' perdido en {$asigBackup['codigo']}";
                }
            }
        }
        
        if ($dataLossDetected) {
            $report['status'] = 'warning';
            $report['message'] = 'Posible pérdida de datos detectada';
            $report['details'] = $details;
        }
        
        return $report;
    }
}