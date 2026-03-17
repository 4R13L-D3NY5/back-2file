<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$testCases = [
    ['codigo' => 'ELC-213-COC-CARELE', 'nombre' => 'PROBABILIDAD Y ESTADISTICA', 'plan' => 'N'],
    ['codigo' => 'ENF-115-COC-CARENL', 'nombre' => 'TECNICAS DE ENFERMERIA', 'plan' => 'N']
];

echo "=== TEST DE MATCHING POR SIGLA + SIMILITUD ===\n";

foreach ($testCases as $tc) {
    echo "\nBuscando oficial para: [{$tc['codigo']}] {$tc['nombre']}\n";
    $sigla = substr($tc['codigo'], 0, 3); // 'ELC'
    
    // 1. Candidate strictly by matching code (the old way)
    if (preg_match('/^([A-Z]{2,5}-[0-9]{1,4})(-.+)?/', $tc['codigo'], $m)) {
        $codigoBase = $m[1];
        $exactCodeMatch = DB::table("$dbCurrent.asignaturas")->where('codigo', $codigoBase)->whereNull('deleted_at')->first();
        if ($exactCodeMatch) {
            echo "  Old Method (by Code $codigoBase) -> Encontró: [{$exactCodeMatch->codigo}] {$exactCodeMatch->nombre} (Plan: {$exactCodeMatch->plan_estudios})\n";
        }
    }
    
    // 2. Candidate by sigla + name similarity (The new way)
    $candidatos = DB::table("$dbCurrent.asignaturas")
        ->where('codigo', 'LIKE', "$sigla-%")
        ->whereNull('deleted_at')
        ->get();
        
    $bestScore = -1;
    $oficialLexical = null;
    
    foreach ($candidatos as $cand) {
        similar_text(strtoupper($tc['nombre']), strtoupper($cand->nombre), $percent);
        // Penalize if plan doesn't match? Maybe slightly
        if (($cand->plan_estudios ?: 'N') == $tc['plan']) $percent += 5;
        
        if ($percent > $bestScore) {
            $bestScore = $percent;
            $oficialLexical = $cand;
        }
    }
    
    if ($oficialLexical) {
        echo "  New Method (by Sigla $sigla + Name) -> Mejor (\% {$bestScore}): [{$oficialLexical->codigo}] {$oficialLexical->nombre} (Plan: {$oficialLexical->plan_estudios})\n";
    }
}
