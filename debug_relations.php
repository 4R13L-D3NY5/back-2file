<?php
use App\Models\Grupo;
use App\Models\Cronograma;
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$gid = Grupo::where('asignatura_id', 620)->value('id');
echo "GrupoID: " . ($gid ?? 'NULL') . "\n";

if ($gid) {
    echo "Cronogramas: " . Cronograma::where('grupo_id', $gid)->count() . "\n";
    echo "First Date: " . Cronograma::where('grupo_id', $gid)->min('fecha') . "\n";
}
