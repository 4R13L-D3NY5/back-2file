<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Grupo;
use Illuminate\Http\Request;
use App\Http\Controllers\ReporteController;

$controller = new ReporteController();

echo "--- Verificando generateWeeklyReport (Sede 1, Carrera 34, 2026-02-09) ---\n";
$request = new Request([
    'sede_id' => 1,
    'carrera_id' => 34,
    'fecha_inicio' => '2026-02-09'
]);

$response = $controller->generateWeeklyReport($request);
$data = json_decode($response->getContent(), true);

$found = 0;
foreach ($data as $item) {
    if (count($item['criterios']) > 0) {
        echo "  - Grupo: {$item['grupo_nombre']} | Asignatura: {$item['asignatura']} | Sesiones: " . count($item['criterios']) . " | Alerta: {$item['alerta']}\n";
        $found++;
    }
}
echo "Total grupos con sesiones detectadas: $found\n";

echo "\n--- Verificando getWeeklyReportDraft (Grupo 13235 - Taller de Redes) ---\n";
$draftRequest = new Request([
    'grupo_id' => 13235,
    'fecha_inicio' => '2026-02-09'
]);
$draftResponse = $controller->getWeeklyReportDraft($draftRequest);
$draftData = json_decode($draftResponse->getContent(), true);

if (isset($draftData['report'])) {
    echo "Reporte Borrador generado para: " . $draftData['report']['asignatura_nombre'] . "\n";
    echo "Docente: " . $draftData['report']['docente_nombre'] . "\n";
    echo "Cumplimiento: " . $draftData['report']['cumplimiento_porcentaje'] . "%\n";
    echo "Escala Alerta: " . $draftData['report']['escala_alerta'] . "\n";
    echo "Detalle de Sesiones (" . count($draftData['report']['sesiones_detalle'] ?? []) . "):\n";
    foreach ($draftData['report']['sesiones_detalle'] as $sd) {
        echo "  * Fecha: {$sd['fecha']} | Tipo: {$sd['tipo']} | Estado: {$sd['estado']} | Tema: {$sd['tema']}\n";
    }
} else {
    echo "Error: No se pudo generar el borrador.\n";
}
