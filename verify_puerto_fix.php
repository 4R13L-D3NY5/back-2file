<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Grupo;
use App\Models\Asignatura;
use App\Models\Sede;

$sedeId = 8; // Puerto Quijarro
$gestion = '1-2026';

echo "=== VERIFY PUERTO QUIJARRO FIX ===\n";

// 1. Check for EXPECTED Correct Assignment (ENF-114-PUE-CARENL)
// Find the ID for this specific subject
$correctSubject = Asignatura::where('codigo', 'ENF-114-PUE-CARENL')->first();

if ($correctSubject) {
    echo "✅ [CORRECT] Subject Found: {$correctSubject->codigo} (ID: {$correctSubject->id})\n";
    $grupo = Grupo::where('sede_id', $sedeId)
        ->where('asignatura_id', $correctSubject->id)
        ->where('gestion', $gestion)
        ->first();

    if ($grupo && $grupo->docente_id) {
        echo "   ✅ Group Exists and has Docente Assigned (ID: {$grupo->docente_id})\n";
    } elseif ($grupo) {
        echo "   ⚠️ Group Exists but NO Docente Assigned.\n";
    } else {
        echo "   ❌ Group NOT Found for correct subject.\n";
    }
} else {
    echo "❌ [CORRECT] Subject ENF-114-PUE-CARENL NOT FOUND in DB.\n";
}

echo "\n-------------------------------------------------\n";

// 2. Check for INCORRECT Assignment (Generic ENF-114) in Puerto Quijarro
$genericSubject = Asignatura::where('codigo', 'ENF-114')->first();

if ($genericSubject) {
    echo "⚠️ [GENERIC] Subject Found: {$genericSubject->codigo} (ID: {$genericSubject->id})\n";
    $grupo = Grupo::where('sede_id', $sedeId)
        ->where('asignatura_id', $genericSubject->id)
        ->where('gestion', $gestion)
        ->first();

    if ($grupo) {
        echo "   ⚠️ DUPLICATE/WRONG Group Found for Puerto Quijarro using Generic Subject!\n";
        echo "   Group ID: {$grupo->id}\n";
        echo "   Docente ID: {$grupo->docente_id}\n";
        echo "   Suggestion: This group should likely be deleted.\n";
    } else {
        echo "   ✅ No incorrect group found for generic subject.\n";
    }
}
