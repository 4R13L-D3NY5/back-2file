<?php
// Dump the raw JSON for the frontend script to process
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$a = App\Models\Asignatura::with([
    'unidades.temas.logros.indicadores',
    'unidades.temas.planificacionPersonal',
    'unidades.temas.logros.bancoPreguntas',
    'bibliografias',
    'cronogramas'
])->where('codigo', 'SIS-423')->first();

if (!$a) {
    echo "Asignatura no encontrada.\n";
    exit;
}

file_put_contents('asignatura_dump.json', json_encode($a));
echo "Dumped to asignatura_dump.json\n";
