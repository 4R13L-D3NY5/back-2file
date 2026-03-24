<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    $request = \Illuminate\Http\Request::capture()
);

$registros = \App\Models\GeneracionManual::latest()->take(5)->get(['id', 'archivo_examen', 'archivo_patron_pdf', 'archivos_patron_xlsx']);

$results = [];
foreach ($registros as $row) {
    if (!$row->archivo_examen) continue;
    $path = storage_path('app/public/examenes/' . $row->archivo_examen);
    $exists = file_exists($path);
    $results[] = [
        'id' => $row->id,
        'archivo_examen' => $row->archivo_examen,
        'path' => $path,
        'exists' => $exists,
    ];
}

header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);
