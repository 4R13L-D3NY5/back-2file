<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Barryvdh\DomPDF\Facade\Pdf;

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

        $skipSslVerify = app()->environment('local')
            && filter_var(env('RESTAURACION_SKIP_SSL_VERIFY', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $requestBuilder = Http::timeout(45)->acceptJson();
            if ($skipSslVerify) {
                $requestBuilder = $requestBuilder->withoutVerifying();
            }
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
                'error_detail' => $e->getMessage(),
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

        try {
            DB::beginTransaction();
            $result = $this->aplicarPayloadAsignatura($data, $validated, $planEstudios);
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Asignatura y programa analitico restaurados correctamente.',
                'asignatura_id' => $result['asignatura_id'],
                'stats' => $result['stats'],
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

    /**
     * Logica central de restauracion de una asignatura. NO maneja la transaccion
     * (debe llamarse dentro de un DB::beginTransaction() / DB::commit()).
     * Reutilizado por restaurarAsignatura (JSON) e importarExcel (xlsx).
     *
     * @return array{asignatura_id: int, stats: array<string, int>}
     */
    private function aplicarPayloadAsignatura(array $data, array $validated, string $planEstudios): array
    {
        $docentesExternos = $data['docentes'] ?? [];
        $stats = [
            'unidades_restauradas' => 0,
            'temas_restaurados' => 0,
            'planificaciones_restauradas' => 0,
            'planificaciones_omitidas' => 0,
            'bibliografias_restauradas' => 0,
        ];

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

        return [
            'asignatura_id' => (int) $asignaturaId,
            'stats' => $stats,
        ];
    }

    /**
     * Exporta el set de asignaturas de una carrera (obtenidas desde la API externa)
     * a un archivo .xlsx con 5 hojas relacionadas, listo para editar y re-importar.
     */
    public function exportarExcel(Request $request)
    {
        $validated = $request->validate([
            'api_url' => 'required|url',
            'token' => 'nullable|string',
            'carrera_id' => 'required|integer',
            'sede_id' => 'nullable|integer',
            'carrera_nombre' => 'nullable|string',
        ]);

        try {
            $payload = $this->fetchFromExternalApi(
                (string) $validated['api_url'],
                (string) ($validated['token'] ?? ''),
                (int) $validated['carrera_id'],
                isset($validated['sede_id']) ? (int) $validated['sede_id'] : null,
            );
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo obtener la documentacion del origen: ' . $e->getMessage(),
            ], 502);
        }

        $asignaturas = $this->extractAsignaturasFromPayload($payload);

        if (empty($asignaturas)) {
            return response()->json([
                'status' => 'error',
                'message' => 'El origen no devolvio asignaturas para exportar.',
            ], 404);
        }

        $spreadsheet = new Spreadsheet();
        $defaultSheet = $spreadsheet->getActiveSheet();
        $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($defaultSheet));

        $this->writeAsignaturasSheet($spreadsheet, $asignaturas);
        $this->writeUnidadesTemasSheet($spreadsheet, $asignaturas);
        $this->writeLogrosIndicadoresSheet($spreadsheet, $asignaturas);
        $this->writeBibliografiasSheet($spreadsheet, $asignaturas);
        $this->writeDocentesSheet($spreadsheet, $asignaturas);

        $carreraLabel = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($validated['carrera_nombre'] ?? ('carrera_' . $validated['carrera_id'])));
        $fileName = sprintf('restauracion_%s_%s.xlsx', $carreraLabel, date('Ymd_His'));

        $writer = new XlsxWriter($spreadsheet);
        $tmpPath = tempnam(sys_get_temp_dir(), 'restauracion_xlsx_');
        $writer->save($tmpPath);

        return response()->download($tmpPath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Recibe un .xlsx exportado y aplicado de la herramienta de restauracion,
     * reconstruye el payload de cada asignatura y aplica la restauracion una por una.
     * Continua con las validas aunque alguna falle y devuelve un resumen.
     */
    public function importarExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx|max:51200',
        ]);

        try {
            $reader = new XlsxReader();
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo leer el archivo Excel: ' . $e->getMessage(),
            ], 400);
        }

        $asigRows = $this->readSheetAsRows($spreadsheet, 'Asignaturas');
        $utRows = $this->readSheetAsRows($spreadsheet, 'Unidades_Temas');
        $liRows = $this->readSheetAsRows($spreadsheet, 'Logros_Indicadores');
        $bibRows = $this->readSheetAsRows($spreadsheet, 'Bibliografias');
        $docRows = $this->readSheetAsRows($spreadsheet, 'Docentes');

        if (empty($asigRows)) {
            return response()->json([
                'status' => 'error',
                'message' => 'La hoja "Asignaturas" esta vacia o no existe.',
            ], 422);
        }

        $byCodigo = [
            'ut' => $this->groupRowsByKey($utRows, 'codigo_asignatura'),
            'li' => $this->groupRowsByKey($liRows, 'codigo_asignatura'),
            'bib' => $this->groupRowsByKey($bibRows, 'codigo_asignatura'),
            'doc' => $this->groupRowsByKey($docRows, 'codigo_asignatura'),
        ];

        $aplicadas = [];
        $errores = [];

        foreach ($asigRows as $index => $asigRow) {
            $codigo = trim((string) ($asigRow['codigo'] ?? ''));
            if ($codigo === '') {
                $errores[] = [
                    'codigo' => null,
                    'indice' => $index,
                    'error' => 'Fila sin codigo de asignatura en la hoja Asignaturas.',
                ];
                continue;
            }

            $payload = $this->buildPayloadFromExcelRows($asigRow, $byCodigo[$codigo] ?? [
                'ut' => [], 'li' => [], 'bib' => [], 'doc' => [],
            ]);

            $validated = [
                'asignatura_id' => $payload['asignatura_id'] ?? null,
                'codigo' => $codigo,
                'carrera_id' => $payload['carrera_id'] ?? null,
                'sede_id' => $payload['sede_id'] ?? null,
                'plan_estudios' => $payload['plan_estudios'] ?? null,
                'semestre' => $payload['semestre'] ?? null,
            ];

            try {
                DB::beginTransaction();
                $result = $this->aplicarPayloadAsignatura($payload, $validated, $this->resolvePlanEstudios($payload));
                DB::commit();

                $aplicadas[] = [
                    'codigo' => $codigo,
                    'asignatura_id' => $result['asignatura_id'],
                    'stats' => $result['stats'],
                ];
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('Importacion Excel: asignatura fallida', [
                    'codigo' => $codigo,
                    'message' => $e->getMessage(),
                ]);
                $errores[] = [
                    'codigo' => $codigo,
                    'indice' => $index,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'status' => empty($errores) ? 'success' : 'partial',
            'message' => sprintf(
                'Importacion finalizada. %d aplicada(s), %d con error.',
                count($aplicadas),
                count($errores)
            ),
            'aplicadas' => $aplicadas,
            'errores' => $errores,
            'total_asignaturas' => count($asigRows),
        ]);
    }

    /**
     * Genera un Excel con el layout exacto del PAC (Programa Analitico de Clase)
     * que el docente usa para importar. Pre-rellena los campos que el JSON
     * extraido provee (carrera, codigo, nombre, plan, semestre, creditos,
     * unidades, temas, logros, indicadores, bibliografia) y deja vacios los
     * campos propios del PAC que la API externa no expone (modalidad, tipo_curso,
     * area_desempenio, datos del docente, metodologia estructurada, sistema
     * de evaluacion con notas, etc.) para que el docente los complete.
     */
    public function exportarExcelPacAsignatura(Request $request)
    {
        $validated = $request->validate([
            'asignatura' => 'required|array',
        ]);

        $asignatura = $validated['asignatura'];
        $codigo = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($asignatura['codigo'] ?? 'asignatura'));
        $fileName = sprintf('PAC_%s_%s.xlsx', $codigo, date('Ymd_His'));

        $spreadsheet = new Spreadsheet();
        $defaultSheet = $spreadsheet->getActiveSheet();
        $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($defaultSheet));

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('PAC');

        $this->buildPacSheet($sheet, $asignatura);

        $writer = new XlsxWriter($spreadsheet);
        $tmpPath = tempnam(sys_get_temp_dir(), 'pac_xlsx_');
        $writer->save($tmpPath);

        return response()->download($tmpPath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Construye el layout del PAC replicando 1:1 'excels/plantilla PAC.xlsx'.
     *
     * Mapeo de filas (igual a la plantilla original):
     *   Filas 1-7  Cabecera (titulo universidad + subtitulo programa)
     *   Fila  8    "1.- Identificacion de la Asignatura"
     *   Filas 10-16  Identificacion (2 pares label-valor por fila)
     *   Fila  18   "2.- Docente Responsable de la Asignatura" (solo si hay datos)
     *   Filas 20-21  Docente
     *   Fila  23   "3.- Justificacion de la Asignatura"
     *   Fila  25   valor justificacion
     *   Fila  27   "4.- Proposito General de la Unidad de Formacion"
     *   Fila  29   valor proposito
     *   Fila  31   "5.- Competencias"
     *   Filas 33-34  Competencias
     *   Fila  36   "6.- Elementos de Competencia"
     *   Filas 38-45  Elementos
     *   Fila  47   "7.- Estructura de Unidad de Aprendizaje (ver Plan de Clase)"
     *   Fila  49   nota plan de clase
     *
     * A partir de la fila 50 el layout se vuelve dinamico para mantener
     * compatibilidad con el importador (AsignaturaController::importExcel)
     * que busca por etiquetas en todo el grid.
     *
     * Columnas: A y K son margen estrecho (3.4), B-J area de datos.
     */
    private function buildPacSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $asig): void
    {
        // ===== Estilos =====
        $sectionHeader = [
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '1F2937']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDE9FE']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true, 'indent' => 1],
        ];
        $labelStyle = [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '1F2937']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true, 'indent' => 1],
        ];
        $valueStyle = [
            'font' => ['size' => 10, 'color' => ['rgb' => '1F2937']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true, 'indent' => 1],
        ];
        $valueTopStyle = $valueStyle;
        $valueTopStyle['alignment']['vertical'] = Alignment::VERTICAL_TOP;
        $bigTitle = [
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '1F2937'], 'name' => 'Cambria'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
        $subTitle = [
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '1F2937'], 'name' => 'Cambria'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
        $borderAll = [
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => '7C3AED']],
            ],
        ];
        $borderOuter = [
            'borders' => [
                'outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => '7C3AED']],
            ],
        ];

        // ===== Anchos de columna (identicos a la plantilla) =====
        $widths = [
            'A' => 3.42, 'B' => 15.71, 'C' => 11.42, 'D' => 12.28, 'E' => 27.14,
            'F' => 19.28, 'G' => 19.28, 'H' => 19.28, 'I' => 19.28, 'J' => 19.28, 'K' => 3.42,
        ];
        foreach ($widths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // ===== Extraer datos =====
        $metodologia = is_array($asig['metodologia_general'] ?? null) ? $asig['metodologia_general'] : [];
        $sistemaEval = is_array($asig['sistema_evaluacion'] ?? null) ? $asig['sistema_evaluacion'] : [];
        $elementos = is_array($asig['elementos_competencia'] ?? null) ? $asig['elementos_competencia'] : [];
        $carrera = is_string($asig['carrera'] ?? null) ? $asig['carrera'] : ($asig['carrera']['nombre'] ?? '');

        // Mapear elementos de competencia por numero de unidad (1..8).
        // Si el payload trae unidades con elemento_competencia, esa es la fuente
        // de verdad; si no, recaemos en el array plano elementos_competencia.
        $elementosPorUnidad = [];
        foreach (($asig['unidades'] ?? []) as $u) {
            $num = intval(is_array($u) ? ($u['numero'] ?? 0) : ($u->numero ?? 0));
            if ($num >= 1 && $num <= 8) {
                $valor = is_array($u) ? ($u['elemento_competencia'] ?? '') : ($u->elemento_competencia ?? '');
                $elementosPorUnidad[$num] = $this->stringifyValue($valor);
            }
        }

        // ===== Filas 2-6: Cabecera (titulo + subtitulo) =====
        $sheet->mergeCells('B2:J3');
        $sheet->setCellValue('B2', 'UNIVERSIDAD TÉCNICA PRIVADA COSMOS');
        $sheet->getStyle('B2')->applyFromArray($bigTitle);

        $sheet->mergeCells('B5:J6');
        $sheet->setCellValue('B5', 'PROGRAMA DE ASIGNATURA');
        $sheet->getStyle('B5')->applyFromArray($subTitle);

        $sheet->getStyle('B2:J6')->applyFromArray($borderOuter);
        foreach (range(2, 6) as $r) $sheet->getRowDimension($r)->setRowHeight(20);

        // Logo de la universidad (misma area B2:B6 que la plantilla original).
        $logoPath = base_path('../Academico/dist/spa/icons/LOGO UNITEPC.png');
        if (!file_exists($logoPath)) {
            // Fallback: ruta relativa alternativa dentro de back-2file
            $logoPath = public_path('icons/LOGO UNITEPC.png');
        }
        if (file_exists($logoPath)) {
            try {
                $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                $drawing->setName('Logo UNITEPC');
                $drawing->setDescription('Logo UNITEPC');
                $drawing->setPath($logoPath);
                $drawing->setCoordinates('B2');
                $drawing->setHeight(55);
                $drawing->setOffsetX(8);
                $drawing->setOffsetY(4);
                $drawing->setResizeProportional(true);
                $drawing->setWorksheet($sheet);
            } catch (\Throwable $e) {
                // Si falla la imagen, continuamos sin ella.
                Log::warning('No se pudo insertar logo en export PAC: ' . $e->getMessage());
            }
        }

        // ===== Fila 8: Header seccion 1 =====
        $sheet->mergeCells('B8:J8');
        $sheet->setCellValue('B8', '1.- Identificación de la Asignatura');
        $sheet->getStyle('B8:J8')->applyFromArray($sectionHeader + $borderAll);
        $sheet->getRowDimension(8)->setRowHeight(22);

        // ===== Filas 10-16: Identificacion (2 pares label-valor por fila) =====
        // Fila 10: CARRERA (full width, sin par a la derecha)
        $this->writeIdentRowSingle($sheet, 10, 'CARRERA:', $carrera, $labelStyle, $valueStyle, $borderAll);

        // Filas 11-15: pares B|C-F y G|H-J
        $this->writeIdentRowPair($sheet, 11, 'ASIGNATURA:', $asig['nombre'] ?? '', 'CÓDIGO:', $asig['codigo'] ?? '', $labelStyle, $valueStyle, $borderAll);
        $this->writeIdentRowPair($sheet, 12, 'ÁREA DE DESEMPEÑO:', $this->orDefault($asig['area_desempenio'] ?? ($asig['area'] ?? '')), 'TIPO DE CURSO:', $this->orDefault($asig['tipo_curso'] ?? ''), $labelStyle, $valueStyle, $borderAll);
        $this->writeIdentRowPair($sheet, 13, 'MODALIDAD:', $this->orDefault($asig['modalidad'] ?? ''), 'SEMESTRE:', $asig['semestre'] ?? '', $labelStyle, $valueStyle, $borderAll);
        $this->writeIdentRowPair($sheet, 14, 'PRE-REQUISITO:', $this->orDefault($asig['requisitos'] ?? ''), 'CRÉDITOS:', $asig['creditos'] ?? '', $labelStyle, $valueStyle, $borderAll);
        $this->writeIdentRowPair($sheet, 15, 'CARGA HORARIA TOTAL:', $asig['carga_horaria_total'] ?? '', 'HORAS TEÓRICAS Y/O PRÁCTICAS:', $asig['horas_teoricas_practicas'] ?? '', $labelStyle, $valueStyle, $borderAll);

        // Fila 16: estructura especial - B|C-D, E|F, G|H-J
        $sheet->setCellValue('B16', 'N° DE SESIONES SEMANALES:');
        $sheet->mergeCells('C16:D16');
        $sheet->setCellValue('C16', $asig['sesiones_semanales'] ?? '');
        $sheet->setCellValue('E16', 'TEÓRICAS:');
        $sheet->setCellValue('F16', $asig['sesiones_semanales_teoricas'] ?? '');
        $sheet->setCellValue('G16', 'PRÁCTICAS:');
        $sheet->mergeCells('H16:J16');
        $sheet->setCellValue('H16', $asig['sesiones_semanales_practicas'] ?? '');
        $sheet->getStyle('B16')->applyFromArray($labelStyle);
        $sheet->getStyle('E16')->applyFromArray($labelStyle);
        $sheet->getStyle('G16')->applyFromArray($labelStyle);
        $sheet->getStyle('C16:D16')->applyFromArray($valueStyle);
        $sheet->getStyle('F16')->applyFromArray($valueStyle);
        $sheet->getStyle('H16:J16')->applyFromArray($valueStyle);
        $sheet->getStyle('B16:J16')->applyFromArray($borderAll);
        $sheet->getRowDimension(16)->setRowHeight(25);

        // ===== Fila 18: Header seccion 2 + filas 20-21 docente =====
        // La plantilla siempre muestra esta seccion, aunque los campos esten vacios.
        $docNombre = $asig['docente']['nombre'] ?? ($asig['docente_nombre'] ?? '');
        $docEmail = $asig['docente']['email'] ?? ($asig['docente_email'] ?? '');
        $docFormacion = $this->orDefault($asig['docente']['formacion'] ?? ($asig['docente_formacion'] ?? ''));
        $docTelefono = $asig['docente']['telefono'] ?? ($asig['docente_telefono'] ?? '');

        $sheet->mergeCells('B18:J18');
        $sheet->setCellValue('B18', '2.- Docente Responsable de la Asignatura');
        $sheet->getStyle('B18:J18')->applyFromArray($sectionHeader + $borderAll);
        $sheet->getRowDimension(18)->setRowHeight(22);

        $this->writeIdentRowPair($sheet, 20, 'Nombre del docente:', $docNombre, 'eMail:', $docEmail, $labelStyle, $valueStyle, $borderAll);
        $this->writeIdentRowPair($sheet, 21, 'Formación:', $docFormacion, 'Teléfono:', $docTelefono, $labelStyle, $valueStyle, $borderAll);

        // ===== Fila 23: "3.- Justificacion de la Asignatura" + Fila 25 valor =====
        $sheet->mergeCells('B23:J23');
        $sheet->setCellValue('B23', '3.- Justificación de la Asignatura');
        $sheet->getStyle('B23:J23')->applyFromArray($sectionHeader + $borderAll);
        $sheet->getRowDimension(23)->setRowHeight(22);

        $sheet->mergeCells('B25:J25');
        $sheet->setCellValue('B25', $asig['justificacion'] ?? '');
        $sheet->getStyle('B25:J25')->applyFromArray($valueTopStyle + $borderAll);
        $sheet->getRowDimension(25)->setRowHeight(100);

        // ===== Fila 27: "4.- Proposito General" + Fila 29 valor =====
        $sheet->mergeCells('B27:J27');
        $sheet->setCellValue('B27', '4.- Propósito General de la Unidad de Formación');
        $sheet->getStyle('B27:J27')->applyFromArray($sectionHeader + $borderAll);
        $sheet->getRowDimension(27)->setRowHeight(22);

        $sheet->mergeCells('B29:J29');
        $sheet->setCellValue('B29', $asig['proposito_general'] ?? '');
        $sheet->getStyle('B29:J29')->applyFromArray($valueTopStyle + $borderAll);
        $sheet->getRowDimension(29)->setRowHeight(100);

        // ===== Fila 31: "5.- Competencias" =====
        $sheet->mergeCells('B31:J31');
        $sheet->setCellValue('B31', '5.- Competencias');
        $sheet->getStyle('B31:J31')->applyFromArray($sectionHeader + $borderAll);
        $sheet->getRowDimension(31)->setRowHeight(22);

        // Fila 33: Competencia Global Especifica
        $sheet->mergeCells('B33:C33');
        $sheet->setCellValue('B33', 'Competencia Global Específica:');
        $sheet->mergeCells('D33:J33');
        $sheet->setCellValue('D33', $asig['competencia_global_especifica'] ?? '');
        $sheet->getStyle('B33:C33')->applyFromArray($labelStyle);
        $sheet->getStyle('D33:J33')->applyFromArray($valueTopStyle);
        $sheet->getStyle('B33:J33')->applyFromArray($borderAll);
        $sheet->getRowDimension(33)->setRowHeight(55);

        // Fila 34: Unidad de Competencia Especifica
        $sheet->mergeCells('B34:C34');
        $sheet->setCellValue('B34', 'Unidad de Competencia Específica:');
        $sheet->mergeCells('D34:J34');
        $sheet->setCellValue('D34', $asig['competencia_asignatura'] ?? '');
        $sheet->getStyle('B34:C34')->applyFromArray($labelStyle);
        $sheet->getStyle('D34:J34')->applyFromArray($valueTopStyle);
        $sheet->getStyle('B34:J34')->applyFromArray($borderAll);
        $sheet->getRowDimension(34)->setRowHeight(55);

        // ===== Fila 36: "6.- Elementos de Competencia" + filas 38-45 =====
        $sheet->mergeCells('B36:J36');
        $sheet->setCellValue('B36', '6.- Elementos de Competencia');
        $sheet->getStyle('B36:J36')->applyFromArray($sectionHeader + $borderAll);
        $sheet->getRowDimension(36)->setRowHeight(22);

        for ($i = 1; $i <= 8; $i++) {
            $r = 37 + $i; // filas 38..45
            $val = $elementosPorUnidad[$i]
                ?? ($elementos[$i - 1] ?? ($elementos[$i] ?? ''));
            $sheet->mergeCells("B{$r}:C{$r}");
            $sheet->setCellValue("B{$r}", "Elemento de competencia {$i}:");
            $sheet->mergeCells("D{$r}:J{$r}");
            $sheet->setCellValue("D{$r}", is_string($val) ? $val : (is_array($val) ? implode("\n", $val) : ''));
            $sheet->getStyle("B{$r}:C{$r}")->applyFromArray($labelStyle);
            $sheet->getStyle("D{$r}:J{$r}")->applyFromArray($valueTopStyle);
            $sheet->getStyle("B{$r}:J{$r}")->applyFromArray($borderAll);
            $sheet->getRowDimension($r)->setRowHeight(28);
        }

        // ===== Fila 47: "7.- Estructura de Unidad de Aprendizaje" + Fila 49 nota =====
        $sheet->mergeCells('B47:J47');
        $sheet->setCellValue('B47', '7.- Estructura de Unidad de Aprendizaje');
        $sheet->getStyle('B47:J47')->applyFromArray($sectionHeader + $borderAll);
        $sheet->getRowDimension(47)->setRowHeight(22);

        $sheet->mergeCells('B49:J49');
        $sheet->setCellValue('B49', 'La estructura detallada por unidad y tema se exporta por separado en el archivo "Plan de Clase" (una hoja por unidad academica).');
        $sheet->getStyle('B49:J49')->applyFromArray($valueTopStyle + $borderAll);
        $sheet->getRowDimension(49)->setRowHeight(35);

        // A partir de aqui el layout es dinamico (fila inicial 51).
        $row = 51;

        // ===== 8.- Metodologia General =====
        $sheet->mergeCells("B{$row}:J{$row}");
        $sheet->setCellValue("B{$row}", '8.- Metodología General de la Asignatura');
        $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($sectionHeader + $borderAll);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row += 2;

        $metodRows = [
            ['label' => 'En el Aula:', 'value' => $this->stringifyValue($metodologia['aula'] ?? null)],
            ['label' => 'Centro de Simulación:', 'value' => $this->stringifyValue($metodologia['simulacion'] ?? null)],
            ['label' => 'Hospital y Centros de Salud:', 'value' => $this->stringifyValue($metodologia['hospital'] ?? null)],
        ];
        foreach ($metodRows as $r) {
            $this->writeMetodologiaRow($sheet, $row, $r['label'], $r['value'], $labelStyle, $valueTopStyle, $borderAll);
            $row++;
        }
        $row++;

        // ===== 9. Sistema de Evaluacion =====
        // El importador busca literal "9. sistema de evaluacion" (con punto, no guion).
        $eval9Row = $row;
        $sheet->mergeCells("B{$row}:J{$row}");
        $sheet->setCellValue("B{$row}", '9. Sistema de Evaluación');
        $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($sectionHeader + $borderAll);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++; // fila N+1 vacia

        // Fases concatenadas para importador (col F de N+2)
        $fases = [];
        $diagText = $sistemaEval['diagnostica'] ?? null;
        $formText = $sistemaEval['formativa'] ?? null;
        $sumaText = $sistemaEval['sumativa'] ?? null;
        if (!empty($diagText) && is_string($diagText)) $fases[] = 'a. ' . trim($diagText);
        if (!empty($formText) && is_string($formText)) $fases[] = 'b. ' . trim($formText);
        if (!empty($sumaText) && is_string($sumaText)) $fases[] = 'c. ' . trim($sumaText);
        $fasesRaw = !empty($fases) ? implode(' ', $fases) : '';

        $introEval = $sistemaEval['intro'] ?? '';
        if (is_array($introEval)) $introEval = $this->stringifyValue($introEval);

        $pondText = $sistemaEval['ponderacion'] ?? '';
        $finalText = $sistemaEval['final'] ?? '';
        $ponderacionFull = (string) $pondText;
        if (!empty($finalText) && !str_contains((string) $pondText, 'La evaluación final')) {
            $ponderacionFull = trim((string) $pondText) . "\n\nLa evaluación final " . trim((string) $finalText);
        }

        // Fila N+2 (proceso evaluador): intro en B-E, fases en F-J (oculto en blanco)
        $row++; // ahora estamos en N+2
        $sheet->mergeCells("B{$row}:E{$row}");
        $sheet->setCellValue("B{$row}", $introEval);
        $sheet->mergeCells("F{$row}:J{$row}");
        $sheet->setCellValue("F{$row}", $fasesRaw);
        $sheet->getStyle("B{$row}:E{$row}")->applyFromArray($valueTopStyle);
        $sheet->getStyle("F{$row}:J{$row}")->applyFromArray($valueTopStyle);
        $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($borderAll);
        if (!empty($fasesRaw)) {
            // Fases ocultas visualmente (blanco sobre blanco) pero leibles por el importador.
            $sheet->getStyle("F{$row}:J{$row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
        }
        $sheet->getRowDimension($row)->setRowHeight(70);

        // Fila N+3 (ponderacion): texto completo en B-J
        $row++;
        $sheet->mergeCells("B{$row}:J{$row}");
        $sheet->setCellValue("B{$row}", $ponderacionFull);
        $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($valueTopStyle + $borderAll);
        $sheet->getRowDimension($row)->setRowHeight(70);
        $row += 2;

        // ===== 12.- Criterios y Normativa =====
        $sheet->mergeCells("B{$row}:J{$row}");
        $sheet->setCellValue("B{$row}", '12.- Criterios y Normativa de la Asignatura');
        $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($sectionHeader + $borderAll);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row += 2;

        $normativa = is_array($asig['reglamento_normativa'] ?? null) ? $asig['reglamento_normativa'] : [];
        $claseText = is_string($normativa['clase'] ?? null) ? $normativa['clase'] : (is_array($normativa['clase'] ?? null) ? implode("\n", $normativa['clase']) : '');
        $labText = is_string($normativa['laboratorio'] ?? null) ? $normativa['laboratorio'] : (is_array($normativa['laboratorio'] ?? null) ? implode("\n", $normativa['laboratorio']) : '');

        // Reglamento clases: label en B-C (rich text con "para las clases:" en negrita), valor en D-J
        $sheet->mergeCells("B{$row}:C{$row}");
        $sheet->setCellValue("B{$row}", 'Los estudiantes deberán cumplir el siguiente reglamento para las clases:');
        $sheet->mergeCells("D{$row}:J{$row}");
        $sheet->setCellValue("D{$row}", $claseText);
        $sheet->getStyle("B{$row}:C{$row}")->applyFromArray($labelStyle);
        $sheet->getStyle("D{$row}:J{$row}")->applyFromArray($valueTopStyle);
        $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($borderAll);
        $sheet->getRowDimension($row)->setRowHeight(80);

        // Reglamento laboratorio: solo si hay contenido. El importador captura
        // "Ademas, en laboratorio" como delimitador, asi que mantenemos esa frase.
        if ($labText !== '') {
            $row++;
            $sheet->mergeCells("B{$row}:C{$row}");
            $sheet->setCellValue("B{$row}", 'Además, en laboratorio:');
            $sheet->mergeCells("D{$row}:J{$row}");
            $sheet->setCellValue("D{$row}", $labText);
            $sheet->getStyle("B{$row}:C{$row}")->applyFromArray($labelStyle);
            $sheet->getStyle("D{$row}:J{$row}")->applyFromArray($valueTopStyle);
            $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($borderAll);
            $sheet->getRowDimension($row)->setRowHeight(60);
        }
        $row += 2;

        // ===== 14.- Bibliografia =====
        $sheet->mergeCells("B{$row}:J{$row}");
        $sheet->setCellValue("B{$row}", '14.- Bibliografía');
        $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($sectionHeader + $borderAll);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row += 2;

        $biblios = $asig['bibliografias'] ?? [];

        $sheet->mergeCells("B{$row}:J{$row}");
        $sheet->setCellValue("B{$row}", 'Específica:');
        $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($labelStyle);
        $row++;

        $especifica = collect($biblios)->filter(function ($b) {
            $t = strtoupper((string) ($b['tipo'] ?? ''));
            return $t === 'BASICA' || $t === 'PRINCIPAL' || $t === 'ESPECIFICA';
        });
        if ($especifica->isEmpty()) {
            // Si no hay clasificacion explicita, considerar todo lo no-complementario como especifico.
            $especifica = collect($biblios)->filter(function ($b) {
                $t = strtoupper((string) ($b['tipo'] ?? ''));
                return $t !== 'COMPLEMENTARIA';
            });
        }
        if ($especifica->isEmpty()) {
            $sheet->mergeCells("B{$row}:J{$row}");
            $sheet->setCellValue("B{$row}", '');
            $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($valueTopStyle + $borderAll);
            $sheet->getRowDimension($row)->setRowHeight(28);
            $row++;
        } else {
            foreach ($especifica as $b) {
                $sheet->mergeCells("B{$row}:J{$row}");
                $sheet->setCellValue("B{$row}", $this->formatBibliografia($b));
                $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($valueTopStyle + $borderAll);
                $sheet->getRowDimension($row)->setRowHeight(22);
                $row++;
            }
        }
        $row++;

        $sheet->mergeCells("B{$row}:J{$row}");
        $sheet->setCellValue("B{$row}", 'Complementaria:');
        $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($labelStyle);
        $row++;

        $complementaria = collect($biblios)->filter(fn($b) => strtoupper((string) ($b['tipo'] ?? '')) === 'COMPLEMENTARIA');
        if ($complementaria->isEmpty()) {
            $sheet->mergeCells("B{$row}:J{$row}");
            $sheet->setCellValue("B{$row}", '');
            $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($valueTopStyle + $borderAll);
            $sheet->getRowDimension($row)->setRowHeight(28);
            $row++;
        } else {
            foreach ($complementaria as $b) {
                $sheet->mergeCells("B{$row}:J{$row}");
                $sheet->setCellValue("B{$row}", $this->formatBibliografia($b));
                $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($valueTopStyle + $borderAll);
                $sheet->getRowDimension($row)->setRowHeight(22);
                $row++;
            }
        }
    }

    /**
     * Escribe una fila de identificacion con UN solo par label-valor que ocupa
     * toda la fila (label en B, valor mergeado C-J).
     */
    private function writeIdentRowSingle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, string $label, $value, array $labelStyle, array $valueStyle, array $border): void
    {
        $sheet->setCellValue("B{$row}", $label);
        $sheet->mergeCells("C{$row}:J{$row}");
        $sheet->setCellValue("C{$row}", $this->stringifyValue($value));
        $sheet->getStyle("B{$row}")->applyFromArray($labelStyle);
        $sheet->getStyle("C{$row}:J{$row}")->applyFromArray($valueStyle);
        $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($border);
        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    /**
     * Escribe una fila de identificacion con DOS pares label-valor:
     * label1 en B, valor1 mergeado C-F, label2 en G, valor2 mergeado H-J.
     */
    private function writeIdentRowPair(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, string $label1, $value1, string $label2, $value2, array $labelStyle, array $valueStyle, array $border): void
    {
        $sheet->setCellValue("B{$row}", $label1);
        $sheet->mergeCells("C{$row}:F{$row}");
        $sheet->setCellValue("C{$row}", $this->stringifyValue($value1));
        $sheet->setCellValue("G{$row}", $label2);
        $sheet->mergeCells("H{$row}:J{$row}");
        $sheet->setCellValue("H{$row}", $this->stringifyValue($value2));
        $sheet->getStyle("B{$row}")->applyFromArray($labelStyle);
        $sheet->getStyle("C{$row}:F{$row}")->applyFromArray($valueStyle);
        $sheet->getStyle("G{$row}")->applyFromArray($labelStyle);
        $sheet->getStyle("H{$row}:J{$row}")->applyFromArray($valueStyle);
        $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($border);
        $sheet->getRowDimension($row)->setRowHeight(25);
    }

    /**
     * Escribe una fila de metodologia con label rich-text (label + "(Si corresponde)" italica)
     * en B, y valor mergeado C-J. Replica el formato visual de la plantilla original.
     */
    private function writeMetodologiaRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, string $label, string $value, array $labelStyle, array $valueStyle, array $border): void
    {
        $rich = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
        $labelRun = $rich->createTextRun($label . "\n");
        $labelRun->getFont()->setBold(true)->setSize(10)->setName('Cambria');
        $hint = $rich->createTextRun('(Si corresponde)');
        $hint->getFont()->setItalic(true)->setSize(9)->setName('Cambria')->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF6B7280'));
        $sheet->getCell("B{$row}")->setValue($rich);

        $sheet->mergeCells("C{$row}:J{$row}");
        $sheet->setCellValue("C{$row}", $value);
        $sheet->getStyle("B{$row}")->applyFromArray($labelStyle);
        $sheet->getStyle("C{$row}:J{$row}")->applyFromArray($valueStyle);
        $sheet->getStyle("B{$row}:J{$row}")->applyFromArray($border);
        $sheet->getRowDimension($row)->setRowHeight(45);
    }

    /**
     * Formatea una entrada de bibliografia como "Autor (Año). Titulo. Editorial."
     * sin prefijos artificiales ("AA.VV.", "S/E", etc.). Omite componentes vacios.
     */
    private function formatBibliografia(array $b): string
    {
        $autor = trim((string) ($b['autor'] ?? ''));
        $titulo = trim((string) ($b['titulo'] ?? ''));
        $editorial = trim((string) ($b['editorial'] ?? ''));
        $anio = trim((string) ($b['anio'] ?? ''));

        $parts = [];
        if ($autor !== '') {
            $parts[] = $anio !== '' ? "{$autor} ({$anio})." : "{$autor}.";
        } elseif ($anio !== '') {
            $parts[] = "({$anio}).";
        }
        if ($titulo !== '') $parts[] = $titulo . '.';
        if ($editorial !== '') $parts[] = $editorial . '.';

        // Si no hay nada, devolver descripcion cruda si existe.
        if (empty($parts)) {
            return trim((string) ($b['descripcion'] ?? ''));
        }
        return trim(implode(' ', $parts));
    }

    private function stringifyValue($value): string
    {
        if ($value === null || $value === '') return '';
        if (is_string($value)) return $value;
        if (is_array($value) || is_object($value)) {
            $arr = is_object($value) ? (array) $value : $value;
            $arr = array_filter($arr, fn($v) => $v !== null && $v !== '');
            return empty($arr) ? '' : implode("\n", array_map(fn($v) => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), $arr));
        }
        return (string) $value;
    }

    /**
     * ponytail: devuelve el valor stringificado o un placeholder no vacio.
     * Evita que el importador PAC (busqueda greedy 15x10) atraviese celdas
     * vacias y levante contenido de otra seccion (p. ej. justificacion).
     */
    private function orDefault($value, string $default = 'NO ESPECIFICADO'): string
    {
        $str = $this->stringifyValue($value);
        return $str === '' ? $default : $str;
    }

    /**
     * Genera un Excel en formato "Plan de Clase" en UNA SOLA hoja con
     * TODAS las unidades y todos los temas concatenados verticalmente.
     * La hoja queda lista para que el docente complete las celdas vacias
     * (Nombre, Fecha, etc.) y la suba al modulo correspondiente, igual
     * que como funciona el importador del docente.
     */
    public function exportarExcelPlanClaseAsignatura(Request $request)
    {
        $validated = $request->validate([
            'asignatura' => 'required|array',
        ]);

        $asignatura = $validated['asignatura'];
        $codigo = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($asignatura['codigo'] ?? 'asignatura'));
        $fileName = sprintf('PlanClase_%s_%s.xlsx', $codigo, date('Ymd_His'));

        $unidades = $asignatura['unidades'] ?? [];
        if (empty($unidades)) {
            return response()->json([
                'status' => 'error',
                'message' => 'La asignatura no tiene unidades para generar el Plan de Clase.',
            ], 422);
        }

        $spreadsheet = new Spreadsheet();
        $defaultSheet = $spreadsheet->getActiveSheet();
        $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($defaultSheet));

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('PlanClase');
        $this->buildPlanClaseSheet($sheet, $asignatura);

        $writer = new XlsxWriter($spreadsheet);
        $tmpPath = tempnam(sys_get_temp_dir(), 'planclase_xlsx_');
        $writer->save($tmpPath);

        return response()->download($tmpPath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Construye la hoja unica del Plan de Clase con todas las unidades y temas.
     * El layout replica EXACTAMENTE el formato que el importador
     * (PlanClaseParserService) espera al leer el archivo. El layout refleja
     * fila a fila la plantilla oficial "Plantilla Plan de clase.xlsx":
     *
     *   Fila TEMA+1  Col C: Resultados de Aprendizaje
     *   Fila TEMA+2  Col C: Logros Esperados
     *   Fila TEMA+3  Col C: Indicadores de Logro
     *   Fila TEMA+4  Col D: Saber Conceptual
     *   Fila TEMA+5  Col D: Saber Actitudinal
     *   Fila TEMA+8  Col B/D/G: Estrategias (metodologicas / aprendizaje / recursos)
     *   Fila TEMA+11 Col C/E/H: Evaluacion Formativa (act / inst / evid)
     *   Fila TEMA+12 Col C/E/H: Evaluacion Sumativa
     *   Fila TEMA+15..+19 Col C/H: Secuencia Didactica (5 momentos fijos)
     *
     * A diferencia de la plantilla (que tiene una hoja por unidad), el importador
     * lee una sola hoja activa con todas las unidades y temas concatenados.
     */
    private function buildPlanClaseSheet(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $asig
    ): void {
        $purple = '4C1D95';
        $purpleMid = '6D28D9';
        $borderColor = '7C3AED';
        $unidades = $asig['unidades'] ?? [];
        $totalUnidades = count($unidades);
        $totalTemas = collect($unidades)->sum(fn ($u) => count($u['temas'] ?? []));

        $labelCell = [
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '1F2937']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDE9FE']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => $borderColor]]],
        ];
        $valueCell = [
            'font' => ['size' => 10, 'color' => ['rgb' => '1F2937']],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true, 'indent' => 1],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => $borderColor]]],
        ];
        $headerMid = [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $purpleMid]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => $borderColor]]],
        ];
        $temaHeader = [
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $purple]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => $borderColor]]],
        ];
        $unidadHeader = [
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '1F2937']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDD6FE']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => $borderColor]]],
        ];

        // Anchos de columna iguales a la plantilla
        $widths = [
            'A' => 4.28, 'B' => 26.14, 'C' => 21.28, 'D' => 21.28, 'E' => 15.28,
            'F' => 15.28, 'G' => 15.28, 'H' => 37.42, 'I' => 4.28,
        ];
        foreach ($widths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // ========== Header (filas 1-6) - el parser ignora estas filas ==========
        // Mismo layout que la plantilla: filas pares pequeñas, titulo en R3, subtitulo en R5.
        $sheet->mergeCells('B3:I3');
        $sheet->setCellValue('B3', 'UNIVERSIDAD TÉCNICA PRIVADA COSMOS');
        $sheet->getStyle('B3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '1F2937'], 'name' => 'Cambria'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->mergeCells('B5:I5');
        $sheet->setCellValue('B5', 'PLAN DE CLASE');
        $sheet->getStyle('B5')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F2937'], 'name' => 'Cambria'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle('B3:I5')->applyFromArray([
            'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => $borderColor]]],
        ]);

        // Logo
        $logoPath = base_path('../Academico/dist/spa/icons/LOGO UNITEPC.png');
        if (!file_exists($logoPath)) {
            $logoPath = public_path('icons/LOGO UNITEPC.png');
        }
        if (file_exists($logoPath)) {
            try {
                $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                $drawing->setName('Logo UNITEPC');
                $drawing->setDescription('Logo UNITEPC');
                $drawing->setPath($logoPath);
                $drawing->setCoordinates('B2');
                $drawing->setHeight(60);
                $drawing->setOffsetX(4);
                $drawing->setOffsetY(2);
                $drawing->setResizeProportional(true);
                $drawing->setWorksheet($sheet);
            } catch (\Throwable $e) {
                Log::warning('No se pudo insertar logo en export Plan de Clase: ' . $e->getMessage());
            }
        }

        $sheet->getRowDimension(1)->setRowHeight(13);
        $sheet->getRowDimension(2)->setRowHeight(8);
        $sheet->getRowDimension(3)->setRowHeight(22);
        $sheet->getRowDimension(4)->setRowHeight(8);
        $sheet->getRowDimension(5)->setRowHeight(26);
        $sheet->getRowDimension(6)->setRowHeight(13);

        // ========== Datos del docente y asignatura (filas 8-9) - el parser tambien los ignora ==========
        $row = 8;
        $this->writePlanClaseIdentRow($sheet, $row,
            'Nombre del docente:', '',
            'Asignatura:', $asig['nombre'] ?? '',
            $labelCell, $valueCell
        );
        $row++;

        $this->writePlanClaseIdentRow($sheet, $row,
            'Fecha:', '',
            'Carrera:', is_string($asig['carrera'] ?? null) ? $asig['carrera'] : ($asig['carrera']['nombre'] ?? ''),
            $labelCell, $valueCell
        );
        $row += 2;

        // ========== Por cada UNIDAD con sus TEMAS (todos en una sola hoja) ==========
        foreach ($unidades as $idxU => $unidad) {
            $unidadNumero = $idxU + 1;
            $numeroUnidad = $unidad['numero'] ?? (string) $unidadNumero;
            $tituloUnidad = $unidad['titulo'] ?? 'Sin titulo';

            // Unidad N header (Col B, merged B:I)
            $sheet->mergeCells("B{$row}:I{$row}");
            $sheet->setCellValue("B{$row}", "Unidad {$numeroUnidad}: " . mb_strtoupper($tituloUnidad));
            $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($unidadHeader);
            $sheet->getRowDimension($row)->setRowHeight(26);
            $row++;

            // Elemento de Competencia N (Col B, valor en C:I)
            $sheet->setCellValue("B{$row}", "Elemento de Competencia {$numeroUnidad}:");
            $sheet->mergeCells("C{$row}:I{$row}");
            $elemComp = $this->stringifyValue($unidad['elemento_competencia'] ?? null);
            $sheet->setCellValue("C{$row}", $elemComp);
            $sheet->getStyle("B{$row}:B{$row}")->applyFromArray($labelCell);
            $sheet->getStyle("C{$row}:I{$row}")->applyFromArray($valueCell);
            $sheet->getStyle("B{$row}:I{$row}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => $borderColor]]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(max(30, 16 * (substr_count($elemComp, "\n") + 1)));
            $row++;

            // ========== Por cada TEMA ==========
            $temaIndex = 0;
            foreach (($unidad['temas'] ?? []) as $tema) {
                $temaIndex++;
                $temaTitulo = $tema['titulo'] ?? 'Sin titulo';
                $temaNumeroDisplay = $tema['orden'] ?? $temaIndex;

                // TEMA N header (Col B, merged B:I)
                $sheet->mergeCells("B{$row}:I{$row}");
                $sheet->setCellValue("B{$row}", "TEMA {$temaNumeroDisplay}: " . mb_strtoupper($temaTitulo));
                $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($temaHeader);
                $sheet->getRowDimension($row)->setRowHeight(24);
                $temaRow = $row;
                $row++;

                // +1 Resultados de Aprendizaje
                $this->writePlanClaseLabelValueRow($sheet, $row, 'Resultados de Aprendizaje:', $this->stringifyValue($tema['resultado_aprendizaje'] ?? null), $labelCell, $valueCell, 66);
                $row++;

                // +2 Logros Esperados
                $logros = $tema['logros_esperados'] ?? [];
                $logrosText = collect($logros)
                    ->map(fn ($l, $i) => (($i + 1) . '. ' . ($l['descripcion'] ?? '')))
                    ->filter(fn ($s) => trim($s) !== '.' && trim($s) !== '')
                    ->implode("\n");
                $this->writePlanClaseLabelValueRow($sheet, $row, 'Logros Esperados:', $logrosText, $labelCell, $valueCell, 65);
                $row++;

                // +3 Indicadores de Logro
                $indicadoresText = collect($logros)
                    ->map(function ($l) {
                        $subtitulo = $l['descripcion'] ?? '';
                        $indicadores = collect($l['indicadores'] ?? [])
                            ->map(fn ($i) => '   * ' . ($i['descripcion'] ?? ''))
                            ->filter()
                            ->implode("\n");
                        if ($indicadores === '') return '';
                        return $subtitulo . ":\n" . $indicadores;
                    })
                    ->filter()
                    ->implode("\n\n");
                $this->writePlanClaseLabelValueRow($sheet, $row, 'Indicadores de Logro:', $indicadoresText, $labelCell, $valueCell, 117);
                $row++;

                // +4 Contenidos: Saber Conceptual (parser lee Col D)
                $conceptual = $this->stringifyValue($tema['contenido_conceptual'] ?? null);
                $this->writePlanClaseContenidoRow($sheet, $row, 'Saber Conceptual:', $conceptual, $labelCell, $valueCell, 157);
                $row++;

                // +5 Saber Actitudinal (parser lee Col D)
                $actitudinal = $this->stringifyValue($tema['contenido_actitudinal'] ?? null);
                $this->writePlanClaseContenidoRow($sheet, $row, 'Saber Actitudinal:', $actitudinal, $labelCell, $valueCell, 127, false);
                $row++;

                // +6 ESTRATEGIAS DIDACTICAS header
                $sheet->mergeCells("B{$row}:I{$row}");
                $sheet->setCellValue("B{$row}", 'ESTRATEGIAS DIDACTICAS');
                $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($headerMid);
                $sheet->getRowDimension($row)->setRowHeight(20);
                $row++;

                // +7 Sub-headers
                $sheet->setCellValue("B{$row}", 'Metodologicas');
                $sheet->mergeCells("B{$row}:C{$row}");
                $sheet->setCellValue("D{$row}", 'De Aprendizaje');
                $sheet->mergeCells("D{$row}:F{$row}");
                $sheet->setCellValue("G{$row}", 'Recursos de Enseñanza');
                $sheet->mergeCells("G{$row}:I{$row}");
                $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($labelCell);
                $sheet->getRowDimension($row)->setRowHeight(20);
                $row++;

                // +8 Contenido estrategias (parser lee Col B/D/G)
                $metodologicas = $this->stringifyValue($tema['estrategias_metodologicas'] ?? null);
                $aprendizaje = $this->stringifyValue($tema['estrategias_aprendizaje'] ?? null);
                $recursos = $this->stringifyValue($tema['estrategias_recursos'] ?? null);
                $sheet->setCellValue("B{$row}", $metodologicas);
                $sheet->mergeCells("B{$row}:C{$row}");
                $sheet->setCellValue("D{$row}", $aprendizaje);
                $sheet->mergeCells("D{$row}:F{$row}");
                $sheet->setCellValue("G{$row}", $recursos);
                $sheet->mergeCells("G{$row}:I{$row}");
                $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($valueCell);
                $maxLines = max(substr_count($metodologicas, "\n"), substr_count($aprendizaje, "\n"), substr_count($recursos, "\n"));
                $sheet->getRowDimension($row)->setRowHeight(max(40, 16 * ($maxLines + 1)));
                $row++;

                // +9 EVALUACION DE LOS APRENDIZAJES header
                $sheet->mergeCells("B{$row}:I{$row}");
                $sheet->setCellValue("B{$row}", 'EVALUACION DE LOS APRENDIZAJES');
                $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($headerMid);
                $sheet->getRowDimension($row)->setRowHeight(20);
                $row++;

                // +10 Sub-headers
                $sheet->setCellValue("B{$row}", 'TIPO DE EVALUACION');
                $sheet->mergeCells("B{$row}:B{$row}");
                $sheet->setCellValue("C{$row}", 'Actividades y Técnicas');
                $sheet->mergeCells("C{$row}:D{$row}");
                $sheet->setCellValue("E{$row}", 'Instrumentos');
                $sheet->mergeCells("E{$row}:G{$row}");
                $sheet->setCellValue("H{$row}", 'Evidencias de Evaluación');
                $sheet->mergeCells("H{$row}:I{$row}");
                $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($labelCell);
                $sheet->getRowDimension($row)->setRowHeight(20);
                $row++;

                // +11 FORMATIVA (parser lee Col C/E/H)
                [$formAct, $formInst, $formEvid] = $this->extractEvaluacionParts($tema['evaluacion_formativa'] ?? null);
                $this->writePlanClaseEvalRow($sheet, $row, 'FORMATIVA', $formAct, $formInst, $formEvid, $labelCell, $valueCell, 65);
                $row++;

                // +12 SUMATIVA (parser lee Col C/E/H)
                [$sumAct, $sumInst, $sumEvid] = $this->extractEvaluacionParts($tema['evaluacion_sumativa'] ?? null);
                $this->writePlanClaseEvalRow($sheet, $row, 'SUMATIVA', $sumAct, $sumInst, $sumEvid, $labelCell, $valueCell, 92);
                $row++;

                // +13 SECUENCIA DIDACTICA header
                $sheet->mergeCells("B{$row}:I{$row}");
                $sheet->setCellValue("B{$row}", 'SECUENCIA DIDACTICA');
                $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($headerMid);
                $sheet->getRowDimension($row)->setRowHeight(20);
                $row++;

                // +14 Sub-headers
                $sheet->setCellValue("B{$row}", 'MOMENTOS');
                $sheet->mergeCells("B{$row}:B{$row}");
                $sheet->setCellValue("C{$row}", 'ACTIVIDAD');
                $sheet->mergeCells("C{$row}:G{$row}");
                $sheet->setCellValue("H{$row}", 'DURACIÓN');
                $sheet->mergeCells("H{$row}:I{$row}");
                $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($labelCell);
                $sheet->getRowDimension($row)->setRowHeight(20);
                $row++;

                // +15..+19 5 momentos fijos (parser lee Col C/H)
                $secuencias = $tema['secuencias'] ?? [];
                $secByMomento = [];
                foreach ($secuencias as $sec) {
                    $secByMomento[$sec['momento'] ?? 'Desarrollo'] = $sec;
                }

                $momentosDef = [
                    'INTRODUCCION',
                    'RESULTADOS DE APRENDIZAJE/LOGROS ESPERADOS',
                    'CONTENIDOS DE LA CLASE',
                    'CUERPO DE CONTENIDOS',
                    'CONCLUSION O CIERRE',
                ];

                foreach ($momentosDef as $mLabel) {
                    $sec = $secByMomento[$mLabel] ?? null;
                    $actividad = $sec['actividad'] ?? $sec['descripcion'] ?? '';
                    $duracionRaw = $sec['duracion'] ?? $sec['duracion_minutos'] ?? null;
                    $duracionStr = '';
                    if ($duracionRaw !== null && $duracionRaw !== '') {
                        $duracionStr = is_numeric($duracionRaw)
                            ? (int) $duracionRaw . ' minutos'
                            : (string) $duracionRaw;
                    }

                    $sheet->setCellValue("B{$row}", $mLabel);
                    $sheet->mergeCells("B{$row}:B{$row}");
                    $sheet->setCellValue("C{$row}", $actividad);
                    $sheet->mergeCells("C{$row}:G{$row}");
                    $sheet->setCellValue("H{$row}", $duracionStr);
                    $sheet->mergeCells("H{$row}:I{$row}");
                    $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($valueCell);
                    $sheet->getStyle("B{$row}:B{$row}")->applyFromArray($labelCell);
                    $sheet->getRowDimension($row)->setRowHeight(max(30, 16 * (substr_count((string) $actividad, "\n") + 1)));
                    $row++;
                }

                // Sin fila vacia entre temas (igual que la plantilla).
            }
            $row++; // separacion entre unidades
        }
    }

    /**
     * Escribe una fila de identificacion del Plan de Clase (docente/asignatura).
     * label1 en B, valor1 en C:D, label2 en E, valor2 en F:I.
     */
    private function writePlanClaseIdentRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, string $label1, string $value1, string $label2, string $value2, array $labelCell, array $valueCell): void
    {
        $borderColor = '7C3AED';
        $border = ['borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => $borderColor]]]];

        $sheet->setCellValue("B{$row}", $label1);
        $sheet->mergeCells("C{$row}:D{$row}");
        $sheet->setCellValue("C{$row}", $value1);
        $sheet->setCellValue("E{$row}", $label2);
        $sheet->mergeCells("F{$row}:I{$row}");
        $sheet->setCellValue("F{$row}", $value2);

        $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($border);
        $sheet->getStyle("B{$row}")->applyFromArray($labelCell);
        $sheet->getStyle("E{$row}")->applyFromArray($labelCell);
        $sheet->getStyle("C{$row}:D{$row}")->applyFromArray($valueCell);
        $sheet->getStyle("F{$row}:I{$row}")->applyFromArray($valueCell);
        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    /**
     * Escribe una fila label (Col B) + valor (Col C:I) del Plan de Clase.
     */
    private function writePlanClaseLabelValueRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, string $label, string $value, array $labelCell, array $valueCell, int $minHeight): void
    {
        $borderColor = '7C3AED';
        $border = ['borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => $borderColor]]]];

        $sheet->setCellValue("B{$row}", $label);
        $sheet->mergeCells("C{$row}:I{$row}");
        $sheet->setCellValue("C{$row}", $value);
        $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($border);
        $sheet->getStyle("B{$row}")->applyFromArray($labelCell);
        $sheet->getStyle("C{$row}:I{$row}")->applyFromArray($valueCell);
        $sheet->getRowDimension($row)->setRowHeight(max($minHeight, 16 * max(1, substr_count($value, "\n") + 1)));
    }

    /**
     * Escribe una fila de contenido (Conceptual/Actitudinal) del Plan de Clase.
     */
    private function writePlanClaseContenidoRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, string $label, string $value, array $labelCell, array $valueCell, int $minHeight, bool $includeContenidosLabel = true): void
    {
        $borderColor = '7C3AED';
        $border = ['borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => $borderColor]]]];

        if ($includeContenidosLabel) {
            $sheet->setCellValue("B{$row}", 'Contenidos:');
            $sheet->getStyle("B{$row}")->applyFromArray($labelCell);
        }
        $sheet->setCellValue("C{$row}", $label);
        $sheet->mergeCells("D{$row}:I{$row}");
        $sheet->setCellValue("D{$row}", $value);
        $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($border);
        $sheet->getStyle("C{$row}")->applyFromArray($labelCell);
        $sheet->getStyle("D{$row}:I{$row}")->applyFromArray($valueCell);
        $sheet->getRowDimension($row)->setRowHeight(max($minHeight, 16 * max(1, substr_count($value, "\n") + 1)));
    }

    /**
     * Escribe una fila FORMATIVA/SUMATIVA del Plan de Clase.
     */
    private function writePlanClaseEvalRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, string $tipoLabel, string $act, string $inst, string $evid, array $labelCell, array $valueCell, int $minHeight): void
    {
        $borderColor = '7C3AED';
        $border = ['borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => $borderColor]]]];

        $sheet->setCellValue("B{$row}", $tipoLabel);
        $sheet->mergeCells("C{$row}:D{$row}");
        $sheet->setCellValue("C{$row}", $act);
        $sheet->mergeCells("E{$row}:G{$row}");
        $sheet->setCellValue("E{$row}", $inst);
        $sheet->mergeCells("H{$row}:I{$row}");
        $sheet->setCellValue("H{$row}", $evid);

        $sheet->getStyle("B{$row}:I{$row}")->applyFromArray($border);
        $sheet->getStyle("B{$row}")->applyFromArray($labelCell);
        $sheet->getStyle("C{$row}:D{$row}")->applyFromArray($valueCell);
        $sheet->getStyle("E{$row}:G{$row}")->applyFromArray($valueCell);
        $sheet->getStyle("H{$row}:I{$row}")->applyFromArray($valueCell);
        $maxLines = max(substr_count($act, "\n"), substr_count($inst, "\n"), substr_count($evid, "\n"));
        $sheet->getRowDimension($row)->setRowHeight(max($minHeight, 16 * ($maxLines + 1)));
    }

    /**
     * Extrae actividades/instrumentos/evidencias de un campo de evaluacion que
     * puede venir como string o como array estructurado.
     */
    private function extractEvaluacionParts($evaluacion): array
    {
        if (is_string($evaluacion) || is_numeric($evaluacion)) {
            $txt = trim((string) $evaluacion);
            return [$txt, $txt, $txt];
        }
        if (is_array($evaluacion) || is_object($evaluacion)) {
            $arr = is_object($evaluacion) ? (array) $evaluacion : $evaluacion;
            $act = $this->stringifyList($arr['actividades'] ?? ($arr['actividad'] ?? ($arr['actividades_y_tecnicas'] ?? '')));
            $inst = $this->stringifyList($arr['instrumentos'] ?? ($arr['instrumento'] ?? ''));
            $evid = $this->stringifyList($arr['evidencias'] ?? ($arr['evidencia'] ?? ''));
            return [$act, $inst, $evid];
        }
        return ['', '', ''];
    }

    /**
     * Convierte un valor (string o array) en texto plano separado por saltos de linea.
     */
    private function stringifyList($value): string
    {
        if ($value === null || $value === '') return '';
        if (is_string($value)) return $value;
        if (is_array($value)) {
            return collect($value)
                ->map(fn ($v) => is_array($v) ? ($v['descripcion'] ?? json_encode($v)) : (string) $v)
                ->filter(fn ($v) => trim((string) $v) !== '')
                ->implode("\n");
        }
        return (string) $value;
    }

    private function numberedList(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }
        if (preg_match('/^\s*\d+[\.\)]\s/m', $text)) {
            return $text;
        }
        $lines = preg_split('/\r?\n/', $text);
        $numbered = [];
        $counter = 0;
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            $counter++;
            $numbered[] = $counter . '. ' . $trim;
        }
        return implode("\n", $numbered);
    }

    /**
     * Genera un PDF con la estructura jerarquica completa de UNA asignatura.
     * El frontend envia el payload completo de la asignatura (que ya tiene
     * unidades > temas > logros > indicadores, etc.) y el backend lo renderiza
     * a traves de la vista Blade programa-analitico-pdf.
     */
    public function exportarPdfAsignatura(Request $request)
    {
        $validated = $request->validate([
            'asignatura' => 'required|array',
        ]);

        $asignatura = $validated['asignatura'];
        $codigo = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($asignatura['codigo'] ?? 'asignatura'));
        $fileName = sprintf('programa_analitico_%s_%s.pdf', $codigo, date('Ymd_His'));

        $pdf = Pdf::loadView('restauracion.programa-analitico-pdf', [
            'asignatura' => $asignatura,
            'generado_en' => now()->format('d/m/Y H:i'),
        ])->setPaper('letter', 'portrait');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $fileName),
        ]);
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

    // =====================================================================
    // Helpers para export/import Excel
    // =====================================================================

    /**
     * Centraliza la llamada server-to-server a la API externa (reusado por
     * extraerDesdeApiExterna y exportarExcel).
     */
    private function fetchFromExternalApi(string $apiUrl, string $token, int $carreraId, ?int $sedeId): array
    {
        $baseUrl = rtrim($apiUrl, '/');
        $path = parse_url($baseUrl, PHP_URL_PATH) ?: '';
        $alreadyIncludesEndpoint = str_contains($path, '/api/export/documentacion-carrera');

        $targetUrl = $alreadyIncludesEndpoint
            ? $baseUrl
            : $baseUrl . '/api/export/documentacion-carrera';

        $query = [
            'carrera_id' => $carreraId,
            'token' => $token,
        ];
        if ($sedeId) {
            $query['sede_id'] = $sedeId;
        }

        $skipSslVerify = app()->environment('local')
            && filter_var(env('RESTAURACION_SKIP_SSL_VERIFY', false), FILTER_VALIDATE_BOOLEAN);

        $requestBuilder = Http::timeout(45)->acceptJson();
        if ($skipSslVerify) {
            $requestBuilder = $requestBuilder->withoutVerifying();
        }
        if ($token !== '') {
            $requestBuilder = $requestBuilder->withToken($token);
        }

        $response = $requestBuilder->get($targetUrl, $query);

        if (!$response->successful()) {
            throw new \RuntimeException(sprintf(
                'La API externa respondio con HTTP %d: %s',
                $response->status(),
                substr((string) $response->body(), 0, 300)
            ));
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            throw new \RuntimeException('La API externa no devolvio JSON valido.');
        }

        return $payload;
    }

    /**
     * Acepta el payload crudo que devuelve la API externa y devuelve la lista
     * de asignaturas. Tolera varias formas: {data:{asignaturas:[...]}},
     * {asignaturas:[...]}, {data:[...]} o una lista directa.
     */
    private function extractAsignaturasFromPayload(array $payload): array
    {
        $candidates = [
            $payload['data']['asignaturas'] ?? null,
            $payload['asignaturas'] ?? null,
            $payload['data'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && !empty($candidate) && array_is_list($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function writeAsignaturasSheet(Spreadsheet $spreadsheet, array $asignaturas): void
    {
        $headers = [
            'codigo', 'nombre', 'plan_estudios', 'creditos', 'semestre',
            'carrera_id', 'sede_id',
            'descripcion', 'justificacion', 'proposito_general',
            'competencia_asignatura', 'competencia_global_especifica', 'elementos_competencia',
            'contenido_minimo', 'metodologia_general', 'sistema_evaluacion', 'requisitos',
        ];

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Asignaturas');
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeaderRow($sheet, count($headers));

        $row = 2;
        foreach ($asignaturas as $asig) {
            $sheet->fromArray([
                (string) ($asig['codigo'] ?? ''),
                (string) ($asig['nombre'] ?? ''),
                (string) ($asig['plan_estudios'] ?? ''),
                $asig['creditos'] ?? null,
                $asig['semestre'] ?? null,
                $asig['carrera_id'] ?? null,
                $asig['sede_id'] ?? null,
                $asig['descripcion'] ?? null,
                $asig['justificacion'] ?? null,
                $asig['proposito_general'] ?? null,
                $asig['competencia_asignatura'] ?? null,
                $asig['competencia_global_especifica'] ?? null,
                $this->jsonCell($asig['elementos_competencia'] ?? null),
                $asig['contenido_minimo'] ?? null,
                $this->jsonCell($asig['metodologia_general'] ?? null),
                $this->jsonCell($asig['sistema_evaluacion'] ?? null),
                $asig['requisitos'] ?? null,
            ], null, 'A' . $row);
            $row++;
        }

        $this->autosizeAndFreeze($sheet, count($headers));
    }

    private function writeUnidadesTemasSheet(Spreadsheet $spreadsheet, array $asignaturas): void
    {
        $headers = [
            'codigo_asignatura', 'unidad_numero', 'unidad_titulo', 'unidad_tipo',
            'unidad_objetivo', 'unidad_contenido_minimo', 'unidad_elemento_competencia',
            'tema_orden', 'tema_titulo', 'tema_tipo',
            'horas_teoricas', 'horas_practicas', 'resultado_aprendizaje',
            'contenido_conceptual', 'contenido_procedimental', 'contenido_actitudinal',
            'contenido_items',
            'estrategias_metodologicas', 'estrategias_aprendizaje', 'estrategias_recursos',
            'evaluacion_formativa', 'evaluacion_sumativa',
        ];

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Unidades_Temas');
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeaderRow($sheet, count($headers));

        $row = 2;
        foreach ($asignaturas as $asig) {
            $codigo = (string) ($asig['codigo'] ?? '');
            foreach (($asig['unidades'] ?? []) as $unidad) {
                foreach (($unidad['temas'] ?? []) as $tema) {
                    $sheet->fromArray([
                        $codigo,
                        (string) ($unidad['numero'] ?? ''),
                        (string) ($unidad['titulo'] ?? ''),
                        $unidad['tipo'] ?? null,
                        $unidad['objetivo'] ?? null,
                        $unidad['contenido_minimo'] ?? null,
                        $unidad['elemento_competencia'] ?? null,
                        $tema['orden'] ?? null,
                        (string) ($tema['titulo'] ?? ''),
                        $tema['tipo'] ?? null,
                        $tema['horas_teoricas'] ?? null,
                        $tema['horas_practicas'] ?? null,
                        $tema['resultado_aprendizaje'] ?? null,
                        $this->jsonCell($tema['contenido_conceptual'] ?? null),
                        $this->jsonCell($tema['contenido_procedimental'] ?? null),
                        $this->jsonCell($tema['contenido_actitudinal'] ?? null),
                        $this->jsonCell($tema['contenido_items'] ?? null),
                        $tema['estrategias_metodologicas'] ?? null,
                        $tema['estrategias_aprendizaje'] ?? null,
                        $this->jsonCell($tema['estrategias_recursos'] ?? null),
                        $this->jsonCell($tema['evaluacion_formativa'] ?? null),
                        $this->jsonCell($tema['evaluacion_sumativa'] ?? null),
                    ], null, 'A' . $row);
                    $row++;
                }
            }
        }

        $this->autosizeAndFreeze($sheet, count($headers));
    }

    private function writeLogrosIndicadoresSheet(Spreadsheet $spreadsheet, array $asignaturas): void
    {
        $headers = [
            'codigo_asignatura', 'unidad_numero', 'tema_orden',
            'logro_descripcion', 'logro_tipo', 'logro_periodo',
            'indicador_descripcion',
        ];

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Logros_Indicadores');
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeaderRow($sheet, count($headers));

        $row = 2;
        foreach ($asignaturas as $asig) {
            $codigo = (string) ($asig['codigo'] ?? '');
            foreach (($asig['unidades'] ?? []) as $unidad) {
                $unidadNumero = (string) ($unidad['numero'] ?? '');
                foreach (($unidad['temas'] ?? []) as $tema) {
                    $temaOrden = $tema['orden'] ?? null;
                    foreach (($tema['logros_esperados'] ?? []) as $logro) {
                        $indicadores = $logro['indicadores'] ?? [];
                        if (empty($indicadores)) {
                            $sheet->fromArray([
                                $codigo, $unidadNumero, $temaOrden,
                                (string) ($logro['descripcion'] ?? ''),
                                $logro['tipo_logro'] ?? null,
                                $logro['periodo'] ?? null,
                                '',
                            ], null, 'A' . $row);
                            $row++;
                            continue;
                        }
                        foreach ($indicadores as $indicador) {
                            $sheet->fromArray([
                                $codigo, $unidadNumero, $temaOrden,
                                (string) ($logro['descripcion'] ?? ''),
                                $logro['tipo_logro'] ?? null,
                                $logro['periodo'] ?? null,
                                (string) ($indicador['descripcion'] ?? ''),
                            ], null, 'A' . $row);
                            $row++;
                        }
                    }
                }
            }
        }

        $this->autosizeAndFreeze($sheet, count($headers));
    }

    private function writeBibliografiasSheet(Spreadsheet $spreadsheet, array $asignaturas): void
    {
        $headers = [
            'codigo_asignatura', 'titulo', 'autor', 'editorial', 'edicion',
            'anio', 'tipo', 'isbn', 'paginas', 'descripcion',
        ];

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Bibliografias');
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeaderRow($sheet, count($headers));

        $row = 2;
        foreach ($asignaturas as $asig) {
            $codigo = (string) ($asig['codigo'] ?? '');
            foreach (($asig['bibliografias'] ?? []) as $bib) {
                $sheet->fromArray([
                    $codigo,
                    (string) ($bib['titulo'] ?? ''),
                    $bib['autor'] ?? null,
                    $bib['editorial'] ?? null,
                    $bib['edicion'] ?? null,
                    $bib['anio'] ?? null,
                    $bib['tipo'] ?? null,
                    $bib['isbn'] ?? null,
                    $bib['paginas'] ?? null,
                    $bib['descripcion'] ?? null,
                ], null, 'A' . $row);
                $row++;
            }
        }

        $this->autosizeAndFreeze($sheet, count($headers));
    }

    private function writeDocentesSheet(Spreadsheet $spreadsheet, array $asignaturas): void
    {
        $headers = [
            'codigo_asignatura', 'nombre_completo', 'ci', 'email',
        ];

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Docentes');
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeaderRow($sheet, count($headers));

        $row = 2;
        foreach ($asignaturas as $asig) {
            $codigo = (string) ($asig['codigo'] ?? '');
            foreach (($asig['docentes'] ?? []) as $docente) {
                $sheet->fromArray([
                    $codigo,
                    (string) ($docente['nombre_completo'] ?? ''),
                    $docente['ci'] ?? null,
                    $docente['email'] ?? null,
                ], null, 'A' . $row);
                $row++;
            }
        }

        $this->autosizeAndFreeze($sheet, count($headers));
    }

    private function styleHeaderRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $colCount): void
    {
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);
        $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E40AF']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);
    }

    private function autosizeAndFreeze(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $colCount): void
    {
        for ($i = 1; $i <= $colCount; $i++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
        $sheet->setAutoFilter('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount) . '1');
        $sheet->freezePane('A2');
    }

    private function jsonCell(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                return $value;
            }
            return $value;
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }

    private function readSheetAsRows(Spreadsheet $spreadsheet, string $title): array
    {
        $sheet = $spreadsheet->getSheetByName($title);
        if ($sheet === null) {
            return [];
        }

        $rows = $sheet->toArray(null, true, true, false);
        if (empty($rows)) {
            return [];
        }

        $headers = array_map(
            fn ($h) => strtolower(trim((string) $h)),
            array_shift($rows)
        );

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $assoc = [];
            foreach ($headers as $idx => $header) {
                $assoc[$header] = $row[$idx] ?? null;
            }
            // Saltar filas vacias (todas las celdas nulas o vacias)
            $nonEmpty = array_filter($assoc, fn ($v) => $v !== null && $v !== '');
            if (empty($nonEmpty)) {
                continue;
            }
            $result[] = $assoc;
        }

        return $result;
    }

    private function groupRowsByKey(array $rows, string $key): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $k = (string) ($row[$key] ?? '');
            if ($k === '') {
                continue;
            }
            $grouped[$k][] = $row;
        }
        return $grouped;
    }

    /**
     * Reconstruye el payload que espera aplicarPayloadAsignatura a partir de las
     * filas de las hojas relacionadas, agrupadas por codigo_asignatura.
     */
    private function buildPayloadFromExcelRows(array $asigRow, array $byCodigo): array
    {
        $utRows = $byCodigo['ut'] ?? [];
        $liRows = $byCodigo['li'] ?? [];
        $bibRows = $byCodigo['bib'] ?? [];
        $docRows = $byCodigo['doc'] ?? [];

        // Agrupar unidades + temas por unidad_numero
        $unidadesMap = [];
        foreach ($utRows as $ut) {
            $uNum = (string) ($ut['unidad_numero'] ?? '');
            if ($uNum === '') {
                continue;
            }
            if (!isset($unidadesMap[$uNum])) {
                $unidadesMap[$uNum] = [
                    'numero' => $uNum,
                    'titulo' => $ut['unidad_titulo'] ?? null,
                    'tipo' => $ut['unidad_tipo'] ?? null,
                    'objetivo' => $ut['unidad_objetivo'] ?? null,
                    'contenido_minimo' => $ut['unidad_contenido_minimo'] ?? null,
                    'elemento_competencia' => $ut['unidad_elemento_competencia'] ?? null,
                    'temas' => [],
                ];
            }
            $temaKey = (string) ($ut['tema_orden'] ?? '');
            $unidadesMap[$uNum]['temas'][$temaKey] = [
                'orden' => $ut['tema_orden'] ?? null,
                'titulo' => $ut['tema_titulo'] ?? null,
                'tipo' => $ut['tema_tipo'] ?? null,
                'horas_teoricas' => $this->toIntOrNull($ut['horas_teoricas'] ?? null),
                'horas_practicas' => $this->toIntOrNull($ut['horas_practicas'] ?? null),
                'resultado_aprendizaje' => $ut['resultado_aprendizaje'] ?? null,
                'contenido_conceptual' => $this->decodeJsonOrArray($ut['contenido_conceptual'] ?? null),
                'contenido_procedimental' => $this->decodeJsonOrArray($ut['contenido_procedimental'] ?? null),
                'contenido_actitudinal' => $this->decodeJsonOrArray($ut['contenido_actitudinal'] ?? null),
                'contenido_items' => $this->decodeJsonOrArray($ut['contenido_items'] ?? null),
                'estrategias_metodologicas' => $ut['estrategias_metodologicas'] ?? null,
                'estrategias_aprendizaje' => $ut['estrategias_aprendizaje'] ?? null,
                'estrategias_recursos' => $this->decodeJsonOrArray($ut['estrategias_recursos'] ?? null),
                'evaluacion_formativa' => $this->decodeJsonOrArray($ut['evaluacion_formativa'] ?? null),
                'evaluacion_sumativa' => $this->decodeJsonOrArray($ut['evaluacion_sumativa'] ?? null),
                'logros_esperados' => [],
            ];
        }

        // Agrupar logros + indicadores por (unidad, tema)
        $logrosByTema = [];
        foreach ($liRows as $li) {
            $uNum = (string) ($li['unidad_numero'] ?? '');
            $temaOrden = (string) ($li['tema_orden'] ?? '');
            $descripcion = trim((string) ($li['logro_descripcion'] ?? ''));
            if ($uNum === '' || $descripcion === '') {
                continue;
            }
            $temaKey = $uNum . '::' . $temaOrden;
            $logroKey = $temaKey . '::' . $descripcion;
            if (!isset($logrosByTema[$logroKey])) {
                $logrosByTema[$logroKey] = [
                    'tema_key' => $temaKey,
                    'descripcion' => $descripcion,
                    'tipo_logro' => $li['logro_tipo'] ?? null,
                    'periodo' => $li['logro_periodo'] ?? null,
                    'indicadores' => [],
                ];
            }
            $ind = trim((string) ($li['indicador_descripcion'] ?? ''));
            if ($ind !== '') {
                $logrosByTema[$logroKey]['indicadores'][] = ['descripcion' => $ind];
            }
        }

        // Insertar logros en los temas correspondientes
        foreach ($logrosByTema as $logro) {
            [$uNum, $temaOrden] = explode('::', $logro['tema_key'], 2);
            if (!isset($unidadesMap[$uNum]['temas'][$temaOrden])) {
                continue;
            }
            $unidadesMap[$uNum]['temas'][$temaOrden]['logros_esperados'][] = [
                'descripcion' => $logro['descripcion'],
                'tipo_logro' => $logro['tipo_logro'],
                'periodo' => $logro['periodo'],
                'indicadores' => $logro['indicadores'],
            ];
        }

        // Reindexar temas como lista
        foreach ($unidadesMap as $uNum => &$unidad) {
            $unidad['temas'] = array_values($unidad['temas']);
        }
        unset($unidad);

        $payload = [
            'asignatura_id' => $this->toIntOrNull($asigRow['asignatura_id'] ?? null),
            'codigo' => $asigRow['codigo'] ?? null,
            'nombre' => $asigRow['nombre'] ?? null,
            'plan_estudios' => $asigRow['plan_estudios'] ?? null,
            'creditos' => $this->toIntOrNull($asigRow['creditos'] ?? null),
            'semestre' => $this->toIntOrNull($asigRow['semestre'] ?? null),
            'carrera_id' => $this->toIntOrNull($asigRow['carrera_id'] ?? null),
            'sede_id' => $this->toIntOrNull($asigRow['sede_id'] ?? null),
            'descripcion' => $asigRow['descripcion'] ?? null,
            'justificacion' => $asigRow['justificacion'] ?? null,
            'proposito_general' => $asigRow['proposito_general'] ?? null,
            'metodologia_general' => $this->decodeJsonOrArray($asigRow['metodologia_general'] ?? null),
            'sistema_evaluacion' => $this->decodeJsonOrArray($asigRow['sistema_evaluacion'] ?? null),
            'contenido_minimo' => $asigRow['contenido_minimo'] ?? null,
            'requisitos' => $asigRow['requisitos'] ?? null,
            'competencia_asignatura' => $asigRow['competencia_asignatura'] ?? null,
            'competencia_global_especifica' => $asigRow['competencia_global_especifica'] ?? null,
            'elementos_competencia' => $this->decodeJsonOrArray($asigRow['elementos_competencia'] ?? null),
            'docentes' => array_map(
                fn ($d) => [
                    'nombre_completo' => $d['nombre_completo'] ?? null,
                    'ci' => $d['ci'] ?? null,
                    'email' => $d['email'] ?? null,
                ],
                $docRows
            ),
            'bibliografias' => array_map(
                fn ($b) => [
                    'titulo' => $b['titulo'] ?? null,
                    'autor' => $b['autor'] ?? null,
                    'editorial' => $b['editorial'] ?? null,
                    'edicion' => $b['edicion'] ?? null,
                    'anio' => $this->toIntOrNull($b['anio'] ?? null),
                    'tipo' => $b['tipo'] ?? null,
                    'isbn' => $b['isbn'] ?? null,
                    'paginas' => $this->toIntOrNull($b['paginas'] ?? null),
                    'descripcion' => $b['descripcion'] ?? null,
                ],
                $bibRows
            ),
            'unidades' => array_values($unidadesMap),
        ];

        return $payload;
    }

    private function decodeJsonOrArray(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            return $value;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
            return $decoded;
        }
        // Si no es JSON valido, lo envolvemos como un array de un solo item
        // para no perder el texto editado a mano.
        return [$trimmed];
    }

    private function toIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }
}
