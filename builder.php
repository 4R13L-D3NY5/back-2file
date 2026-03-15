<?php

$files = [
    'master_restore_v7.php' => 'Paso 1: Restauración de Asignaturas y Preservación de Nombres',
    'restore_pedagogy.php' => 'Paso 2: Restauración de Pedagogía Base (0%)',
    'restore_cronogramas.php' => 'Paso 3: Restauración de Cronogramas',
    'restore_pedagogy_deep.php' => 'Paso 4: Restauración Pedagógica Profunda (Textos e Indicadores)'
];

$output = "<?php\n";
$output .= "/**\n * RESTAURACIÓN TOTAL UNIFICADA\n";
$output .= " * Este archivo contiene todos los pasos de restauración consolidados.\n */\n\n";

$output .= "require __DIR__ . '/vendor/autoload.php';\n";
$output .= "\$app = require __DIR__ . '/bootstrap/app.php';\n";
$output .= "\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();\n\n";
$output .= "use Illuminate\Support\Facades\DB;\n\n";

$output .= "\$dbCurrent = 'academico';\n";
$output .= "\$dbBackup  = 'academico_backup';\n\n";

$output .= "echo \"\\n=======================================================\\n\";\n";
$output .= "echo \"   INICIANDO RESTAURACIÓN TOTAL DEL SISTEMA SIDOPA\\n\";\n";
$output .= "echo \"=======================================================\\n\\n\";\n\n";

foreach ($files as $file => $title) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    
    // Remove php tags
    $content = preg_replace('/<\?php/', '', $content);
    
    // Remove requires and uses
    $content = preg_replace('/require __DIR__ \. \'\/vendor\/autoload\.php\';/', '', $content);
    $content = preg_replace('/\$app = require __DIR__ \. \'\/bootstrap\/app\.php\';/', '', $content);
    $content = preg_replace('/\$app->make\(\'Illuminate\\\\Contracts\\\\Console\\\\Kernel\'\)->bootstrap\(\);/', '', $content);
    $content = preg_replace('/use Illuminate\\\\Support\\\\Facades\\\\DB;/', '', $content);
    
    // Remove db definitions
    $content = preg_replace('/\$dbCurrent\s*=\s*[\'"]academico[\'"];/', '', $content);
    $content = preg_replace('/\$dbBackup\s*=\s*[\'"]academico_backup[\'"];/', '', $content);
    
    $output .= "echo \"\\n>>> $title <<<\\n\";\n";
    $output .= "call_user_func(function() use (\$dbCurrent, \$dbBackup) {\n";
    $output .= $content;
    $output .= "\n});\n\n";
}

$output .= "echo \"\\n=======================================================\\n\";\n";
$output .= "echo \"   RESTAURACIÓN TOTAL FINALIZADA CON ÉXITO\\n\";\n";
$output .= "echo \"=======================================================\\n\";\n";

file_put_contents('restauracion_total_unificada.php', $output);
echo "Archivo 'restauracion_total_unificada.php' creado con éxito.\n";
