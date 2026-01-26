<?php

use App\Models\Asignatura;

$as = Asignatura::where('nombre', 'like', '%CALCULO II%')->with('carreras')->get();
foreach ($as as $a) {
    echo "ID: {$a->id} | {$a->nombre} | CARRERA: " . ($a->carreras->first()?->nombre ?? 'N/A') . " | TOKEN: {$a->comun_token}\n";
}
