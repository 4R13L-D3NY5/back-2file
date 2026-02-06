<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Carrera;

$carreras = Carrera::all(['id', 'nombre', 'codigo', 'sigla']);
file_put_contents('carreras_dump.json', json_encode($carreras, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Dumped " . $carreras->count() . " careers to carreras_dump.json\n";
