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

echo "=== CLEANUP DUPLICATES PUERTO QUIJARRO ===\n";

// 1. Generic Subject (The Wrong One for Puerto)
$genericSubject = Asignatura::where('codigo', 'ENF-114')->first();

// 2. Specific Subject (The Correct One)
$correctSubject = Asignatura::where('codigo', 'ENF-114-PUE-CARENL')->first();

if ($genericSubject && $correctSubject) {
    // Check if Correct Group Exists
    $correctGrupo = Grupo::where('sede_id', $sedeId)
        ->where('asignatura_id', $correctSubject->id)
        ->where('gestion', $gestion)
        ->first();

    // Check if Duplicate Group Exists
    $duplicateGrupo = Grupo::where('sede_id', $sedeId)
        ->where('asignatura_id', $genericSubject->id)
        ->where('gestion', $gestion)
        ->first();

    if ($correctGrupo && $duplicateGrupo) {
        echo "✅ Found Match:\n";
        echo "   - Correct Group ID: {$correctGrupo->id} (Subject: {$correctSubject->codigo})\n";
        echo "   - Duplicate Group ID: {$duplicateGrupo->id} (Subject: {$genericSubject->codigo})\n";

        // DELETE THE DUPLICATE
        $duplicateGrupo->delete();
        echo "🗑️ DELETED Duplicate Group ID: {$duplicateGrupo->id}\n";
    } else {
        echo "⚠️ Match condition not met:\n";
        echo "   - Correct Group Exists? " . ($correctGrupo ? 'YES' : 'NO') . "\n";
        echo "   - Duplicate Group Exists? " . ($duplicateGrupo ? 'YES' : 'NO') . "\n";
    }
} else {
    echo "❌ One of the subjects was not found.\n";
}
