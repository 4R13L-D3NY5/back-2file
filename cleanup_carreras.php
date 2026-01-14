<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Delete the specific "fake" ones I created with short codes
$fakeCodes = ['SIS', 'CIV', 'MED', 'ODO', 'DER', 'COM'];
\App\Models\Carrera::whereIn('codigo', $fakeCodes)->delete();
echo "Deleted fake careers.\n";
