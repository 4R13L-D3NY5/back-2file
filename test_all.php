<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user_id = 48; // Try 48, which we saw earlier, or just fetch all
$asignaturas = App\Models\Asignatura::with([
    'unidades.temas.logros.indicadores',
    'unidades.temas.planificacionPersonal',
    'unidades.temas.logros.bancoPreguntas',
    'bibliografias',
    'cronogramas',
    'docentes'
])->get();

foreach ($asignaturas as $a) {
    echo $a->codigo . " - " . $a->nombre . "\n";
    foreach ($a->docentes as $doc) {
        $pct = $a->getIndicadoresDocumentacionPorDocente($doc->user_id)['plan_clase']['porcentaje'];
        if ($pct > 0 && $pct < 100) {
            echo "   -> Docente User " . $doc->user_id . " ($doc->nombre_completo): " . $pct . "%\n";
        }
    }
}
