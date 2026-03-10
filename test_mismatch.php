<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$a = App\Models\Asignatura::with([
    'unidades.temas.logros.indicadores',
    'unidades.temas.planificacionPersonal',
    'unidades.temas.logros.bancoPreguntas'
])->where('codigo', 'SIS-423')->first();

$model = new App\Models\Asignatura();
$rm = new ReflectionMethod($model, 'hasRealContent');
$rm->setAccessible(true);

function hasRC($v) {
    if (is_null($v)) return false;
    $v = strip_tags((string)$v);
    $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $v = str_replace(["&nbsp;", "\xc2\xa0"], " ", $v);
    return trim($v) !== '';
}

$tema = $a->unidades[0]->temas[0];

$fields = [
    'resultado_aprendizaje' => $tema->resultado_aprendizaje,
    'estrategias_metodologicas' => $tema->estrategias_metodologicas,
    'estrategias_aprendizaje' => $tema->estrategias_aprendizaje,
    'estrategias_recursos' => $tema->estrategias_recursos,
    'evaluacion_formativa' => $tema->evaluacion_formativa,
    'evaluacion_sumativa' => $tema->evaluacion_sumativa,
    'contenido_conceptual' => $tema->contenido_conceptual,
    'contenido_procedimental' => $tema->contenido_procedimental,
    'contenido_actitudinal' => $tema->contenido_actitudinal,
];

foreach ($fields as $k => $v) {
    if (is_string($v)) {
        $real = $rm->invoke($model, $v);
        $rc = hasRC($v);
        if ($real !== $rc) {
            echo "Mismatch String [$k]: Real=$real vs RC=$rc. Val=" . json_encode($v) . "\n";
        }
    } else if (is_array($v)) {
        // recursively test array
        array_walk_recursive($v, function($item) use ($rm, $model, $k) {
            $real = $rm->invoke($model, $item);
            $rc = hasRC($item);
            if ($real !== $rc) {
                echo "Mismatch Array Item [$k]: Real=$real vs RC=$rc. Val=" . json_encode($item) . "\n";
            }
        });
    }
}

// Test planificacion
$plan = $tema->planificacionPersonal;
if ($plan) {
    $plan_fields = [
        'estrategias_metodologicas', 'estrategias_aprendizaje', 'estrategias_recursos',
        'evaluacion_formativa', 'evaluacion_sumativa', 'secuencia_didactica'
    ];
    foreach($plan_fields as $pf) {
        $v = $plan->$pf;
        if (is_array($v)) {
            array_walk_recursive($v, function($item) use ($rm, $model, $pf) {
                $real = $rm->invoke($model, $item);
                $rc = hasRC($item);
                if ($real !== $rc) {
                    echo "Mismatch Plan Array Item [$pf]: Real=$real vs RC=$rc. Val=" . json_encode($item) . "\n";
                }
            });
        }
    }
}
