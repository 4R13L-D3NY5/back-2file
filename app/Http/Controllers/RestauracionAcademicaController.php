<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RestauracionAcademicaController extends Controller
{
    /**
     * Extrae asignaturas desde una API externa usando el backend local como proxy.
     * Evita bloqueos CORS en navegador al hacer la solicitud server-to-server.
     */
    public function extraerDesdeApiExterna(Request $request)
    {
        $validated = $request->validate([
            'api_url' => 'required|url',
            'token' => 'nullable|string',
            'carrera_id' => 'required|integer',
            'sede_id' => 'nullable|integer',
        ]);

        $baseUrl = rtrim((string) $validated['api_url'], '/');
        $path = parse_url($baseUrl, PHP_URL_PATH) ?: '';
        $alreadyIncludesEndpoint = str_contains($path, '/api/export/documentacion-carrera');

        $targetUrl = $alreadyIncludesEndpoint
            ? $baseUrl
            : $baseUrl . '/api/export/documentacion-carrera';

        $token = trim((string) ($validated['token'] ?? ''));
        $query = [
            'carrera_id' => (int) $validated['carrera_id'],
            'token' => $token,
        ];

        if (!empty($validated['sede_id'])) {
            $query['sede_id'] = (int) $validated['sede_id'];
        }

        try {
            $requestBuilder = Http::timeout(45)->acceptJson();
            if ($token !== '') {
                $requestBuilder = $requestBuilder->withToken($token);
            }

            $response = $requestBuilder->get($targetUrl, $query);

            if (!$response->successful()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La API externa respondio con error.',
                    'http_status' => $response->status(),
                    'endpoint' => $targetUrl,
                    'details' => $response->body(),
                ], 502);
            }

            $payload = $response->json();
            if (!is_array($payload)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La API externa no devolvio JSON valido.',
                    'endpoint' => $targetUrl,
                ], 502);
            }

            return response()->json($payload, 200);
        } catch (\Throwable $e) {
            Log::error('Extraccion externa fallida en restauracion', [
                'endpoint' => $targetUrl,
                'carrera_id' => $validated['carrera_id'] ?? null,
                'sede_id' => $validated['sede_id'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo consultar la API externa desde el backend.',
            ], 500);
        }
    }

    /**
     * Restaura una asignatura basandose en el JSON exportado desde otra instancia.
     * El reemplazo es total y transaccional.
     */
    public function restaurarAsignatura(Request $request)
    {
        $validated = $request->validate([
            'asignatura_id' => 'nullable|integer',
            'codigo' => 'required|string',
            'nombre' => 'nullable|string',
            'carrera_id' => 'nullable|integer|exists:carreras,id',
            'sede_id' => 'nullable|integer|exists:sedes,id',
            'plan_estudios' => 'nullable|string|max:10',
            'semestre' => 'nullable',
            'docentes' => 'nullable|array',
            'unidades' => 'nullable|array',
            'bibliografias' => 'nullable|array',
        ]);

        $data = $request->all();
        $planEstudios = $this->resolvePlanEstudios($data);
        $docentesExternos = $data['docentes'] ?? [];
        $stats = [
            'unidades_restauradas' => 0,
            'temas_restaurados' => 0,
            'planificaciones_restauradas' => 0,
            'planificaciones_omitidas' => 0,
            'bibliografias_restauradas' => 0,
        ];

        try {
            DB::beginTransaction();

            $asignatura = !empty($validated['asignatura_id'])
                ? $this->findTargetAsignaturaById((int) $validated['asignatura_id'], $validated['codigo'])
                : $this->findTargetAsignatura(
                    $validated['codigo'],
                    $validated['carrera_id'] ?? null,
                    $validated['sede_id'] ?? null,
                    $planEstudios,
                );

            $identity = $this->resolveRestoreTargetIdentity(
                $asignatura,
                $validated,
                $data,
                $planEstudios,
            );

            $asignaturaData = [
                'nombre' => $identity['nombre'],
                'sigla' => $identity['sigla'],
                'plan_estudios' => $identity['plan_estudios'],
                'descripcion' => $this->extractRestoreField($data, 'descripcion'),
                'justificacion' => $this->extractRestoreField($data, 'justificacion'),
                'proposito_general' => $this->extractRestoreField($data, 'proposito_general'),
                'metodologia_general' => $this->extractRestoreField($data, 'metodologia_general', true),
                'sistema_evaluacion' => $this->extractRestoreField($data, 'sistema_evaluacion', true),
                'contenido_minimo' => $this->extractRestoreField($data, 'contenido_minimo'),
                'requisitos' => $this->extractRestoreField($data, 'requisitos'),
                'competencia_asignatura' => $this->extractRestoreField($data, 'competencia_asignatura'),
                'competencia_global_especifica' => $this->extractRestoreField($data, 'competencia_global_especifica'),
                'elementos_competencia' => $this->extractRestoreField($data, 'elementos_competencia', true),
                'modificado_localmente' => true,
                'updated_at' => now(),
            ];

            if (!$asignatura) {
                $asignaturaData['codigo'] = $identity['codigo'];
                $asignaturaData['created_at'] = now();
                $asignaturaId = DB::table('asignaturas')->insertGetId($asignaturaData);
            } else {
                $asignaturaId = $asignatura->id;
                DB::table('asignaturas')->where('id', $asignaturaId)->update($asignaturaData);
            }

            $this->syncCarreraPivot(
                $asignaturaId,
                $validated['carrera_id'] ?? null,
                $validated['sede_id'] ?? null,
                $data['semestre'] ?? null,
            );

            $this->cleanupAsignaturaStructure($asignaturaId);

            $resolverCache = [
                'ci' => [],
                'email' => [],
            ];
            $userIdMap = $this->buildUserIdMap($docentesExternos, $resolverCache);
            $bibliografiaCache = [];

            foreach ($data['bibliografias'] ?? [] as $bibliografiaGeneral) {
                $this->storeBibliografia($asignaturaId, $bibliografiaGeneral, $bibliografiaCache);
                $stats['bibliografias_restauradas']++;
            }

            foreach ($data['unidades'] ?? [] as $indexUnidad => $unidad) {
                $newUnitId = DB::table('unidades')->insertGetId([
                    'asignatura_id' => $asignaturaId,
                    'numero' => $unidad['numero'] ?? (string) ($indexUnidad + 1),
                    'titulo' => $unidad['titulo'] ?? 'Sin titulo',
                    'tipo' => $unidad['tipo'] ?? null,
                    'objetivo' => $unidad['objetivo'] ?? null,
                    'contenido_minimo' => $unidad['contenido_minimo'] ?? null,
                    'elemento_competencia' => $unidad['elemento_competencia'] ?? null,
                    'orden' => $unidad['orden'] ?? $unidad['numero'] ?? ($indexUnidad + 1),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $stats['unidades_restauradas']++;

                foreach ($unidad['temas'] ?? [] as $indexTema => $tema) {
                    $newTemaId = DB::table('temas')->insertGetId([
                        'unidad_id' => $newUnitId,
                        'orden' => $tema['orden'] ?? $indexTema,
                        'titulo' => $tema['titulo'] ?? 'Sin titulo',
                        'tipo' => $tema['tipo'] ?? null,
                        'resultado_aprendizaje' => $tema['resultado_aprendizaje'] ?? null,
                        'contenido_conceptual' => $this->toDatabaseValue($tema['contenido_conceptual'] ?? null),
                        'contenido_procedimental' => $this->toDatabaseValue($tema['contenido_procedimental'] ?? null),
                        'contenido_actitudinal' => $this->toDatabaseValue($tema['contenido_actitudinal'] ?? null),
                        'horas_practicas' => $tema['horas_practicas'] ?? null,
                        'horas_teoricas' => $tema['horas_teoricas'] ?? null,
                        'estrategias_metodologicas' => $tema['estrategias_metodologicas'] ?? null,
                        'estrategias_aprendizaje' => $tema['estrategias_aprendizaje'] ?? null,
                        'estrategias_recursos' => $this->toDatabaseValue($tema['estrategias_recursos'] ?? null),
                        'evaluacion_formativa' => $this->toDatabaseValue($tema['evaluacion_formativa'] ?? null),
                        'evaluacion_sumativa' => $this->toDatabaseValue($tema['evaluacion_sumativa'] ?? null),
                        'contenido_items' => $this->toDatabaseValue($tema['contenido_items'] ?? null),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $stats['temas_restaurados']++;

                    foreach ($tema['logros_esperados'] ?? [] as $logro) {
                        $newLogroId = DB::table('logros_esperados')->insertGetId([
                            'tema_id' => $newTemaId,
                            'descripcion' => $logro['descripcion'] ?? '',
                            'tipo_logro' => $logro['tipo_logro'] ?? null,
                            'periodo' => $logro['periodo'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        foreach ($logro['indicadores'] ?? [] as $indicador) {
                            DB::table('indicadores')->insert([
                                'logro_esperado_id' => $newLogroId,
                                'descripcion' => $indicador['descripcion'] ?? '',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }

                    foreach ($tema['secuencias'] ?? [] as $secuencia) {
                        if (!Schema::hasTable('secuencias_temas')) {
                            break;
                        }

                        DB::table('secuencias_temas')->insert([
                            'tema_id' => $newTemaId,
                            'momento' => $secuencia['momento'] ?? 'Desarrollo',
                            'descripcion' => $secuencia['descripcion'] ?? '',
                            'duracion_minutos' => $secuencia['duracion_minutos'] ?? 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    foreach ($tema['bibliografias'] ?? [] as $bibliografia) {
                        $bibId = $this->storeBibliografia($asignaturaId, $bibliografia, $bibliografiaCache);

                        DB::table('tema_bibliografia')->insert([
                            'tema_id' => $newTemaId,
                            'bibliografia_id' => $bibId,
                            'pagina_desde' => $bibliografia['pivot']['pagina_desde'] ?? $bibliografia['pagina_desde'] ?? null,
                            'pagina_hasta' => $bibliografia['pivot']['pagina_hasta'] ?? $bibliografia['pagina_hasta'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $stats['bibliografias_restauradas']++;
                    }

                    foreach ($tema['planificaciones_personales'] ?? [] as $planificacion) {
                        $externalUserId = $planificacion['user_id'] ?? null;
                        $localUserId = $externalUserId && isset($userIdMap[$externalUserId])
                            ? $userIdMap[$externalUserId]
                            : $this->resolveLocalUserId($planificacion, $resolverCache);

                        if (!$localUserId) {
                            $stats['planificaciones_omitidas']++;
                            continue;
                        }

                        DB::table('planificaciones_personales')->insert([
                            'tema_id' => $newTemaId,
                            'user_id' => $localUserId,
                            'estrategias_metodologicas' => $planificacion['estrategias_metodologicas'] ?? null,
                            'estrategias_aprendizaje' => $planificacion['estrategias_aprendizaje'] ?? null,
                            'estrategias_recursos' => $this->toDatabaseValue($planificacion['estrategias_recursos'] ?? null),
                            'evaluacion_formativa' => $this->toDatabaseValue($planificacion['evaluacion_formativa'] ?? null),
                            'evaluacion_sumativa' => $this->toDatabaseValue($planificacion['evaluacion_sumativa'] ?? null),
                            'secuencia_didactica' => $this->toDatabaseValue($planificacion['secuencia_didactica'] ?? null),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $stats['planificaciones_restauradas']++;
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Asignatura y programa analitico restaurados correctamente.',
                'asignatura_id' => $asignaturaId,
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Restauracion de asignatura fallida', [
                'codigo' => $validated['codigo'] ?? null,
                'carrera_id' => $validated['carrera_id'] ?? null,
                'sede_id' => $validated['sede_id'] ?? null,
                'plan_estudios' => $planEstudios,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Hubo un error al restaurar la asignatura: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function estadoAsignaturas(Request $request)
    {
        $validated = $request->validate([
            'asignaturas' => 'required|array|max:300',
            'asignaturas.*.asignatura_id' => 'nullable|integer',
            'asignaturas.*.codigo' => 'required|string',
            'asignaturas.*.restore_key' => 'nullable|string',
            'asignaturas.*.carrera_id' => 'nullable|integer',
            'asignaturas.*.sede_id' => 'nullable|integer',
            'asignaturas.*.plan_estudios' => 'nullable|string|max:10',
        ]);

        $items = collect($validated['asignaturas'] ?? [])
            ->map(function (array $item) {
                $restoreKey = $item['restore_key'] ?? $this->buildRestoreKey(
                    $item['codigo'] ?? null,
                    $item['plan_estudios'] ?? null,
                    $item['carrera_id'] ?? null,
                    $item['sede_id'] ?? null,
                );

                try {
                    $planEstudios = $this->resolvePlanEstudios($item);
                    $asignatura = !empty($item['asignatura_id'])
                        ? $this->findTargetAsignaturaById((int) $item['asignatura_id'], $item['codigo'])
                        : $this->findTargetAsignatura(
                            $item['codigo'],
                            $item['carrera_id'] ?? null,
                            $item['sede_id'] ?? null,
                            $planEstudios,
                        );

                    if (!$asignatura) {
                        return [
                            'restore_key' => $restoreKey,
                            'navigation' => null,
                            'estado' => 'no_encontrada',
                            'label' => 'No encontrada',
                            'tiene_contenido' => false,
                            'resumen' => 'No existe una asignatura local vinculada a esta combinacion.',
                            'stats' => [
                                'pac_campos' => 0,
                                'unidades' => 0,
                                'temas' => 0,
                                'bibliografias' => 0,
                                'logros' => 0,
                                'planificaciones' => 0,
                                'secuencias' => 0,
                            ],
                        ];
                    }

                    $estado = $this->buildLocalAsignaturaStatus($asignatura);
                    $navigation = $this->buildDocumentacionNavigation(
                        $asignatura,
                        isset($item['sede_id']) ? (int) $item['sede_id'] : null
                    );

                    return [
                        'restore_key' => $restoreKey,
                        'asignatura_id' => $asignatura->id,
                        'navigation' => $navigation,
                        'estado' => $estado['tiene_contenido'] ? 'restaurada' : 'base_vacia',
                        'label' => $estado['tiene_contenido'] ? 'Con contenido' : 'Base vacia',
                        'tiene_contenido' => $estado['tiene_contenido'],
                        'resumen' => $estado['resumen'],
                        'stats' => $estado['stats'],
                    ];
                } catch (\Throwable $e) {
                    return [
                        'restore_key' => $restoreKey,
                        'navigation' => null,
                        'estado' => 'ambigua',
                        'label' => 'Coincidencia ambigua',
                        'tiene_contenido' => false,
                        'resumen' => $e->getMessage(),
                        'stats' => [
                            'pac_campos' => 0,
                            'unidades' => 0,
                            'temas' => 0,
                            'bibliografias' => 0,
                            'logros' => 0,
                            'planificaciones' => 0,
                            'secuencias' => 0,
                        ],
                    ];
                }
            })
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $items,
        ]);
    }

    private function cleanupAsignaturaStructure(int $asignaturaId): void
    {
        $currentUnitsIds = DB::table('unidades')
            ->where('asignatura_id', $asignaturaId)
            ->pluck('id');

        if ($currentUnitsIds->isEmpty()) {
            DB::table('bibliografias')->where('asignatura_id', $asignaturaId)->delete();
            return;
        }

        $currentTemasIds = DB::table('temas')
            ->whereIn('unidad_id', $currentUnitsIds)
            ->pluck('id');

        if ($currentTemasIds->isNotEmpty()) {
            $currentLogrosIds = DB::table('logros_esperados')
                ->whereIn('tema_id', $currentTemasIds)
                ->pluck('id');

            if ($currentLogrosIds->isNotEmpty()) {
                DB::table('indicadores')->whereIn('logro_esperado_id', $currentLogrosIds)->delete();
                DB::table('logros_esperados')->whereIn('tema_id', $currentTemasIds)->delete();
            }

            DB::table('tema_bibliografia')->whereIn('tema_id', $currentTemasIds)->delete();
            DB::table('planificaciones_personales')->whereIn('tema_id', $currentTemasIds)->delete();

            if (Schema::hasTable('secuencias_temas')) {
                DB::table('secuencias_temas')->whereIn('tema_id', $currentTemasIds)->delete();
            }
            if (Schema::hasTable('estrategias_temas')) {
                DB::table('estrategias_temas')->whereIn('tema_id', $currentTemasIds)->delete();
            }
            if (Schema::hasTable('evaluaciones_temas')) {
                DB::table('evaluaciones_temas')->whereIn('tema_id', $currentTemasIds)->delete();
            }

            DB::table('temas')->whereIn('unidad_id', $currentUnitsIds)->delete();
        }

        DB::table('unidades')->where('asignatura_id', $asignaturaId)->delete();
        DB::table('bibliografias')->where('asignatura_id', $asignaturaId)->delete();
    }

    private function buildLocalAsignaturaStatus(object $asignatura): array
    {
        $unidadesIds = DB::table('unidades')
            ->where('asignatura_id', $asignatura->id)
            ->pluck('id');

        $temasIds = $unidadesIds->isNotEmpty()
            ? DB::table('temas')->whereIn('unidad_id', $unidadesIds)->pluck('id')
            : collect();

        $pacCampos = collect([
            $asignatura->justificacion ?? null,
            $asignatura->descripcion ?? null,
            $asignatura->proposito_general ?? null,
            $asignatura->competencia_asignatura ?? null,
            $asignatura->competencia_global_especifica ?? null,
            $asignatura->metodologia_general ?? null,
            $asignatura->sistema_evaluacion ?? null,
            $asignatura->contenido_minimo ?? null,
            $asignatura->elementos_competencia ?? null,
        ])->filter(fn ($value) => $this->hasMeaningfulValue($value))->count();

        $stats = [
            'pac_campos' => $pacCampos,
            'unidades' => $unidadesIds->count(),
            'temas' => $temasIds->count(),
            'bibliografias' => DB::table('bibliografias')
                ->where('asignatura_id', $asignatura->id)
                ->count(),
            'logros' => $temasIds->isNotEmpty()
                ? DB::table('logros_esperados')->whereIn('tema_id', $temasIds)->count()
                : 0,
            'planificaciones' => $temasIds->isNotEmpty()
                ? DB::table('planificaciones_personales')->whereIn('tema_id', $temasIds)->count()
                : 0,
            'secuencias' => Schema::hasTable('secuencias_temas') && $temasIds->isNotEmpty()
                ? DB::table('secuencias_temas')->whereIn('tema_id', $temasIds)->count()
                : 0,
        ];

        $tieneContenido = collect($stats)->contains(fn ($value) => (int) $value > 0);

        $resumen = collect([
            $stats['pac_campos'] > 0 ? $stats['pac_campos'] . ' campo(s) PAC' : null,
            $stats['unidades'] > 0 ? $stats['unidades'] . ' unidad(es)' : null,
            $stats['temas'] > 0 ? $stats['temas'] . ' tema(s)' : null,
            $stats['bibliografias'] > 0 ? $stats['bibliografias'] . ' bibliografia(s)' : null,
            $stats['planificaciones'] > 0 ? $stats['planificaciones'] . ' planificacion(es)' : null,
        ])->filter()->implode(' | ');

        return [
            'tiene_contenido' => $tieneContenido,
            'resumen' => $resumen !== '' ? $resumen : 'La asignatura local no tiene estructura restaurada.',
            'stats' => $stats,
        ];
    }

    private function buildDocumentacionNavigation(object $asignatura, ?int $requestedSedeId = null): array
    {
        $sedeContext = DB::table('asignatura_carrera as ac')
            ->leftJoin('sedes as s', 's.id', '=', 'ac.sede_id')
            ->where('ac.asignatura_id', $asignatura->id)
            ->when(
                $requestedSedeId,
                fn ($query) => $query->orderByRaw('ac.sede_id = ? DESC', [$requestedSedeId]),
                fn ($query) => $query
            )
            ->orderBy('ac.id')
            ->select('ac.sede_id', 's.nombre as sede_nombre')
            ->first();

        $resolvedSedeId = $requestedSedeId ?: ($sedeContext->sede_id ?? null);
        $resolvedSedeName = $sedeContext->sede_nombre ?? null;

        if (!$resolvedSedeName && $resolvedSedeId) {
            $resolvedSedeName = DB::table('sedes')->where('id', $resolvedSedeId)->value('nombre');
        }

        $docentes = DB::table('planificaciones_personales as pp')
            ->join('temas as t', 't.id', '=', 'pp.tema_id')
            ->join('unidades as u', 'u.id', '=', 't.unidad_id')
            ->leftJoin('users as usr', 'usr.id', '=', 'pp.user_id')
            ->leftJoin('docentes as d', 'd.user_id', '=', 'usr.id')
            ->where('u.asignatura_id', $asignatura->id)
            ->whereNotNull('d.id')
            ->select(
                'd.id as docente_id',
                'd.nombre_completo as docente_nombre',
                DB::raw("TRIM(CONCAT(COALESCE(usr.nombre, ''), ' ', COALESCE(usr.apellido, ''))) as user_name"),
                'usr.username as user_username',
                'usr.email as user_email'
            )
            ->distinct()
            ->orderBy('d.nombre_completo')
            ->get()
            ->map(fn ($docente) => [
                'docente_id' => (int) $docente->docente_id,
                'docente_nombre' => $docente->docente_nombre
                    ?: ($docente->user_name ?: ($docente->user_username ?: ($docente->user_email ?: 'Docente'))),
            ])
            ->values()
            ->all();

        return [
            'asignatura_id' => (int) $asignatura->id,
            'sede_id' => $resolvedSedeId ? (int) $resolvedSedeId : null,
            'nombre_sede' => $resolvedSedeName,
            'docentes' => $docentes,
        ];
    }

    private function syncCarreraPivot(
        int $asignaturaId,
        ?int $carreraId,
        ?int $sedeId,
        mixed $semestre
    ): void {
        if (!$carreraId || !$sedeId || !Schema::hasTable('asignatura_carrera')) {
            return;
        }

        $pivot = DB::table('asignatura_carrera')
            ->where('asignatura_id', $asignaturaId)
            ->where('carrera_id', $carreraId)
            ->where('sede_id', $sedeId)
            ->first();

        $payload = [
            'semestre' => is_numeric($semestre) ? (int) $semestre : null,
            'updated_at' => now(),
        ];

        if ($pivot) {
            DB::table('asignatura_carrera')
                ->where('id', $pivot->id)
                ->update($payload);
            return;
        }

        DB::table('asignatura_carrera')->insert([
            'asignatura_id' => $asignaturaId,
            'carrera_id' => $carreraId,
            'sede_id' => $sedeId,
            'semestre' => $payload['semestre'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function buildUserIdMap(array $docentesExternos, array &$resolverCache): array
    {
        $map = [];

        foreach ($docentesExternos as $docente) {
            $externalUserId = $docente['user_id'] ?? null;
            if (!$externalUserId) {
                continue;
            }

            $localUserId = $this->resolveLocalUserId($docente, $resolverCache);
            if ($localUserId) {
                $map[$externalUserId] = $localUserId;
            }
        }

        return $map;
    }

    private function resolveRestoreTargetIdentity(
        ?object $asignatura,
        array $validated,
        array $data,
        string $planEstudios
    ): array {
        if ($asignatura) {
            return [
                'codigo' => $asignatura->codigo,
                'nombre' => $asignatura->nombre ?? 'Sin nombre',
                'sigla' => $asignatura->sigla ?? ($data['sigla'] ?? $asignatura->codigo),
                'plan_estudios' => $asignatura->plan_estudios ?: $planEstudios,
            ];
        }

        $codigo = trim((string) ($validated['codigo'] ?? $data['codigo'] ?? ''));

        return [
            'codigo' => $codigo,
            'nombre' => trim((string) ($data['nombre'] ?? '')) ?: 'Sin nombre',
            'sigla' => $data['sigla'] ?? $codigo,
            'plan_estudios' => $planEstudios,
        ];
    }

    private function extractRestoreField(array $data, string $key, bool $encodeStructured = false): mixed
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];

        if ($encodeStructured) {
            return $this->toDatabaseValue($value);
        }

        return $value;
    }

    private function resolveLocalUserId(array $persona, array &$resolverCache): ?int
    {
        $ci = $this->normalizeCi($persona['ci'] ?? $persona['docente_ci'] ?? null);
        if ($ci) {
            if (array_key_exists($ci, $resolverCache['ci'])) {
                return $resolverCache['ci'][$ci];
            }

            $localUserId = DB::table('users')
                ->whereRaw("REPLACE(REPLACE(UPPER(ci), '-', ''), ' ', '') = ?", [$ci])
                ->value('id');

            if (!$localUserId) {
                $localUserId = DB::table('docentes')
                    ->whereRaw("REPLACE(REPLACE(UPPER(ci), '-', ''), ' ', '') = ?", [$ci])
                    ->value('user_id');
            }

            $resolverCache['ci'][$ci] = $localUserId ? (int) $localUserId : null;
            if ($localUserId) {
                return (int) $localUserId;
            }
        }

        $email = $this->normalizeEmail($persona['email'] ?? $persona['docente_email'] ?? null);
        if (!$email) {
            return null;
        }

        if (array_key_exists($email, $resolverCache['email'])) {
            return $resolverCache['email'][$email];
        }

        $localUserId = DB::table('users')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->value('id');

        if (!$localUserId) {
            $localUserId = DB::table('docentes')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->value('user_id');
        }

        $resolverCache['email'][$email] = $localUserId ? (int) $localUserId : null;

        return $localUserId ? (int) $localUserId : null;
    }

    private function storeBibliografia(int $asignaturaId, array $bibliografia, array &$cache): int
    {
        $titulo = trim((string) ($bibliografia['titulo'] ?? ''));
        $autor = trim((string) ($bibliografia['autor'] ?? ''));
        $tipo = trim((string) ($bibliografia['tipo'] ?? 'BASICA'));
        $key = mb_strtolower($titulo . '|' . $autor . '|' . $tipo);

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $existing = DB::table('bibliografias')
            ->where('asignatura_id', $asignaturaId)
            ->where('titulo', $titulo)
            ->where('autor', $autor)
            ->where('tipo', $tipo)
            ->first();

        if ($existing) {
            $cache[$key] = $existing->id;
            return $existing->id;
        }

        $cache[$key] = DB::table('bibliografias')->insertGetId([
            'asignatura_id' => $asignaturaId,
            'titulo' => $titulo,
            'descripcion' => $bibliografia['descripcion'] ?? null,
            'autor' => $autor ?: null,
            'editorial' => $bibliografia['editorial'] ?? null,
            'edicion' => $bibliografia['edicion'] ?? null,
            'anio' => $bibliografia['anio'] ?? null,
            'tipo' => $tipo,
            'isbn' => $bibliografia['isbn'] ?? null,
            'paginas' => $bibliografia['paginas'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $cache[$key];
    }

    private function findTargetAsignatura(
        string $codigo,
        ?int $carreraId,
        ?int $sedeId,
        string $planEstudios
    ): ?object {
        $strategies = [
            fn () => $this->queryAsignaturas($codigo, $carreraId, $sedeId, $planEstudios),
            fn () => $this->queryAsignaturas($codigo, $carreraId, $sedeId, null),
            fn () => $this->queryAsignaturas($codigo, null, null, $planEstudios),
            fn () => $this->queryAsignaturas($codigo, null, null, null),
        ];

        foreach ($strategies as $strategy) {
            $matches = $strategy();

            if ($matches->count() === 1) {
                return $matches->first();
            }

            if ($matches->count() > 1) {
                throw new \RuntimeException(
                    'Existen multiples asignaturas locales con el codigo '
                    . $codigo
                    . '. Envia carrera, sede y plan de estudios para identificar la materia correcta.'
                );
            }
        }

        return null;
    }

    private function queryAsignaturas(
        string $codigo,
        ?int $carreraId,
        ?int $sedeId,
        ?string $planEstudios
    ) {
        $query = $this->baseAsignaturaQuery($codigo);
        $this->applyCarreraSedeFilter($query, $carreraId, $sedeId);

        if ($planEstudios !== null && $planEstudios !== '') {
            $this->applyPlanEstudiosFilter($query, $planEstudios);
        }

        return $query->get();
    }

    private function baseAsignaturaQuery(string $codigo): Builder
    {
        return DB::table('asignaturas')
            ->where('codigo', $codigo)
            ->where(function ($query) {
                $query->whereNull('estado')
                    ->orWhere('estado', '!=', 'cancelado');
            })
            ->whereNull('deleted_at');
    }

    private function findTargetAsignaturaById(int $asignaturaId, ?string $codigo = null): ?object
    {
        $query = DB::table('asignaturas')
            ->where('id', $asignaturaId)
            ->whereNull('deleted_at')
            ->where(function ($subQuery) {
                $subQuery->whereNull('estado')
                    ->orWhere('estado', '!=', 'cancelado');
            });

        if ($codigo !== null && $codigo !== '') {
            $query->where('codigo', $codigo);
        }

        return $query->first();
    }

    private function applyCarreraSedeFilter(Builder $query, ?int $carreraId, ?int $sedeId): void
    {
        if (!$carreraId && !$sedeId) {
            return;
        }

        $query->whereExists(function ($subQuery) use ($carreraId, $sedeId) {
            $subQuery->select(DB::raw(1))
                ->from('asignatura_carrera')
                ->whereColumn('asignatura_carrera.asignatura_id', 'asignaturas.id');

            if ($carreraId) {
                $subQuery->where('asignatura_carrera.carrera_id', $carreraId);
            }

            if ($sedeId) {
                $subQuery->where('asignatura_carrera.sede_id', $sedeId);
            }
        });
    }

    private function applyPlanEstudiosFilter(Builder $query, string $planEstudios): void
    {
        if ($planEstudios === '') {
            return;
        }
        $query->where('plan_estudios', $planEstudios);
    }

    private function resolvePlanEstudios(array $data): string
    {
        $incoming = $this->normalizePlanEstudios($data['plan_estudios'] ?? null);
        if ($incoming !== '') {
            return $incoming;
        }

        if (!empty($data['carrera_id'])) {
            $carreraPlan = DB::table('carreras')
                ->where('id', $data['carrera_id'])
                ->value('plan_estudios');

            $normalizedCarreraPlan = $this->normalizePlanEstudios($carreraPlan);
            if ($normalizedCarreraPlan !== '') {
                return $normalizedCarreraPlan;
            }
        }

        return 'N';
    }

    private function buildRestoreKey(
        ?string $codigo,
        ?string $planEstudios,
        mixed $carreraId,
        mixed $sedeId
    ): string {
        return implode('-', [
            $codigo ?: 'sin-codigo',
            $this->normalizePlanEstudios($planEstudios) ?: 'N',
            $carreraId ?: 'sin-carrera',
            $sedeId ?: 'sin-sede',
        ]);
    }

    private function normalizePlanEstudios(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasMeaningfulValue($item)) {
                    return true;
                }
            }

            return false;
        }

        if (is_object($value)) {
            return $this->hasMeaningfulValue((array) $value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || strtolower($trimmed) === 'null') {
                return false;
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                return $this->hasMeaningfulValue($decoded);
            }

            $plainText = strip_tags(str_ireplace(['&nbsp;', '\u00a0'], ' ', $trimmed));
            return trim($plainText) !== '';
        }

        return !empty($value);
    }

    private function normalizeEmail(?string $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeCi(?string $value): ?string
    {
        $normalized = strtoupper(trim((string) $value));
        $normalized = str_replace([' ', '-'], '', $normalized);
        return $normalized !== '' ? $normalized : null;
    }

    private function toDatabaseValue(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }
}
