<?php
$filepath = 'f:/SISTEMA ACADEMICO/back-2file/app/Models/Asignatura.php';
$lines = file($filepath);

// We will momentarily modify the method to echo what it computes
$start = -1;
for ($i = 0; $i < count($lines); $i++) {
    if (strpos($lines[$i], 'public function calcularProgresoTemaBase(') !== false) {
        $start = $i;
        break;
    }
}

if ($start !== -1) {
    // Find the return statement
    for ($j = $start; $j < count($lines); $j++) {
        if (strpos($lines[$j], 'return (int) round(($pResultados') !== false) {
            $lines[$j] = "        echo \"pRes:\$pResultados, pCont:\$pContenidos, pEst:\$pEstrategias, pEval:\$pEvaluacion, pSec:\$pSecuencia\\n\";\n" . $lines[$j];
            break;
        }
    }
    file_put_contents($filepath, implode("", $lines));
    echo "Modified Asignatura.php temporarily.\n";
}
