<?php
// verify_seeder_v4.php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$a = App\Models\Asignatura::where('codigo', 'SIS-325')->first();
if (!$a) {
    echo "Asignatura SIS-325 not found.\n";
    exit(1);
}

// Check first theme content
$tema1 = App\Models\Tema::where('unidad_id', $a->unidades->first()->id)->where('orden', 1)->first();
echo "Tema 1 Conceptuales Count: " . count($tema1->contenido_conceptual) . "\n";
echo "Tema 1 Items Count: " . (is_array($tema1->contenido_items) ? count($tema1->contenido_items) : 0) . "\n";

$c = App\Models\Cronograma::where('asignatura_id', $a->id)->get();
echo "Total Cronograma Sessions: " . $c->count() . "\n";

if ($c->count() > 0) {
    $first = $c->first();
    echo "First Session Criteria Type: " . gettype($first->criterios_desempeno) . "\n";
    echo "First Session Criteria Count: " . (is_array($first->criterios_desempeno) ? count($first->criterios_desempeno) : 0) . "\n";
    echo "First Criterion: " . (is_array($first->criterios_desempeno) && count($first->criterios_desempeno) > 0 ? $first->criterios_desempeno[0] : 'N/A') . "\n";
    
    echo "First Session Instruments Type: " . gettype($first->instrumentos_evaluacion) . "\n";
    echo "First Session Instruments Count: " . (is_array($first->instrumentos_evaluacion) ? count($first->instrumentos_evaluacion) : 0) . "\n";
    
    $items = $first->contenido_items_seleccionados;
    echo "First Session Selected Items Count: " . (is_array($items) ? count($items) : 0) . "\n";
    if (is_array($items) && count($items) > 0) {
        echo "First Selected Item: " . $items[0] . "\n";
    }
}
