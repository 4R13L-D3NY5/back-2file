<?php
// restore_assignments.php
use App\Models\Grupo;

$docenteId = 238; // Juan Jose Mamani Via
echo "Restaurando asignaciones para Docente ID: $docenteId\n";

// IDs identificados en los logs previos como "desasignados"
$groupsToRestore = [
    2588,
    2589,
    3355,
    3356,
    3357,
    3358,
    3457,
    3458,
    3459,
    3460,
    3573,
    3574,
    3575,
    3576,
    3577,
    3578
];

$count = 0;
foreach ($groupsToRestore as $id) {
    $g = Grupo::find($id);
    if ($g) {
        $g->docente_id = $docenteId;
        $g->save();
        echo "  [RESTAURADO] Grupo ID: $id | {$g->nombre} | {$g->asignatura->nombre}\n";
        $count++;
    } else {
        echo "  [ERROR] Grupo ID: $id no encontrado.\n";
    }
}

echo "\nSe restauraron $count grupos exitosamente.\n";
