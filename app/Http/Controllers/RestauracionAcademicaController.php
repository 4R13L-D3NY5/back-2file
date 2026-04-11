<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RestauracionAcademicaController extends Controller
{
    /**
     * Restaura una asignatura basándose en el JSON exportado desde la API central.
     * Solo para usuarios con rol DIRECTOR o ADMIN.
     */
    public function restaurarAsignatura(Request $request)
    {
        $request->validate([
            'codigo' => 'required|string',
            'docentes' => 'nullable|array'
        ]);

        $data = $request->all();
        $codigo = $data['codigo'];
        $docentesExternos = $data['docentes'] ?? [];

        try {
            DB::beginTransaction();

            // 1. Encontrar o crear la asignatura localmente basándonos en el código (Sigla)
            $asignatura = DB::table('asignaturas')->where('codigo', $codigo)->whereNull('deleted_at')->first();
            
            $asignaturaId = null;
            $asignaturaData = [
                'nombre' => $data['nombre'] ?? ($asignatura->nombre ?? 'Sin nombre'),
                'sigla' => $data['sigla'] ?? ($asignatura->sigla ?? null),
                'plan_estudios' => $data['plan_estudios'] ?? ($asignatura->plan_estudios ?? null),
                'descripcion' => $data['descripcion'] ?? ($asignatura->descripcion ?? null),
                'justificacion' => $data['justificacion'] ?? ($asignatura->justificacion ?? null),
                'proposito_general' => $data['proposito_general'] ?? ($asignatura->proposito_general ?? null),
                'metodologia_general' => $data['metodologia_general'] ?? ($asignatura->metodologia_general ?? null),
                'sistema_evaluacion' => $data['sistema_evaluacion'] ?? ($asignatura->sistema_evaluacion ?? null),
                'contenido_minimo' => $data['contenido_minimo'] ?? ($asignatura->contenido_minimo ?? null),
                'requisitos' => $data['requisitos'] ?? ($asignatura->requisitos ?? null),
                'competencia_asignatura' => $data['competencia_asignatura'] ?? ($asignatura->competencia_asignatura ?? null),
                'elementos_competencia' => $data['elementos_competencia'] ?? ($asignatura->elementos_competencia ?? null),
                'updated_at' => now()
            ];

            if (!$asignatura) {
                // Crear si no existe
                $asignaturaData['codigo'] = $codigo;
                $asignaturaData['created_at'] = now();
                $asignaturaId = DB::table('asignaturas')->insertGetId($asignaturaData);
            } else {
                $asignaturaId = $asignatura->id;
                DB::table('asignaturas')->where('id', $asignaturaId)->update($asignaturaData);
            }

            // 2. Limpieza profunda y en cascada de las estructuras anteriores de esta asignatura
            $currentUnitsIds = DB::table('unidades')->where('asignatura_id', $asignaturaId)->pluck('id');
            if ($currentUnitsIds->isNotEmpty()) {
                $currentTemasIds = DB::table('temas')->whereIn('unidad_id', $currentUnitsIds)->pluck('id');
                if ($currentTemasIds->isNotEmpty()) {
                    $currentLogrosIds = DB::table('logros_esperados')->whereIn('tema_id', $currentTemasIds)->pluck('id');
                    if ($currentLogrosIds->isNotEmpty()) {
                        DB::table('indicadores')->whereIn('logro_esperado_id', $currentLogrosIds)->delete();
                        DB::table('logros_esperados')->whereIn('tema_id', $currentTemasIds)->delete();
                    }
                    DB::table('tema_bibliografia')->whereIn('tema_id', $currentTemasIds)->delete();
                    DB::table('planificaciones_personales')->whereIn('tema_id', $currentTemasIds)->delete();
                    DB::table('temas')->whereIn('unidad_id', $currentUnitsIds)->delete();
                }
                DB::table('unidades')->where('asignatura_id', $asignaturaId)->delete();
            }

            // Mapeo temporal de docentes externos a IDs internos
            // El API dice tener un arreglo "docentes" con {"user_id": X, "email": Y}
            $userIdMap = [];
            foreach ($docentesExternos as $docExt) {
                if (!empty($docExt['email']) && !empty($docExt['user_id'])) {
                    // Buscar usuario local por email
                    $localUser = DB::table('users')->where('email', $docExt['email'])->first();
                    if ($localUser) {
                        $userIdMap[$docExt['user_id']] = $localUser->id;
                    }
                }
            }

            // Helper para convertir a JSON de ser necesario
            $toJson = function($val) {
                if (is_array($val) || is_object($val)) {
                    return json_encode($val, JSON_UNESCAPED_UNICODE);
                }
                return $val;
            };

            // 3. Recrear estructura desde el JSON
            if (isset($data['unidades']) && is_array($data['unidades'])) {
                foreach ($data['unidades'] as $uni) {
                    $newUnitId = DB::table('unidades')->insertGetId([
                        'asignatura_id' => $asignaturaId,
                        'numero' => $uni['numero'] ?? null,
                        'titulo' => $uni['titulo'] ?? '',
                        'tipo' => $uni['tipo'] ?? null,
                        'objetivo' => $uni['objetivo'] ?? null,
                        'contenido_minimo' => $uni['contenido_minimo'] ?? null,
                        'elemento_competencia' => $uni['elemento_competencia'] ?? null,
                        'orden' => $uni['orden'] ?? $uni['numero'] ?? 0,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);

                    if (isset($uni['temas']) && is_array($uni['temas'])) {
                        foreach ($uni['temas'] as $tema) {
                            $newTemaId = DB::table('temas')->insertGetId([
                                'unidad_id' => $newUnitId,
                                'orden' => $tema['orden'] ?? 0,
                                'titulo' => $tema['titulo'] ?? '',
                                'tipo' => $tema['tipo'] ?? null,
                                'resultado_aprendizaje' => $tema['resultado_aprendizaje'] ?? null,
                                'contenido_conceptual' => $toJson($tema['contenido_conceptual'] ?? null),
                                'contenido_procedimental' => $toJson($tema['contenido_procedimental'] ?? null),
                                'contenido_actitudinal' => $toJson($tema['contenido_actitudinal'] ?? null),
                                'horas_practicas' => $tema['horas_practicas'] ?? null,
                                'horas_teoricas' => $tema['horas_teoricas'] ?? null,
                                'estrategias_metodologicas' => $tema['estrategias_metodologicas'] ?? null,
                                'estrategias_aprendizaje' => $tema['estrategias_aprendizaje'] ?? null,
                                'estrategias_recursos' => $toJson($tema['estrategias_recursos'] ?? null),
                                'evaluacion_formativa' => $toJson($tema['evaluacion_formativa'] ?? null),
                                'evaluacion_sumativa' => $toJson($tema['evaluacion_sumativa'] ?? null),
                                'contenido_items' => $toJson($tema['contenido_items'] ?? null),
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);

                            // Logros Esperados e Indicadores
                            if (isset($tema['logros_esperados']) && is_array($tema['logros_esperados'])) {
                                foreach ($tema['logros_esperados'] as $logro) {
                                    $newLogroId = DB::table('logros_esperados')->insertGetId([
                                        'tema_id' => $newTemaId,
                                        'descripcion' => $logro['descripcion'] ?? '',
                                        'tipo_logro' => $logro['tipo_logro'] ?? null,
                                        'periodo' => $logro['periodo'] ?? null,
                                        'created_at' => now(),
                                        'updated_at' => now()
                                    ]);

                                    if (isset($logro['indicadores']) && is_array($logro['indicadores'])) {
                                        foreach ($logro['indicadores'] as $ind) {
                                            DB::table('indicadores')->insert([
                                                'logro_esperado_id' => $newLogroId,
                                                'descripcion' => $ind['descripcion'] ?? '',
                                                'created_at' => now(),
                                                'updated_at' => now()
                                            ]);
                                        }
                                    }
                                }
                            }

                            // Bibliografías
                            if (isset($tema['bibliografias']) && is_array($tema['bibliografias'])) {
                                foreach ($tema['bibliografias'] as $bib) {
                                    // Buscar ignorando minúsculas/mayúsculas o crear si no existe
                                    $currBib = DB::table('bibliografias')
                                        ->where('titulo', $bib['titulo'] ?? '')
                                        ->where('autor', $bib['autor'] ?? '')
                                        ->first();
                                    
                                    $bibId = null;
                                    if ($currBib) {
                                        $bibId = $currBib->id;
                                    } else {
                                        $bibId = DB::table('bibliografias')->insertGetId([
                                            'titulo' => $bib['titulo'] ?? '',
                                            'autor' => $bib['autor'] ?? '',
                                            'edicion' => $bib['edicion'] ?? '',
                                            'tipo' => $bib['tipo'] ?? 'BASICA',
                                            'created_at' => now(),
                                            'updated_at' => now()
                                        ]);
                                    }

                                    // Llenar tabla pivote
                                    DB::table('tema_bibliografia')->insert([
                                        'tema_id' => $newTemaId,
                                        'bibliografia_id' => $bibId,
                                        'pagina_desde' => $bib['pivot']['pagina_desde'] ?? null,
                                        'pagina_hasta' => $bib['pivot']['pagina_hasta'] ?? null,
                                        'created_at' => now(),
                                        'updated_at' => now()
                                    ]);
                                }
                            }

                            // Planificaciones Personales
                            if (isset($tema['planificaciones_personales']) && is_array($tema['planificaciones_personales'])) {
                                foreach ($tema['planificaciones_personales'] as $pp) {
                                    // Buscar usuario interno que corresponde al user_id externo
                                    $extUserId = $pp['user_id'] ?? null;
                                    $localUserId = null;

                                    if ($extUserId && isset($userIdMap[$extUserId])) {
                                        $localUserId = $userIdMap[$extUserId];
                                    }

                                    if ($localUserId) {
                                        DB::table('planificaciones_personales')->insert([
                                            'tema_id' => $newTemaId,
                                            'user_id' => $localUserId,
                                            'estrategias_metodologicas' => $pp['estrategias_metodologicas'] ?? null,
                                            'estrategias_aprendizaje' => $pp['estrategias_aprendizaje'] ?? null,
                                            'estrategias_recursos' => $toJson($pp['estrategias_recursos'] ?? null),
                                            'evaluacion_formativa' => $toJson($pp['evaluacion_formativa'] ?? null),
                                            'evaluacion_sumativa' => $toJson($pp['evaluacion_sumativa'] ?? null),
                                            'secuencia_didactica' => $toJson($pp['secuencia_didactica'] ?? null),
                                            'created_at' => now(),
                                            'updated_at' => now()
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                }
            }

            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Asignatura y programa analítico restaurados correctamente.',
                'asignatura_id' => $asignaturaId
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Restauración de asignatura fallida: ' . $e->getMessage() . " File: " . $e->getFile() . " Line: " . $e->getLine());
            return response()->json([
                'status' => 'error',
                'message' => 'Hubo un error al restaurar la asignatura: ' . $e->getMessage()
            ], 500);
        }
    }
}
