<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$host = '127.0.0.1';
$db   = 'academico';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // 1. Find Career
    $stmt = $pdo->prepare("SELECT id, nombre FROM carreras WHERE nombre LIKE :name");
    $stmt->execute(['name' => '%SISTEMAS%']);
    $carrera = $stmt->fetch();
    if (!$carrera) {
        throw new Exception("Error: No se encontró la carrera de Sistemas.");
    }
    echo "Generando planilla para: {$carrera['nombre']} (ID: {$carrera['id']})\n";

    // 2. Fetch Subjects via Pivot Table
    $sql = "
        SELECT a.id, a.codigo, a.nombre, ac.semestre
        FROM asignaturas a
        JOIN asignatura_carrera ac ON a.id = ac.asignatura_id
        WHERE ac.carrera_id = :id
        ORDER BY ac.semestre, a.nombre
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $carrera['id']]);
    $asignaturas = $stmt->fetchAll();

    if (empty($asignaturas)) {
        throw new Exception("Error: No se encontraron asignaturas para esta carrera.");
    }

    echo "Encontradas " . count($asignaturas) . " asignaturas.\n";

    // 3. Create Excel
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Exámenes Sistemas');

    $headers = ['Código Materia', 'Nombre Materia', 'Tipo Examen', 'Grupo (Teórico)', 'Semana', 'Fecha', 'Hora Inicio', 'Hora Fin', 'Aula'];
    $sheet->fromArray($headers, NULL, 'A1');

    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ];
    $sheet->getStyle('A1:I1')->applyFromArray($headerStyle);

    $row = 2;
    $examRanges = [
        '1er Parcial' => [7, 9],
        '2do Parcial' => [14, 16],
        'Final' => [18, 20],
        '2da Instancia' => [21, 25]
    ];

    $semesterStart = new DateTime('2026-02-02');
    $scheduleMap = []; // [semestre][fecha] = codigo

    foreach ($asignaturas as $asig) {
        $codigo = $asig['codigo'];
        $nombre = $asig['nombre'];
        $id = $asig['id'];
        $semestre = $asig['semestre'];

        // Get Valid Theoretical Group
        $sqlGroup = "
            SELECT g.id, g.nombre
            FROM grupos g
            JOIN horarios h ON g.id = h.grupo_id
            WHERE g.asignatura_id = :aid AND g.tipo = 'TEORICO'
            LIMIT 1
        ";
        $stmtG = $pdo->prepare($sqlGroup);
        $stmtG->execute(['aid' => $id]);
        $group = $stmtG->fetch();

        // Fallback
        if (!$group) {
            $sqlGroupAny = "
                SELECT g.id, g.nombre
                FROM grupos g
                JOIN horarios h ON g.id = h.grupo_id
                WHERE g.asignatura_id = :aid
                LIMIT 1
            ";
            $stmtGAny = $pdo->prepare($sqlGroupAny);
            $stmtGAny->execute(['aid' => $id]);
            $group = $stmtGAny->fetch();
        }

        if (!$group) {
            echo "SKIPPING: No group/schedule found for $codigo - $nombre\n";
            continue;
        }

        $grupoNombre = $group['nombre'];

        // Get ALL Schedules for this group (Multi-day support)
        // Order by day to be deterministic
        $sqlHorario = "SELECT dia, hora_inicio, hora_fin, aula_id FROM horarios WHERE grupo_id = :gid ORDER BY dia ASC";
        $stmtH = $pdo->prepare($sqlHorario);
        $stmtH->execute(['gid' => $group['id']]);
        $allHorarios = $stmtH->fetchAll();

        if (empty($allHorarios)) {
            echo "SKIPPING: Group found but NO SCHEDULE for $codigo - $nombre\n";
            continue;
        }

        // Process each exam type
        foreach ($examRanges as $type => $range) {
            list($minWeek, $maxWeek) = $range;

            $assignedDate = null;
            $assignedWeek = null;
            $chosenHorario = null;

            // Strategy: Try each available class day. For each day, try current week, then next...
            // Optimization: Iterate Weeks first? No, iterate Days first enables picking preferred day?
            // Actually, we want to find ANY slot.
            // Let's iterate Weeks (inner) then Days (outer)?
            // Better: Iterate Weeks (to keep 1st partial early) then Days.

            for ($w = $minWeek; $w <= $maxWeek; $w++) {
                if ($assignedDate) break;

                foreach ($allHorarios as $hCandidate) {

                    // Map Day
                    $diaRaw = trim($hCandidate['dia']);
                    $diaLower = mb_strtolower($diaRaw, 'UTF-8');
                    $dayMapLower = [
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
                        '1' => 1,
                        '2' => 2,
                        '3' => 3,
                        '4' => 4,
                        '5' => 5,
                        '6' => 6,
                        '7' => 7
                    ];
                    $diaNum = $dayMapLower[$diaLower] ?? (is_numeric($diaRaw) ? (int)$diaRaw : 1);

                    // Calculate Date
                    $calcDate = clone $semesterStart;
                    $calcDate->modify("+" . ($w - 1) . " weeks");
                    $currentDay = (int)$calcDate->format('N');
                    $diff = $diaNum - $currentDay;
                    $calcDate->modify("{$diff} days");
                    $candidateDate = $calcDate->format('Y-m-d');

                    // Check collision
                    if (!isset($scheduleMap[$semestre][$candidateDate])) {
                        $assignedDate = $candidateDate;
                        $assignedWeek = $w;
                        $chosenHorario = $hCandidate;
                        // Mark as taken
                        $scheduleMap[$semestre][$candidateDate] = $codigo;
                        break;
                    }
                }
            }

            // Fallback: If still no slot, force the first day of first week (Collision)
            if (!$assignedDate) {
                $chosenHorario = $allHorarios[0];
                $diaRaw = trim($chosenHorario['dia']);
                // ... map logic again ...
                $diaLower = mb_strtolower($diaRaw, 'UTF-8');
                $diaNum = $dayMapLower[$diaLower] ?? 1;

                $assignedWeek = $minWeek;
                $calcDate = clone $semesterStart;
                $calcDate->modify("+" . ($minWeek - 1) . " weeks");
                $currentDay = (int)$calcDate->format('N');
                $diff = $diaNum - $currentDay;
                $calcDate->modify("{$diff} days");
                $assignedDate = $calcDate->format('Y-m-d');
                echo "WARNING: NO SE ENCONTRO ESPACIO. Forzando colisión para $codigo ($type) en $assignedDate\n";
            }

            $data = [
                $codigo,
                $nombre,
                $type,
                $grupoNombre,
                $assignedWeek,
                $assignedDate,
                substr($chosenHorario['hora_inicio'], 0, 5),
                substr($chosenHorario['hora_fin'], 0, 5),
                $chosenHorario['aula_id'] ?? 'Aula ??' // Ensure key exists
            ];

            $sheet->fromArray($data, NULL, "A{$row}");
            $row++;
        }
    }

    foreach (range('A', 'I') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $fileName = 'rol_examenes_sistemas_generated.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save(__DIR__ . '/' . $fileName);

    echo "SUCCESS: Archivo generado exitosamente en: " . __DIR__ . "\\{$fileName}\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
