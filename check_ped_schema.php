<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$tabs = ['unidades', 'evaluaciones'];
foreach ($tabs as $t) {
    echo "\n=== $t ===\n";
    $cols = DB::select("SHOW COLUMNS FROM academico.{$t}");
    foreach ($cols as $c) echo "{$c->Field}\n";
}
