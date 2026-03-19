<?php

namespace App\Http\Controllers;

use App\Models\Sede;
use App\Models\SyncLog;
use App\Services\PlanningSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncController extends Controller
{
    const PLANNING_API = 'http://181.188.185.211:9098/api/Grupos/listar/';

    const CARRERAS = [
        'CARADM', 'CARAYE', 'CARBYF', 'CARCAD', 'CARCCP', 'CARCIC',
        'CARCNE', 'CARCPU', 'CARCSO', 'CARDER', 'CARECO', 'CARELE',
        'CARENL', 'CARFIS', 'CARFON', 'CARIBI', 'CARICO', 'CARMED',
        'CARNYD', 'CARODO', 'CARPRO', 'CARSIS', 'CARSON', 'CARVET',
    ];

    /**
     * Sincronizar una carrera específica en una sede específica.
     * POST /api/sync/carrera
     * Body: { gestion, sede_id, carrera }
     */
    public function syncCarrera(Request $request)
    {
        $request->validate([
            'gestion'  => 'required|string',
            'sede_id'  => 'required|integer|exists:sedes,id',
            'carrera'  => 'required|string|in:' . implode(',', self::CARRERAS),
        ]);

        $sede    = Sede::findOrFail($request->sede_id);
        $carrera = strtoupper($request->carrera);
        $gestion = $request->gestion;

        $inicio = microtime(true);

        try {
            $stats = $this->callApiAndSync($gestion, $sede, $carrera);

            $duracion = round(microtime(true) - $inicio, 2);

            $log = SyncLog::create([
                'sede_id'               => $sede->id,
                'carrera'               => $carrera,
                'gestion'               => $gestion,
                'modo'                  => 'carrera',
                'estado'                => 'ok',
                'total_registros'       => $stats['total'],
                'docentes_creados'      => $stats['docentes'],
                'grupos_creados'        => $stats['grupos'],
                'horarios_actualizados' => $stats['horarios'],
                'diff_data'             => $stats['diff'] ?? null,
                'duracion_segundos'     => $duracion,
                'user_id'               => Auth::id(),
            ]);

            return response()->json([
                'ok'      => true,
                'sede'    => $sede->nombre,
                'carrera' => $carrera,
                'gestion' => $gestion,
                'stats'   => $stats,
                'diff'    => $stats['diff'] ?? null,
                'log_id'  => $log->id,
                'duracion'=> $duracion,
            ]);
        } catch (\Throwable $e) {
            $duracion = round(microtime(true) - $inicio, 2);
            Log::error("SyncController::syncCarrera error: {$e->getMessage()}");

            SyncLog::create([
                'sede_id'           => $sede->id,
                'carrera'           => $carrera,
                'gestion'           => $gestion,
                'modo'              => 'carrera',
                'estado'            => 'error',
                'error_mensaje'     => $e->getMessage(),
                'duracion_segundos' => $duracion,
                'user_id'           => Auth::id(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sincronizar todas las carreras de una sede.
     * POST /api/sync/sede
     * Body: { gestion, sede_id }
     * Devuelve resultado carrera por carrera.
     */
    public function syncSede(Request $request)
    {
        $request->validate([
            'gestion' => 'required|string',
            'sede_id' => 'required|integer|exists:sedes,id',
        ]);

        $sede    = Sede::findOrFail($request->sede_id);
        $gestion = $request->gestion;
        $results = [];
        $inicio  = microtime(true);

        foreach (self::CARRERAS as $carrera) {
            $t = microtime(true);
            try {
                $stats = $this->callApiAndSync($gestion, $sede, $carrera);
                $dur   = round(microtime(true) - $t, 2);

                $log = SyncLog::create([
                    'sede_id'               => $sede->id,
                    'carrera'               => $carrera,
                    'gestion'               => $gestion,
                    'modo'                  => 'sede',
                    'estado'                => 'ok',
                    'total_registros'       => $stats['total'],
                    'docentes_creados'      => $stats['docentes'],
                    'grupos_creados'        => $stats['grupos'],
                    'horarios_actualizados' => $stats['horarios'],
                    'diff_data'             => $stats['diff'] ?? null,
                    'duracion_segundos'     => $dur,
                    'user_id'               => Auth::id(),
                ]);

                $results[$carrera] = ['ok' => true, 'stats' => $stats, 'log_id' => $log->id, 'duracion' => $dur];
            } catch (\Throwable $e) {
                $dur = round(microtime(true) - $t, 2);
                Log::error("SyncController::syncSede [{$carrera}] error: {$e->getMessage()}");

                SyncLog::create([
                    'sede_id'           => $sede->id,
                    'carrera'           => $carrera,
                    'gestion'           => $gestion,
                    'modo'              => 'sede',
                    'estado'            => 'error',
                    'error_mensaje'     => $e->getMessage(),
                    'duracion_segundos' => $dur,
                    'user_id'           => Auth::id(),
                ]);

                $results[$carrera] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        $totalDur = round(microtime(true) - $inicio, 2);
        $ok       = collect($results)->filter(fn($r) => $r['ok'])->count();
        $err      = count($results) - $ok;

        return response()->json([
            'ok'       => $err === 0,
            'sede'     => $sede->nombre,
            'gestion'  => $gestion,
            'results'  => $results,
            'resumen'  => ['ok' => $ok, 'error' => $err, 'total' => count($results)],
            'duracion' => $totalDur,
        ]);
    }

    /**
     * Sincronizar una carrera en todas las sedes.
     * POST /api/sync/materia
     * Body: { gestion, carrera }
     */
    public function syncMateria(Request $request)
    {
        $request->validate([
            'gestion' => 'required|string',
            'carrera' => 'required|string|in:' . implode(',', self::CARRERAS),
        ]);

        $carrera = strtoupper($request->carrera);
        $gestion = $request->gestion;
        $sedes   = Sede::where('activo', true)->get();
        $results = [];
        $inicio  = microtime(true);

        foreach ($sedes as $sede) {
            $t = microtime(true);
            try {
                $stats = $this->callApiAndSync($gestion, $sede, $carrera);
                $dur   = round(microtime(true) - $t, 2);

                $log = SyncLog::create([
                    'sede_id'               => $sede->id,
                    'carrera'               => $carrera,
                    'gestion'               => $gestion,
                    'modo'                  => 'materia',
                    'estado'                => $stats['total'] > 0 ? 'ok' : 'parcial',
                    'total_registros'       => $stats['total'],
                    'docentes_creados'      => $stats['docentes'],
                    'grupos_creados'        => $stats['grupos'],
                    'horarios_actualizados' => $stats['horarios'],
                    'diff_data'             => $stats['diff'] ?? null,
                    'duracion_segundos'     => $dur,
                    'user_id'               => Auth::id(),
                ]);

                $results[$sede->nombre] = [
                    'ok'      => true,
                    'sede_id' => $sede->id,
                    'log_id'  => $log->id,
                    'stats'   => $stats,
                    'duracion'=> $dur,
                ];
            } catch (\Throwable $e) {
                $dur = round(microtime(true) - $t, 2);
                Log::error("SyncController::syncMateria [{$sede->nombre}] error: {$e->getMessage()}");

                SyncLog::create([
                    'sede_id'           => $sede->id,
                    'carrera'           => $carrera,
                    'gestion'           => $gestion,
                    'modo'              => 'materia',
                    'estado'            => 'error',
                    'error_mensaje'     => $e->getMessage(),
                    'duracion_segundos' => $dur,
                    'user_id'           => Auth::id(),
                ]);

                $results[$sede->nombre] = [
                    'ok'      => false,
                    'sede_id' => $sede->id,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        $totalDur = round(microtime(true) - $inicio, 2);
        $ok       = collect($results)->filter(fn($r) => $r['ok'])->count();
        $err      = count($results) - $ok;

        return response()->json([
            'ok'       => $err === 0,
            'carrera'  => $carrera,
            'gestion'  => $gestion,
            'results'  => $results,
            'resumen'  => ['ok' => $ok, 'error' => $err, 'total' => count($results)],
            'duracion' => $totalDur,
        ]);
    }

    /**
     * Obtener historial de sincronizaciones.
     * GET /api/sync/logs
     */
    public function getLogs(Request $request)
    {
        $query = SyncLog::with('sede', 'user')
            ->orderBy('created_at', 'desc');

        if ($request->sede_id) {
            $query->where('sede_id', $request->sede_id);
        }
        if ($request->carrera) {
            $query->where('carrera', $request->carrera);
        }
        if ($request->modo) {
            $query->where('modo', $request->modo);
        }

        $logs = $query->limit($request->limit ?? 50)->get()->map(fn($l) => [
            'id'                    => $l->id,
            'sede'                  => $l->sede?->nombre ?? '—',
            'sede_id'               => $l->sede_id,
            'carrera'               => $l->carrera ?? 'TODAS',
            'gestion'               => $l->gestion,
            'modo'                  => $l->modo,
            'estado'                => $l->estado,
            'total_registros'       => $l->total_registros,
            'docentes_creados'      => $l->docentes_creados,
            'grupos_creados'        => $l->grupos_creados,
            'horarios_actualizados' => $l->horarios_actualizados,
            'error_mensaje'         => $l->error_mensaje,
            'duracion_segundos'     => $l->duracion_segundos,
            'usuario'               => $l->user ? ($l->user->nombre . ' ' . $l->user->apellido) : '—',
            'fecha'                 => $l->created_at->format('d/m/Y H:i'),
            // Indica si tiene diff guardado y cuántos cambios totales hubo
            'has_diff'              => !is_null($l->diff_data),
            'total_cambios'         => $l->diff_data['resumen']['total_cambios'] ?? 0,
        ]);

        return response()->json(['logs' => $logs]);
    }

    /**
     * Obtener el diff detallado de un sync específico.
     * GET /api/sync/logs/{id}/diff
     */
    public function getDiff(int $id)
    {
        $log = SyncLog::with('sede', 'user')->findOrFail($id);

        return response()->json([
            'id'       => $log->id,
            'sede'     => $log->sede?->nombre ?? '—',
            'carrera'  => $log->carrera,
            'gestion'  => $log->gestion,
            'fecha'    => $log->created_at->format('d/m/Y H:i:s'),
            'estado'   => $log->estado,
            'diff'     => $log->diff_data,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVADO: llama a la API externa y procesa con PlanningSyncService
    // ─────────────────────────────────────────────────────────────────────────

    private function callApiAndSync(string $gestion, Sede $sede, string $carrera): array
    {
        $apiSedeId = $sede->id_api ?? $sede->id;

        $response = Http::timeout(120)
            ->retry(2, 3000)
            ->get(self::PLANNING_API, [
                'gestion' => $gestion,
                'sede'    => $apiSedeId,
                'carrera' => $carrera,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                "API respondió con status {$response->status()} para {$carrera} sede {$sede->nombre}"
            );
        }

        $items = $response->json();

        if (!is_array($items) || empty($items)) {
            return ['total' => 0, 'docentes' => 0, 'grupos' => 0, 'horarios' => 0, 'diff' => null];
        }

        // ── Snapshot ANTES ──────────────────────────────────────────────────
        $snapshotAntes = $this->capturarSnapshot($sede->id, $carrera);

        $service = app(\App\Services\PlanningSyncService::class);
        $stats   = $service->syncBatch($items);

        // ── Snapshot DESPUÉS ─────────────────────────────────────────────────
        $snapshotDespues = $this->capturarSnapshot($sede->id, $carrera);

        // ── Generar Diff ─────────────────────────────────────────────────────
        $diff = $this->generarDiff($snapshotAntes, $snapshotDespues);

        return [
            'total'    => count($items),
            'docentes' => $stats['docentes']      ?? 0,
            'grupos'   => $stats['grupos']        ?? 0,
            'horarios' => $stats['horarios']      ?? 0,
            'usuarios' => $stats['users_created'] ?? 0,
            'errores'  => $stats['errors']        ?? 0,
            'diff'     => $diff,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DIFF: captura el estado actual de la BD para sede+carrera
    // ─────────────────────────────────────────────────────────────────────────

    private function capturarSnapshot(int $sedeId, string $carrera): array
    {
        // Carrera local
        $carreraModel = \App\Models\Carrera::where('sigla', $carrera)->first();
        $carreraId    = $carreraModel?->id;

        // Grupos: snapshot de docente asignado y estado
        $grupos = \App\Models\Grupo::with('docente', 'horarios')
            ->where('sede_id', $sedeId)
            ->when($carreraId, fn($q) => $q->where('carrera_id', $carreraId))
            ->get()
            ->keyBy('id')
            ->map(fn($g) => [
                'id'           => $g->id,
                'nombre'       => $g->nombre,
                'tipo'         => $g->tipo,
                'asignatura'   => $g->asignatura?->nombre ?? $g->asignatura_id,
                'docente_id'   => $g->docente_id,
                'docente'      => $g->docente?->nombre_completo ?? null,
                'estado'       => $g->estado,
                'horarios'     => $g->horarios->map(fn($h) => [
                    'id'          => $h->id,
                    'dia'         => $h->dia,
                    'hora_inicio' => $h->hora_inicio,
                    'hora_fin'    => $h->hora_fin,
                ])->toArray(),
            ])->toArray();

        // Docentes existentes en esta sede
        $docentes = \App\Models\Docente::where('sede_id', $sedeId)
            ->pluck('nombre_completo', 'id')
            ->toArray();

        // Asignaturas vinculadas a esta carrera/sede
        $asignaturas = \App\Models\Asignatura::whereHas('carreras', fn($q) =>
                $q->where('carreras.id', $carreraId)
                  ->where('asignatura_carrera.sede_id', $sedeId)
            )
            ->pluck('nombre', 'id')
            ->toArray();

        return [
            'grupos'      => $grupos,
            'docentes'    => $docentes,
            'asignaturas' => $asignaturas,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DIFF: compara antes y después, genera estructura legible
    // ─────────────────────────────────────────────────────────────────────────

    private function generarDiff(array $antes, array $despues): array
    {
        $diff = [
            'grupos_nuevos'          => [],
            'grupos_docente_cambio'  => [],
            'grupos_horario_cambio'  => [],
            'grupos_eliminados'      => [],
            'horarios_eliminados'    => [],
            'docentes_nuevos'        => [],
            'asignaturas_nuevas'     => [],
            'resumen'                => [],
        ];

        $idsAntes   = array_keys($antes['grupos']);
        $idsDespues = array_keys($despues['grupos']);

        // ── Grupos nuevos ────────────────────────────────────────────────────
        $nuevosIds = array_diff($idsDespues, $idsAntes);
        foreach ($nuevosIds as $id) {
            $g = $despues['grupos'][$id];
            $diff['grupos_nuevos'][] = [
                'grupo'      => $g['nombre'] . ' (' . $g['tipo'] . ')',
                'asignatura' => $g['asignatura'],
                'docente'    => $g['docente'] ?? 'Sin asignar',
            ];
        }

        // ── Grupos eliminados (ya no existen en API) ─────────────────────────
        $eliminadosIds = array_diff($idsAntes, $idsDespues);
        foreach ($eliminadosIds as $id) {
            $g = $antes['grupos'][$id];
            $diff['grupos_eliminados'][] = [
                'grupo'      => $g['nombre'] . ' (' . $g['tipo'] . ')',
                'asignatura' => $g['asignatura'],
                'docente'    => $g['docente'] ?? 'Sin asignar',
            ];
        }

        // ── Cambios en grupos existentes ─────────────────────────────────────
        $comunes = array_intersect($idsAntes, $idsDespues);
        foreach ($comunes as $id) {
            $ga = $antes['grupos'][$id];
            $gd = $despues['grupos'][$id];

            // Cambio de docente
            if ($ga['docente_id'] !== $gd['docente_id']) {
                $diff['grupos_docente_cambio'][] = [
                    'grupo'           => $gd['nombre'] . ' (' . $gd['tipo'] . ')',
                    'asignatura'      => $gd['asignatura'],
                    'docente_antes'   => $ga['docente'] ?? 'Sin asignar',
                    'docente_despues' => $gd['docente'] ?? 'Sin asignar',
                ];
            }

            // Cambios en horarios
            $horasAntes   = collect($ga['horarios'])->keyBy('id');
            $horasDespues = collect($gd['horarios'])->keyBy('id');

            // Horarios eliminados
            foreach ($horasAntes as $hid => $ha) {
                if (!$horasDespues->has($hid)) {
                    $diff['horarios_eliminados'][] = [
                        'grupo'      => $gd['nombre'] . ' (' . $gd['tipo'] . ')',
                        'asignatura' => $gd['asignatura'],
                        'horario'    => $ha['dia'] . ' ' . $ha['hora_inicio'] . '-' . $ha['hora_fin'],
                    ];
                }
            }

            // Horarios modificados o nuevos
            foreach ($horasDespues as $hid => $hd) {
                $ha = $horasAntes->get($hid);
                if (!$ha) {
                    // Horario nuevo dentro de grupo existente
                    $diff['grupos_horario_cambio'][] = [
                        'grupo'      => $gd['nombre'] . ' (' . $gd['tipo'] . ')',
                        'asignatura' => $gd['asignatura'],
                        'tipo'       => 'nuevo',
                        'antes'      => null,
                        'despues'    => $hd['dia'] . ' ' . $hd['hora_inicio'] . '-' . $hd['hora_fin'],
                    ];
                } elseif ($ha['dia'] !== $hd['dia'] || $ha['hora_inicio'] !== $hd['hora_inicio'] || $ha['hora_fin'] !== $hd['hora_fin']) {
                    $diff['grupos_horario_cambio'][] = [
                        'grupo'      => $gd['nombre'] . ' (' . $gd['tipo'] . ')',
                        'asignatura' => $gd['asignatura'],
                        'tipo'       => 'modificado',
                        'antes'      => $ha['dia'] . ' ' . $ha['hora_inicio'] . '-' . $ha['hora_fin'],
                        'despues'    => $hd['dia'] . ' ' . $hd['hora_inicio'] . '-' . $hd['hora_fin'],
                    ];
                }
            }
        }

        // ── Docentes nuevos ──────────────────────────────────────────────────
        $docNuevosIds = array_diff(array_keys($despues['docentes']), array_keys($antes['docentes']));
        foreach ($docNuevosIds as $id) {
            $diff['docentes_nuevos'][] = $despues['docentes'][$id];
        }

        // ── Asignaturas nuevas ───────────────────────────────────────────────
        $asigNuevasIds = array_diff(array_keys($despues['asignaturas']), array_keys($antes['asignaturas']));
        foreach ($asigNuevasIds as $id) {
            $diff['asignaturas_nuevas'][] = $despues['asignaturas'][$id];
        }

        // ── Resumen ──────────────────────────────────────────────────────────
        $diff['resumen'] = [
            'grupos_nuevos'         => count($diff['grupos_nuevos']),
            'grupos_eliminados'     => count($diff['grupos_eliminados']),
            'cambios_docente'       => count($diff['grupos_docente_cambio']),
            'cambios_horario'       => count($diff['grupos_horario_cambio']),
            'horarios_eliminados'   => count($diff['horarios_eliminados']),
            'docentes_nuevos'       => count($diff['docentes_nuevos']),
            'asignaturas_nuevas'    => count($diff['asignaturas_nuevas']),
            'total_cambios'         => count($diff['grupos_nuevos'])
                                     + count($diff['grupos_eliminados'])
                                     + count($diff['grupos_docente_cambio'])
                                     + count($diff['grupos_horario_cambio'])
                                     + count($diff['horarios_eliminados'])
                                     + count($diff['docentes_nuevos'])
                                     + count($diff['asignaturas_nuevas']),
        ];

        return $diff;
    }
}
