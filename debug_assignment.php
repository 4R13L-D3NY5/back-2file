<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Asignatura;
use App\Models\Grupo;
use App\Models\Docente;
use App\Models\User;

$sigla = 'SON-115';
$grupoNombre = '1';
$sedeId = 1; // Cochabamba
$gestion = '1-2026'; // According to screenshot

echo "=== DEBUG ASSIGNMENT ===\n";

// 1. Check Asignatura
$asignatura = Asignatura::where('codigo', $sigla)->first();
if ($asignatura) {
    echo "✅ Asignatura Found: {$asignatura->nombre} (ID: {$asignatura->id}, Codigo: {$asignatura->codigo})\n";
} else {
    echo "❌ Asignatura NOT Found with codigo: $sigla\n";

    // Try fuzzy search
    $fuzzy = Asignatura::where('codigo', 'like', "%$sigla%")->get();
    if ($fuzzy->count() > 0) {
        echo "   Found similar: " . $fuzzy->pluck('codigo')->implode(', ') . "\n";
    }
}

// 2. Check Grupo
if ($asignatura) {
    $grupo = Grupo::where('sede_id', $sedeId)
        ->where('asignatura_id', $asignatura->id)
        ->where('nombre', $grupoNombre)
        ->where('gestion', $gestion)
        ->first();

    if ($grupo) {
        echo "✅ Grupo Found: ID {$grupo->id}, DocenteID: {$grupo->docente_id}\n";
    } else {
        echo "❌ Grupo NOT Found:\n";
        echo "   Sede: $sedeId\n";
        echo "   Asignatura: {$asignatura->id}\n";
        echo "   Nombre: '$grupoNombre'\n";
        echo "   Gestion: '$gestion'\n";

        // List groups for this asignatura
        $grupos = Grupo::where('asignatura_id', $asignatura->id)->get();
        echo "   Available Groups for this subject: " . $grupos->count() . "\n";
        foreach ($grupos as $g) {
            echo "   - ID: {$g->id}, Nombre: '{$g->nombre}', Sede: {$g->sede_id}, Gestion: '{$g->gestion}'\n";
        }
    }
}

// 3. Check Docente
$ci = '4504898'; // from screenshot
$user = User::where('ci', $ci)->first();
if ($user) {
    echo "✅ User Found: {$user->nombre} {$user->apellido}\n";
    $docente = Docente::where('user_id', $user->id)->first();
    if ($docente) {
        echo "✅ Docente Found: ID {$docente->id}\n";
    } else {
        echo "❌ Docente NOT Found for user ID {$user->id}\n";
    }
} else {
    echo "❌ User NOT Found with CI: $ci\n";
}
