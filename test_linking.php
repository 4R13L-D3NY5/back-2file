<?php

use App\Models\Asignatura;
use Illuminate\Support\Str;

// Limpiar tokens anteriores
Asignatura::whereNotNull('comun_token')->update(['comun_token' => null]);

// Crear 3 asignaturas mock para la prueba (o usar existentes)
// Asumiremos que existen IDs reales o crearemos temporales
// Mejor usar existentes para no ensuciar DB, pero necesitamos 3 distintas.
// Buscaremos 3 asignaturas cualquiera.
$subjects = Asignatura::limit(3)->get();

if ($subjects->count() < 3) {
    echo "No hay suficientes asignaturas para probar.\n";
    exit;
}

$s1 = $subjects[0];
$s2 = $subjects[1];
$s3 = $subjects[2];

echo "Testing Linking: \n";
echo "1. {$s1->nombre} (ID: {$s1->id})\n";
echo "2. {$s2->nombre} (ID: {$s2->id})\n";
echo "3. {$s3->nombre} (ID: {$s3->id})\n";

// 1. Link S1 and S2
echo "\nLinking S1 and S2...\n";
app(\App\Http\Controllers\MateriaComunController::class)->link(new \Illuminate\Http\Request([
    'asignatura_id' => $s1->id,
    'target_asignatura_id' => $s2->id
]));

$s1->refresh();
$s2->refresh();
echo "S1 Token: {$s1->comun_token}\n";
echo "S2 Token: {$s2->comun_token}\n";

if ($s1->comun_token && $s1->comun_token === $s2->comun_token) {
    echo "SUCCESS: S1 and S2 Linked.\n";
} else {
    echo "FAIL: S1 and S2 not linked correctly.\n";
}

// 2. Link S1 and S3
echo "\nLinking S1 and S3...\n";
app(\App\Http\Controllers\MateriaComunController::class)->link(new \Illuminate\Http\Request([
    'asignatura_id' => $s1->id,
    'target_asignatura_id' => $s3->id
]));

$s1->refresh();
$s2->refresh();
$s3->refresh();
echo "S1 Token: {$s1->comun_token}\n";
echo "S2 Token: {$s2->comun_token}\n";
echo "S3 Token: {$s3->comun_token}\n";

if ($s1->comun_token === $s3->comun_token && $s2->comun_token === $s3->comun_token) {
    echo "SUCCESS: S1, S2, and S3 Linked (Group of 3).\n";
} else {
    echo "FAIL: Tokens do not match for all 3.\n";
}

// Clean up
$s1->comun_token = null;
$s1->save();
$s2->comun_token = null;
$s2->save();
$s3->comun_token = null;
$s3->save();
echo "\nCleaned up tokens.\n";
