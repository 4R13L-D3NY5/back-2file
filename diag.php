<?php
$a = App\Models\Asignatura::where('codigo', 'CPEC18')->with(['carrera.sede'])->first();
if (!$a) {
    echo "Asignatura not found\n";
    exit;
}

echo "CHECKING RELATION...\n";
if ($a->carrera) {
    echo "Carrera matches. Sede ID: " . $a->carrera->sede_id . "\n";
    if ($a->carrera->sede) {
        echo "RELATION OK: Sede Name is " . $a->carrera->sede->nombre . "\n";
    } else {
        echo "RELATION FAIL: \$a->carrera->sede is NULL\n";
    }
} else {
    echo "Carrera relation missing\n";
}
