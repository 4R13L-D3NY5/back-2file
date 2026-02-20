<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$startDate = \Carbon\Carbon::parse('2026-02-09')->startOfWeek();
$endDate = $startDate->copy()->endOfWeek();

$grupo = \App\Models\Grupo::with(['cronogramas' => function ($cq) use ($startDate, $endDate) {
    echo "Executing eager load constraint for dates ".$startDate->toDateString()." to ".$endDate->toDateString()."\n";
    $cq->whereBetween('fecha', [$startDate->toDateString(), $endDate->toDateString()]);
}])->find(1177);

echo "\nEAGER LOADED CLASSES for 1177:\n";
foreach($grupo->cronogramas as $c) {
    echo "- ID {$c->id} | {$c->fecha}\n";
}
