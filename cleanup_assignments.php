<?php
// cleanup_assignments.php
use App\Models\User;
use App\Models\Grupo;

$email = 'juanmamani@unitepc.edu.bo';
echo "Iniciando limpieza de asignaciones para: $email\n";

$u = User::where(['email' => $email])->first();
if (!$u || !$u->docente) {
    echo "Usuario o docente no encontrado.\n";
    exit;
}

$docenteId = $u->docente->id;
echo "Docente ID: $docenteId\n";

// IDs de grupos confirmados como CORRECTOS (Enfermería)
$keepGroupIds = [5432, 5433, 5434, 5435];

// Buscar grupos a desasignar
$groupsToClean = Grupo::where('docente_id', $docenteId)
    ->whereNotIn('id', $keepGroupIds)
    ->get();

$count = $groupsToClean->count();

if ($count === 0) {
    echo "No se encontraron grupos extra para limpiar.\n";
    exit;
}

echo "Se encontraron $count grupos para desasignar (que no son los 4 de enfermería).\n";

foreach ($groupsToClean as $g) {
    echo "  [DESASIGNANDO] Grupo ID: {$g->id} | {$g->nombre} | Asignatura: " . ($g->asignatura->nombre ?? 'N/A') . "\n";
    $g->docente_id = null;
    $g->save();
}

echo "\nLimpieza completada exitosamente.\n";
