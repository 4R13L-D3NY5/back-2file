<?php
require __DIR__ . '/vendor/autoload.php';

$url = "http://181.188.185.211:9098/api/Grupos/listar/?gestion=1-2026&carrera=carele&sede=1";
$json = file_get_contents($url);
$data = json_decode($json, true);

echo "Buscando ELC-213 y materias de Tania (CI 4534773) en la API (carele, sede 1):\n";

if ($data && isset($data['data'])) {
    foreach ($data['data'] as $item) {
        $sigla = $item['sigla'] ?? '';
        $docente = $item['docente'] ?? '';
        
        if (strpos($sigla, 'ELC-213') !== false || strpos($docente, 'TANCARA') !== false || strpos($docente, '4534773') !== false) {
            echo "- Sigla: $sigla, Asignatura: {$item['asignatura']}, Docente: $docente\n";
        }
    }
} else {
    echo "No se pudo obtener datos de la API.\n";
}

// Check ENF-115
$urlEnf = "http://181.188.185.211:9098/api/Grupos/listar/?gestion=1-2026&carrera=carenl&sede=1";
$jsonEnf = file_get_contents($urlEnf);
$dataEnf = json_decode($jsonEnf, true);

echo "\nBuscando ENF-115 en la API (carenl, sede 1):\n";
if ($dataEnf && isset($dataEnf['data'])) {
    foreach ($dataEnf['data'] as $item) {
        $sigla = $item['sigla'] ?? '';
        if (strpos($sigla, 'ENF-115') !== false) {
            echo "- Sigla: $sigla, Asignatura: {$item['asignatura']}, Docente: {$item['docente']}\n";
        }
    }
}
