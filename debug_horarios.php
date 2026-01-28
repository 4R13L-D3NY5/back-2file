<?php
require __DIR__ . '/vendor/autoload.php';

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

    // Get Career
    $stmt = $pdo->prepare("SELECT id, nombre FROM carreras WHERE nombre LIKE :name");
    $stmt->execute(['name' => '%SISTEMAS%']);
    $carrera = $stmt->fetch();
    echo "Carrera: " . $carrera['nombre'] . " (ID: " . $carrera['id'] . ")\n\n";

    // Get first 5 subjects
    $sql = "
        SELECT a.id, a.codigo, a.nombre
        FROM asignaturas a
        JOIN asignatura_carrera ac ON a.id = ac.asignatura_id
        WHERE ac.carrera_id = :id
        LIMIT 5
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $carrera['id']]);
    $subjects = $stmt->fetchAll();

    foreach ($subjects as $sub) {
        echo "Validating Subject: {$sub['nombre']} ({$sub['codigo']})\n";

        // Check Groups
        $stmtG = $pdo->prepare("SELECT * FROM grupos WHERE asignatura_id = :aid");
        $stmtG->execute(['aid' => $sub['id']]);
        $groups = $stmtG->fetchAll();

        if (count($groups) === 0) {
            echo "  -> NO GROUPS FOUND!\n";
            continue;
        }

        foreach ($groups as $g) {
            echo "  Group ID: {$g['id']}, Name: {$g['nombre']}, Type: " . ($g['tipo'] ?? 'N/A') . "\n";

            // Check Schedule
            $stmtH = $pdo->prepare("SELECT * FROM horarios WHERE grupo_id = :gid");
            $stmtH->execute(['gid' => $g['id']]);
            $horarios = $stmtH->fetchAll();

            if (count($horarios) === 0) {
                echo "    -> NO SCHEDULE (Horario) FOUND!\n";
            } else {
                foreach ($horarios as $h) {
                    echo "    -> Day: '{$h['dia']}' (Raw Value), Start: {$h['hora_inicio']}\n";
                }
            }
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
