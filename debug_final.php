<?php
$code = 'OPT-004';
$a = App\Models\Asignatura::where('codigo', $code)->first();
if (!$a) {
    die("$code not found\n");
}
echo "Asignatura: {$a->nombre} (ID: {$a->id})\n";
$pivots = DB::table('asignatura_carrera')->where('asignatura_id', $a->id)->get();
foreach ($pivots as $p) {
    $c = App\Models\Carrera::find($p->carrera_id);
    $s = App\Models\Sede::find($p->sede_id);
    echo "Carrera: {$c->sigla} ({$p->carrera_id}) | Sede: {$s->nombre} | Semestre: {$p->semestre}\n";
}
