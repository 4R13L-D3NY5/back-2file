<?php
$a = App\Models\Asignatura::where('codigo', 'SIS-326')->first();
if ($a) {
    dump("ID: " . $a->id);
    dump("Codigo: " . $a->codigo);
    dump("Carrera ID (Column): " . $a->carrera_id);
    dump("Carreras Relation Check:");
    dump($a->carreras->toArray());
} else {
    dump("Subject SIS-326 not found.");
}
