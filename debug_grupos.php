<?php
// debug_grupos.php
use App\Models\User;

$email = 'juanmamani@unitepc.edu.bo';
echo "Buscando usuario: $email\n";
$u = User::where(['email' => $email])->first();

if (!$u) {
    echo "¡Usuario no encontrado!\n";
    exit;
}

echo "Usuario ID: " . $u->id . "\n";
echo "Docente ID: " . ($u->docente ? $u->docente->id : 'NULL') . "\n";

if (!$u->docente) {
    echo "¡No tiene registro de docente!\n";
    exit;
}

$u->docente->load(['grupos.sede', 'grupos.asignatura']);

echo "\n--- GRUPOS ASIGNADOS ({$u->docente->grupos->count()}) ---\n";
foreach ($u->docente->grupos as $g) {
    echo "Grupo ID: " . $g->id . " | Nombre: " . $g->nombre . "\n";
    echo "  Gestion: " . ($g->gestion ?? 'N/A') . " | Estado: " . ($g->estado ?? 'N/A') . "\n";

    $carreras = $g->asignatura && $g->asignatura->carreras ? $g->asignatura->carreras->pluck('nombre')->implode(', ') : 'Ninguna';
    echo "  Carreras Asignatura: " . $carreras . "\n";

    echo "  Asignatura: " . ($g->asignatura ? $g->asignatura->nombre . " (Sede ID: " . $g->asignatura->sede_id . ")" : 'NULL') . "\n";
    echo "  Sede Grupo: " . ($g->sede ? $g->sede->nombre . " (ID: " . $g->sede->id . ")" : 'NULL (sede_id: ' . ($g->sede_id ?? 'NULL') . ')') . "\n";
    echo "  Pivot: " . json_encode($g->pivot) . "\n";
    echo "--------------------------\n";
}
