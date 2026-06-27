<?php
/**
 * Test round-trip del PAC.
 *
 * Construye un PAC con buildPacSheet, lo lee, y simula el algoritmo completo
 * de AsignaturaController::importExcel para verificar que cada campo
 * se importaria con el valor correcto.
 *
 * Uso: php test_pac_roundtrip.php
 * Exit 0 = todos los campos OK, exit 1 = hay fallas.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function buildPayload(bool $withDocente, bool $withFases): array {
    return [
        'codigo' => 'MAT-101',
        'nombre' => 'Matemáticas I',
        'creditos' => 4,
        'semestre' => 1,
        'carrera' => ['nombre' => 'Ingeniería Civil'],
        'area_desempenio' => '',
        'tipo_curso' => '',
        'modalidad' => '',
        'requisitos' => '',
        'carga_horaria_total' => '',
        'sesiones_semanales' => 4,
        'sesiones_semanales_teoricas' => 2,
        'sesiones_semanales_practicas' => 2,
        'docente' => $withDocente ? [
            'nombre' => 'Juan Pérez',
            'email' => 'jperez@test.com',
            'formacion' => 'MSc Matemáticas',
            'telefono' => '12345678',
        ] : [],
        'justificacion' => 'Justificación completa de la materia para el programa académico.',
        'proposito_general' => 'Propósito general del curso en cuestión.',
        'competencia_global_especifica' => 'Aplicar conceptos matemáticos.',
        'competencia_asignatura' => 'Resolver problemas de cálculo.',
        'elementos_competencia' => ['Conocer', 'Aplicar', 'Analizar', 'Sintetizar'],
        'metodologia_general' => [
            'aula' => 'Clases magistrales participativas',
            'simulacion' => 'Simulación con software especializado',
            'hospital' => 'Práctica hospitalaria supervisada',
        ],
        'sistema_evaluacion' => [
            'intro' => 'La evaluación es continua y formativa.',
            'diagnostica' => $withFases ? 'Evaluación inicial de conocimientos previos.' : '',
            'formativa' => $withFases ? 'Quiz durante el proceso de aprendizaje.' : '',
            'sumativa' => $withFases ? 'Examen final acumulativo.' : '',
            'ponderacion' => '1er Parcial 30%, 2do Parcial 30%',
            'final' => 'Examen final 40%',
        ],
        'reglamento_normativa' => [
            'clase' => 'Asistencia 80% y participación activa',
            'laboratorio' => 'Uso de bata y puntualidad',
        ],
        'unidades' => [],
        'bibliografias' => [],
    ];
}

function buildPac(array $asig): Spreadsheet {
    $ctrl = new App\Http\Controllers\RestauracionAcademicaController();
    $ref = new ReflectionClass($ctrl);
    $m = $ref->getMethod('buildPacSheet');
    $m->setAccessible(true);
    $sp = new Spreadsheet();
    $sp->removeSheetByIndex($sp->getIndex($sp->getActiveSheet()));
    $s = $sp->createSheet();
    $s->setTitle('PAC');
    $m->invoke($ctrl, $s, $asig);
    return $sp;
}

function rowsOf(string $xlsxPath): array {
    $reader = new XlsxReader();
    $sp = $reader->load($xlsxPath);
    return $sp->getSheetByName('PAC')->toArray(null, false, false, false);
}

function simulateImport(array $rows): array {
    $searchGrid = function ($label, $limitCols = 15, $limitRows = 10, $strictHeaderSkip = true) use ($rows) {
        $labelLower = mb_strtolower(trim($label));
        foreach ($rows as $rIdx => $row) {
            if (empty($row)) continue;
            foreach ($row as $cIdx => $cell) {
                $cellVal = mb_strtolower(trim($cell ?? ''));
                if ($cellVal !== '' && str_contains($cellVal, $labelLower)) {
                    for ($dr = 0; $dr < $limitRows; $dr++) {
                        for ($dc = 0; $dc < $limitCols; $dc++) {
                            $checkRow = $rIdx + $dr;
                            $checkCol = $cIdx + $dc;
                            if (!isset($rows[$checkRow][$checkCol])) continue;
                            $v = trim($rows[$checkRow][$checkCol]);
                            if ($v === '') continue;
                            $vLower = mb_strtolower($v);
                            if ($vLower === $labelLower) continue;
                            if (str_contains($vLower, $labelLower) && strlen($v) < 80) continue;
                            if ($strictHeaderSkip && preg_match('/^\d+[\.\-\s\)]+/', $v) && strlen($v) < 100) continue;
                            if (str_contains($v, ':') && strlen($v) < 30) continue;
                            if (preg_match('/^\d+[\.\)-]\s*$/', $v)) continue;
                            return $v;
                        }
                    }
                }
            }
        }
        return null;
    };

    $out = [];

    $out['modalidad'] = $searchGrid('modalidad');
    $out['tipo_curso'] = $searchGrid('tipo de curso');
    $out['area_desempenio'] = $searchGrid('área de desempeño');
    $out['requisitos'] = $searchGrid('pre-requisito');
    $t = $searchGrid('teóricas:');
    $out['sesiones_teoricas'] = $t !== null ? intval($t) : null;
    $p = $searchGrid('prácticas:');
    $out['sesiones_practicas'] = $p !== null ? intval($p) : null;

    $out['docente_email'] = $searchGrid('email') ?: $searchGrid('correo');
    $out['docente_formacion'] = $searchGrid('formación');
    $out['docente_telefono'] = $searchGrid('teléfono');

    $out['justificacion'] = $searchGrid('justificación de la asignatura');
    $out['proposito'] = $searchGrid('propósito general de la unidad');

    $out['comp_global'] = $searchGrid('competencia global específica');
    $out['comp_asignatura'] = $searchGrid('unidad de competencia específica');

    $metodologia = [];
    $vAula = $searchGrid('en el aula');
    if ($vAula && strlen($vAula) > 5) $metodologia['aula'] = $vAula;
    $vSim = $searchGrid('centro de simulación');
    if ($vSim && strlen($vSim) > 5) $metodologia['simulacion'] = $vSim;
    $vHosp = $searchGrid('hospital y centros de salud');
    if ($vHosp && strlen($vHosp) > 5) $metodologia['hospital'] = $vHosp;
    $out['metodologia'] = $metodologia;

    $evaluacion = ['intro' => '', 'diagnostica' => '', 'formativa' => '', 'sumativa' => '', 'ponderacion' => '', 'final' => ''];
    foreach ($rows as $rIdx => $row) {
        foreach ($row as $cIdx => $cell) {
            $cellVal = mb_strtolower(trim($cell ?? ''));
            if (str_contains($cellVal, '9. sistema de evaluación')) {
                $rIntro = $rIdx + 2;
                if (isset($rows[$rIntro])) {
                    $evaluacion['intro'] = trim($rows[$rIntro][1] ?? '');
                    $fasesRaw = trim($rows[$rIntro][5] ?? '');
                    if ($fasesRaw !== '') {
                        if (preg_match('/a\.\s*(.*?)\s+b\.\s*(.*?)\s+c\.\s*(.*)/is', $fasesRaw, $mm)) {
                            $evaluacion['diagnostica'] = trim($mm[1]);
                            $evaluacion['formativa'] = trim($mm[2]);
                            $evaluacion['sumativa'] = trim($mm[3]);
                        } else {
                            $evaluacion['formativa'] = $fasesRaw;
                        }
                    }
                }
                $rPond = $rIdx + 3;
                if (isset($rows[$rPond])) {
                    $fullBlock = trim($rows[$rPond][1] ?? '');
                    if (str_contains($fullBlock, 'La evaluación final')) {
                        $parts = explode('La evaluación final', $fullBlock);
                        $evaluacion['ponderacion'] = trim($parts[0]);
                        $evaluacion['final'] = 'La evaluación final ' . trim($parts[1]);
                    } else {
                        $evaluacion['ponderacion'] = $fullBlock;
                    }
                }
                break 2;
            }
        }
    }
    $out['sistema_evaluacion'] = $evaluacion;

    $normativa = ['clase' => '', 'laboratorio' => ''];
    foreach ($rows as $rIdx => $row) {
        foreach ($row as $cIdx => $cell) {
            $cellVal = mb_strtolower(trim($cell ?? ''));
            if (str_contains($cellVal, '12.- criterios y normativa') || str_contains($cellVal, 'reglamento para las clases')) {
                $allText = '';
                for ($dr = 1; $dr <= 9; $dr++) {
                    if (isset($rows[$rIdx + $dr])) {
                        $rowStr = mb_strtolower(implode(' ', array_filter($rows[$rIdx + $dr])));
                        if (str_contains($rowStr, '14.- bibliografía')) break;
                        foreach ($rows[$rIdx + $dr] as $cVal) {
                            $v = trim($cVal ?? '');
                            if ($v !== '') $allText .= $v . "\n";
                        }
                    }
                }
                if (str_contains($allText, 'Además, en laboratorio')) {
                    $parts = explode('Además, en laboratorio', $allText);
                    $normativa['clase'] = trim($parts[0]);
                    $normativa['laboratorio'] = 'Además, en laboratorio' . trim($parts[1]);
                } else {
                    $normativa['clase'] = trim($allText);
                }
                break 2;
            }
        }
    }
    $out['reglamento'] = $normativa;

    return $out;
}

function runScenario(string $label, array $payload, array $expectations, ?callable $modifier = null): array {
    $sp = buildPac($payload);
    $tmp = tempnam(sys_get_temp_dir(), 'pactest_');
    $w = new Xlsx($sp);
    $w->save($tmp);

    if ($modifier !== null) {
        $modifier($tmp);
    }

    $rows = rowsOf($tmp);
    unlink($tmp);

    $imported = simulateImport($rows);
    $results = [];
    foreach ($expectations as $key => $expected) {
        $actual = $imported[$key] ?? null;
        if (is_array($actual)) $actual = json_encode($actual, JSON_UNESCAPED_UNICODE);
        if (is_array($expected)) $expected = json_encode($expected, JSON_UNESCAPED_UNICODE);
        $match = ($actual === $expected);
        $results[$key] = ['expected' => $expected, 'actual' => $actual, 'ok' => $match];
    }
    return ['label' => $label, 'rows' => count($rows), 'results' => $results];
}

function printResult(array $scenario): int {
    echo "=== Escenario: {$scenario['label']} ({$scenario['rows']} filas) ===" . PHP_EOL;
    $fails = 0;
    foreach ($scenario['results'] as $key => $r) {
        $status = $r['ok'] ? 'OK  ' : 'FAIL';
        if (!$r['ok']) $fails++;
        $exp = $r['expected'] ?? '(null)';
        $act = $r['actual'] ?? '(null)';
        echo "  $status  " . str_pad($key, 22) . "  exp=" . str_pad(substr((string)$exp, 0, 50), 50) . "  act=" . substr((string)$act, 0, 50) . PHP_EOL;
    }
    return $fails;
}

$totalFails = 0;

$scenarios = [
    [
        'label' => 'CON docente + CON fases',
        'payload' => buildPayload(true, true),
        'expectations' => [
            'modalidad' => '(Si corresponde)',
            'tipo_curso' => '(Si corresponde)',
            'area_desempenio' => null,
            'requisitos' => '(Si corresponde)',
            'sesiones_teoricas' => 2,
            'sesiones_practicas' => 2,
            'docente_email' => 'jperez@test.com',
            'docente_formacion' => 'MSc Matemáticas',
            'docente_telefono' => '12345678',
            'justificacion' => 'Justificación completa de la materia para el programa académico.',
            'proposito' => 'Propósito general del curso en cuestión.',
            'comp_global' => 'Aplicar conceptos matemáticos.',
            'comp_asignatura' => 'Resolver problemas de cálculo.',
            'metodologia' => [
                'aula' => 'Clases magistrales participativas',
                'simulacion' => 'Simulación con software especializado',
                'hospital' => 'Práctica hospitalaria supervisada',
            ],
            'sistema_evaluacion' => [
                'intro' => 'La evaluación es continua y formativa.',
                'diagnostica' => 'Evaluación inicial de conocimientos previos.',
                'formativa' => 'Quiz durante el proceso de aprendizaje.',
                'sumativa' => 'Examen final acumulativo.',
                'ponderacion' => '1er Parcial 30%, 2do Parcial 30%',
                'final' => 'La evaluación final Examen final 40%',
            ],
            'reglamento' => [
                'clase' => "Los estudiantes deberan cumplir el siguiente reglamento para las clases:\nAsistencia 80% y participación activa",
                'laboratorio' => "Además, en laboratorio:\nUso de bata y puntualidad",
            ],
        ],
    ],
    [
        'label' => 'SIN docente + SIN fases',
        'payload' => buildPayload(false, false),
        'expectations' => [
            'modalidad' => '(Si corresponde)',
            'tipo_curso' => '(Si corresponde)',
            'sesiones_teoricas' => 2,
            'sesiones_practicas' => 2,
            'docente_email' => null,
            'docente_formacion' => null,
            'docente_telefono' => null,
            'sistema_evaluacion' => [
                'intro' => 'La evaluación es continua y formativa.',
                'diagnostica' => '',
                'formativa' => '',
                'sumativa' => '',
                'ponderacion' => '1er Parcial 30%, 2do Parcial 30%',
                'final' => 'La evaluación final Examen final 40%',
            ],
        ],
    ],
    [
        'label' => 'CON CAMBIOS LOCALES (edicion manual del XLSX)',
        'payload' => buildPayload(false, false),
        'modifier' => function (string $xlsxPath) {
            $reader = new XlsxReader();
            $sp = $reader->load($xlsxPath);
            $sheet = $sp->getSheetByName('PAC');

            $rows = $sheet->toArray(null, false, false, false);
            $modifications = [];

            foreach ($rows as $rIdx => $row) {
                foreach ($row as $cIdx => $cell) {
                    $v = trim($cell ?? '');
                    $vLow = mb_strtolower($v);

                    if ($vLow === 'modalidad:') {
                        $coord = Coordinate::stringFromColumnIndex($cIdx + 2) . ($rIdx + 1);
                        $sheet->setCellValue($coord, 'Presencial');
                        $modifications[] = "MODALIDAD -> 'Presencial' en $coord";
                    }
                    if ($vLow === 'pre-requisito:') {
                        $coord = Coordinate::stringFromColumnIndex($cIdx + 2) . ($rIdx + 1);
                        $sheet->setCellValue($coord, 'Cálculo básico');
                        $modifications[] = "PRE-REQUISITO -> 'Cálculo básico' en $coord";
                    }
                    if ($vLow === 'justificación de la asignatura') {
                        $coord = Coordinate::stringFromColumnIndex($cIdx + 1) . ($rIdx + 2);
                        $sheet->setCellValue($coord, 'JUSTIFICACION EDITADA LOCALMENTE POR EL DOCENTE');
                        $modifications[] = "JUSTIFICACION -> texto nuevo en $coord";
                    }
                    if ($vLow === 'propósito general de la unidad académica') {
                        $coord = Coordinate::stringFromColumnIndex($cIdx + 1) . ($rIdx + 2);
                        $sheet->setCellValue($coord, 'PROPOSITO EDITADO POR EL DOCENTE');
                        $modifications[] = "PROPOSITO -> texto nuevo en $coord";
                    }
                }
            }

            $newDoc = $sp->createSheet();
            $newDoc->setTitle('Docente');
            $newDoc->fromArray([
                ['Nombre', 'Email', 'Formación', 'Teléfono'],
                ['María López', 'mlopez@test.com', 'PhD Educación', '87654321'],
            ]);
            $modifications[] = "Hoja 'Docente' agregada con datos";

            $w = new Xlsx($sp);
            $w->save($xlsxPath);

            echo "    [Modificaciones aplicadas: " . count($modifications) . "]" . PHP_EOL;
            foreach ($modifications as $m) {
                echo "      - $m" . PHP_EOL;
            }
        },
        'expectations' => [
            'modalidad' => 'Presencial',
            'tipo_curso' => '(Si corresponde)',
            'requisitos' => 'Cálculo básico',
            'sesiones_teoricas' => 2,
            'sesiones_practicas' => 2,
            'justificacion' => 'JUSTIFICACION EDITADA LOCALMENTE POR EL DOCENTE',
            'proposito' => 'PROPOSITO EDITADO POR EL DOCENTE',
            'sistema_evaluacion' => [
                'intro' => 'La evaluación es continua y formativa.',
                'diagnostica' => '',
                'formativa' => '',
                'sumativa' => '',
                'ponderacion' => '1er Parcial 30%, 2do Parcial 30%',
                'final' => 'La evaluación final Examen final 40%',
            ],
        ],
    ],
];

foreach ($scenarios as $sc) {
    $result = runScenario(
        $sc['label'],
        $sc['payload'],
        $sc['expectations'],
        $sc['modifier'] ?? null
    );
    $totalFails += printResult($result);
    echo PHP_EOL;
}

echo "========================================" . PHP_EOL;
if ($totalFails === 0) {
    echo "TODOS LOS TESTS PASARON" . PHP_EOL;
    exit(0);
} else {
    echo "FALLARON $totalFails CAMPOS" . PHP_EOL;
    exit(1);
}
