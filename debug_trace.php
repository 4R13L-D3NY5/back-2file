<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Asignatura;

$id = 620;
echo "--- TRACE DEBUG ($id) ---\n";

$a = Asignatura::find($id);
if (!$a) {
    die("Asignatura not found\n");
}
echo "Found Asignatura: {$a->id}\n";

echo "[1] Testing Docentes load...\n";
try {
    $a->load('docentes');
    echo "    OK. Count: " . $a->docentes->count() . "\n";
} catch (\Exception $e) {
    echo "    FAIL: " . $e->getMessage() . "\n";
}

echo "[2] Testing Carreras load...\n";
try {
    $a->load('carreras'); // Just carreras first
    echo "    OK. Count: " . $a->carreras->count() . "\n";
} catch (\Exception $e) {
    echo "    FAIL: " . $e->getMessage() . "\n";
}

echo "[3] Testing Carreras.Sede load...\n";
try {
    $a->load('carreras.sede');
    echo "    OK.\n";
} catch (\Exception $e) {
    echo "    FAIL: " . $e->getMessage() . "\n";
}

echo "[4] Testing Bibliografias load...\n";
try {
    $a->load('bibliografias');
    echo "    OK.\n";
} catch (\Exception $e) {
    echo "    FAIL: " . $e->getMessage() . "\n";
}

echo "[5] Testing Unidades load...\n";
try {
    $a->load('unidades');
    echo "    OK.\n";
} catch (\Exception $e) {
    echo "    FAIL: " . $e->getMessage() . "\n";
}
