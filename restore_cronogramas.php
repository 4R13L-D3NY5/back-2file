<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dbCurrent = 'academico';
$dbBackup  = 'academico_backup';

echo "=== RESTAURANDO CRONOGRAMAS FALTANTES (FUERZA BRUTA) ===\n";

$recreatedAsigs = DB::table("$dbCurrent.asignaturas")
    ->where('created_at', '>=', now()->subHours(10))
    ->get();

$totalRestaurados = 0;
$errores = 0;

foreach ($recreatedAsigs as $asigC) {
    // 1. Encontrar equivalente en backup
    $asigB = DB::table("$dbBackup.asignaturas")
        ->where('nombre', $asigC->nombre)
        ->where('plan_estudios', $asigC->plan_estudios)
        ->first();
        
    if ($asigB) {
        // Encontrar cronogramas de esta asignatura en backup
        $cronosB = DB::table("$dbBackup.cronogramas")->where('asignatura_id', $asigB->id)->get();
        if ($cronosB->count() > 0) {
            
            // Buscar GRUPOS en Current para esta Asignatura
            $gruposCurrent = DB::table("$dbCurrent.grupos")->where('asignatura_id', $asigC->id)->get();
            
            if ($gruposCurrent->count() > 0) {
                // Hay grupos! Mapeamos cronogramas
                foreach ($cronosB as $cb) {
                    // Tratar de asignar al grupo correcto usando el nombre del grupo del backup
                    $gbOriginal = DB::table("$dbBackup.grupos")->where('id', $cb->grupo_id)->first();
                    $grupoDestino = null;
                    if ($gbOriginal) {
                        $grupoDestino = $gruposCurrent->firstWhere('nombre', $gbOriginal->nombre) ?? $gruposCurrent->first();
                    } else {
                        $grupoDestino = $gruposCurrent->first();
                    }
                    
                    if ($grupoDestino) {
                        $arr = (array)$cb; unset($arr['id']);
                        $arr['grupo_id'] = $grupoDestino->id;
                        $arr['asignatura_id'] = $asigC->id;

                        // Check existence
                        $exists = DB::table("$dbCurrent.cronogramas")
                            ->where('grupo_id', $grupoDestino->id)
                            ->where('numero_sesion', $arr['numero_sesion'])
                            ->where('fecha', $arr['fecha'])
                            ->exists();

                        if (!$exists) {
                            try {
                                DB::table("$dbCurrent.cronogramas")->insert($arr);
                                $totalRestaurados++;
                            } catch (\Exception $e) {
                                echo "Error insertando crono Asig {$asigC->codigo}: " . $e->getMessage() . "\n";
                                $errores++;
                            }
                        }
                    }
                }
            } else {
                echo "Asignatura {$asigC->codigo} ({$asigC->nombre}) tiene cronogramas en backup pero NO TIENE GRUPOS en current.\n";
            }
        }
    }
}

echo "Total cronogramas restaurados en fuerza bruta: $totalRestaurados\n";
echo "Errores: $errores\n";
