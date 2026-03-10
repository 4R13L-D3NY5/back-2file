<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$user = \App\Models\User::with('docente.asignaturas')->where('nombre','like','%HAROLD%')->first();
if ($user && $user->docente) {
    $asig = clone $user->docente->asignaturas()->where('sigla','SIS-423')->first();
    if ($asig) {
        $asig->load('unidades.temas.logros', 'unidades.temas.planificacionPersonal');
        $progreso = $asig->calcularProgresoPorCriterios($user->id, false);
        echo "Calculated progreso:\n";
        print_r($progreso['plan_clase']);
        
        $temas = $asig->unidades->flatMap->temas;
        foreach($temas as $tema) {
            echo "Tema ID: " . $tema->id . "\n";
            echo "Logros loaded: " . ($tema->relationLoaded('logros') ? 'Yes' : 'No') . "\n";
            $logros = $tema->logros ?? collect();
            echo "Logros count: " . $logros->count() . "\n";
            
            $plan = clone $tema->planificacionesPersonales->where('user_id', $user->id)->first();
            if (!$plan) {
                $plan = $tema->planificacionPersonal && $tema->planificacionPersonal->user_id == $user->id ? $tema->planificacionPersonal : null;
            }
            echo "Plan loaded: " . ($plan ? 'Yes' : 'No') . "\n";
        }
    }
}
