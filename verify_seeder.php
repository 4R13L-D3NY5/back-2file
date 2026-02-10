<?php
// verify_seeder.php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$a = App\Models\Asignatura::where('codigo', 'SIS-325')->first();
if (!$a) {
    echo "Asignatura SIS-325 not found.\n";
    exit(1);
}

$c = App\Models\Cronograma::where('asignatura_id', $a->id)->get();
echo "Total Cronograma Sessions: " . $c->count() . "\n";

if ($c->count() > 0) {
    $first = $c->first();
    echo "First Session Criteria: " . $first->criterios_desempeno . "\n";
    echo "First Session Instruments: " . $first->instrumentos_evaluacion . "\n";
    
    // Check if any session has empty criteria/instruments (except exams perhaps)
    $missing = $c->filter(function($s) {
        return empty($s->criterios_desempeno) || empty($s->instrumentos_evaluacion);
    });
    
    echo "Sessions with missing fields: " . $missing->count() . "\n";
}
