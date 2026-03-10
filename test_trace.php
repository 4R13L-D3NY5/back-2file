<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

function hasRC($v) {
    if (is_null($v)) return false;
    $v = strip_tags((string)$v);
    // Decode HTML entities
    $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Replace non-breaking spaces
    $v = str_replace(["&nbsp;", "\xc2\xa0"], " ", $v);
    return trim($v) !== '';
}

$a = App\Models\Asignatura::with([
    'unidades.temas.logros.indicadores',
    'unidades.temas.planificacionPersonal',
    'unidades.temas.logros.bancoPreguntas',
    'bibliografias',
    'cronogramas'
])->where('codigo', 'SIS-423')->first();

$tema = $a->unidades[0]->temas[0];
$plan = $tema->planificacionPersonal;

$pResultados = 0; $totalCamposRes = 3; $camposLlenosRes = 0;
if (hasRC($tema->resultado_aprendizaje ?? '')) $camposLlenosRes++;
$logros = $tema->logros ?? collect();
if ($logros->count() > 0) {
    if ($logros->some(fn($l) => hasRC($l->descripcion ?? ''))) $camposLlenosRes++;
    if ($logros->some(fn($l) => clone $l && $l->relationLoaded('indicadores') && $l->indicadores && $l->indicadores->some(fn($i) => hasRC($i->descripcion ?? '')))) $camposLlenosRes++;
    
    foreach ($logros->slice(1) as $l) {
        $totalCamposRes++;
        if (hasRC($l->descripcion ?? '')) $camposLlenosRes++;
    }
    
    foreach ($logros as $l) {
        $indicadores = $l->relationLoaded('indicadores') && $l->indicadores ? $l->indicadores : collect();
        if ($indicadores->count() > 1) {
            foreach ($indicadores->slice(1) as $ind) {
                $totalCamposRes++;
                if (hasRC($ind->descripcion ?? '')) $camposLlenosRes++;
            }
        }
    }
}
$pResultados = $totalCamposRes > 0 ? round(($camposLlenosRes / $totalCamposRes) * 100) : 0;
echo "Res: $pResultados ($camposLlenosRes/$totalCamposRes)\n";

$pContenidos = 0;
$contenido_items = $tema->contenido_items ?? [];
$tema_c = is_string($tema->contenido_conceptual) ? json_decode($tema->contenido_conceptual, true) : $tema->contenido_conceptual;
$tema_p = is_string($tema->contenido_procedimental) ? json_decode($tema->contenido_procedimental, true) : $tema->contenido_procedimental;
$tema_a = is_string($tema->contenido_actitudinal) ? json_decode($tema->contenido_actitudinal, true) : $tema->contenido_actitudinal;
$conceptual = $tema_c ?? (is_array($contenido_items) ? ($contenido_items['conceptual'] ?? []) : []);
$procedimental = $tema_p ?? (is_array($contenido_items) ? ($contenido_items['procedimental'] ?? []) : []);
$actitudinal = $tema_a ?? (is_array($contenido_items) ? ($contenido_items['actitudinal'] ?? []) : []);

if (!is_array($conceptual)) $conceptual = [$conceptual];
if (!is_array($procedimental)) $procedimental = [$procedimental];
if (!is_array($actitudinal)) $actitudinal = [$actitudinal];

$totalCamposCont = 3; $camposLlenosCont = 0;
if (count($conceptual) > 0 && count(array_filter($conceptual, fn($v) => hasRC($v))) > 0) $camposLlenosCont++;
if (count($conceptual) > 1) foreach (array_slice($conceptual, 1) as $item) { $totalCamposCont++; if (hasRC($item)) $camposLlenosCont++; }
if (count($procedimental) > 0 && count(array_filter($procedimental, fn($v) => hasRC($v))) > 0) $camposLlenosCont++;
if (count($procedimental) > 1) foreach (array_slice($procedimental, 1) as $item) { $totalCamposCont++; if (hasRC($item)) $camposLlenosCont++; }
if (count($actitudinal) > 0 && count(array_filter($actitudinal, fn($v) => hasRC($v))) > 0) $camposLlenosCont++;
if (count($actitudinal) > 1) foreach (array_slice($actitudinal, 1) as $item) { $totalCamposCont++; if (hasRC($item)) $camposLlenosCont++; }
if ($camposLlenosCont === 0 && (hasRC($tema->descripcion ?? '') || hasRC(!is_array($contenido_items) ? $contenido_items : ''))) { $camposLlenosCont = $totalCamposCont; }
$pContenidos = $totalCamposCont > 0 ? round(($camposLlenosCont / $totalCamposCont) * 100) : 0;
echo "Cont: $pContenidos ($camposLlenosCont/$totalCamposCont)\n";


