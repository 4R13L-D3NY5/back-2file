<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Grupo;
use Illuminate\Support\Facades\DB;

// Query to see distinct types and some examples
$types = Grupo::select('tipo')->distinct()->get()->toArray();
echo "Distinct Types: " . json_encode($types) . "\n\n";

// Look for groups with numeric names
$numericGroups = Grupo::whereRaw('nombre REGEXP "^[0-9]+$"')->limit(10)->get();
echo "--- Numeric Named Groups ---\n";
foreach ($numericGroups as $g) {
    echo "ID: {$g->id}, Name: '{$g->nombre}', Type: '{$g->tipo}'\n";
}

// Look for 'TEORIA' type groups
$teoriaGroups = Grupo::where('tipo', 'LIKE', '%TEOR%')->limit(10)->get();
echo "\n--- Theory Groups ---\n";
foreach ($teoriaGroups as $g) {
    echo "ID: {$g->id}, Name: '{$g->nombre}', Type: '{$g->tipo}'\n";
}