$met = ($plan && hasRC($plan->estrategias_metodologicas)) ? $plan->estrategias_metodologicas : $tema->estrategias_metodologicas;
$apr = ($plan && hasRC($plan->estrategias_aprendizaje)) ? $plan->estrategias_aprendizaje : $tema->estrategias_aprendizaje;
$tema_r = is_string($tema->estrategias_recursos) ? json_decode($tema->estrategias_recursos, true) : $tema->estrategias_recursos;
$rec = ($plan && !empty($plan->estrategias_recursos)) ? $plan->estrategias_recursos : ($tema_r ?? []);
if (!is_array($rec)) $rec = [$rec];

$totalCamposEst = 3; $camposLlenosEst = 0;
if (hasRC($met)) $camposLlenosEst++;
if (hasRC($apr)) $camposLlenosEst++;
if (count($rec) > 0 && count(array_filter($rec, fn($v) => hasRC($v))) > 0) $camposLlenosEst++;
if (count($rec) > 1) foreach (array_slice($rec, 1) as $r) { $totalCamposEst++; if (hasRC($r)) $camposLlenosEst++; }
$pEstrategias = $totalCamposEst > 0 ? round(($camposLlenosEst / $totalCamposEst) * 100) : 0;
echo "Est: $pEstrategias ($camposLlenosEst/$totalCamposEst)\n";

$tema_ef = is_string($tema->evaluacion_formativa) ? json_decode($tema->evaluacion_formativa, true) : $tema->evaluacion_formativa;
$tema_es = is_string($tema->evaluacion_sumativa) ? json_decode($tema->evaluacion_sumativa, true) : $tema->evaluacion_sumativa;
$ef = ($plan && !empty($plan->evaluacion_formativa)) ? $plan->evaluacion_formativa : ($tema_ef ?? []);
$es = ($plan && !empty($plan->evaluacion_sumativa)) ? $plan->evaluacion_sumativa : ($tema_es ?? []);

$totalCamposEval = 6; $camposLlenosEval = 0;
$hasLF = fn($v) => (is_array($v) && count(array_filter((array)$v, fn($i) => hasRC($i))) > 0) || (is_string($v) && hasRC($v));
if (is_array($ef)) {
    if ($hasLF($ef['actividades'] ?? [])) $camposLlenosEval++;
    if ($hasLF($ef['instrumentos'] ?? [])) $camposLlenosEval++;
    if ($hasLF($ef['evidencias'] ?? [])) $camposLlenosEval++;
} elseif (hasRC($ef)) { $camposLlenosEval+=3; }
if (is_array($es)) {
    if ($hasLF($es['actividades'] ?? [])) $camposLlenosEval++;
    if ($hasLF($es['instrumentos'] ?? [])) $camposLlenosEval++;
    if ($hasLF($es['evidencias'] ?? [])) $camposLlenosEval++;
} elseif (hasRC($es)) { $camposLlenosEval+=3; }
$pEvaluacion = $totalCamposEval > 0 ? round(($camposLlenosEval / $totalCamposEval) * 100) : 0;
echo "Eval: $pEvaluacion ($camposLlenosEval/$totalCamposEval)\n";

$tema_s = $tema->relationLoaded('secuencias') ? $tema->secuencias->toArray() : [];
$sec = ($plan && !empty($plan->secuencia_didactica)) ? $plan->secuencia_didactica : $tema_s;
$totalCamposSec = 3; $camposLlenosSec = 0;
if (is_array($sec) && count($sec) > 0) {
    foreach ($sec as $m) if (hasRC($m['actividad'] ?? '')) $camposLlenosSec++;
    if (count($sec) > 3) $totalCamposSec = count($sec);
    $pSecuencia = min(100, round(($camposLlenosSec / $totalCamposSec) * 100));
} elseif (hasRC($sec)) { $pSecuencia = 100; }
echo "Sec: $pSecuencia ($camposLlenosSec/$totalCamposSec)\n";
echo "Total: " . round( ($pResultados+$pContenidos+$pEstrategias+$pEvaluacion+$pSecuencia)/5 ) . "\n";
