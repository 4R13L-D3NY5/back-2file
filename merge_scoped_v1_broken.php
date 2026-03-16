<?php
/**
 * merge_scoped_v1_broken.php (SIMULADOR DE FALLO)
 * ─────────────────────────────────────────────────────────────
 * Este script simula la v1 que solo borraba o no encontraba
 * los códigos oficiales, dejando a los docentes sin sus materias.
 * ─────────────────────────────────────────────────────────────
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== SIMULANDO FALLO v1 (Borrando asignaciones scoped sin transferir) ===\n";

$scopedAsigs = DB::table('asignaturas')
    ->where(function($q) {
        $q->where('codigo', 'LIKE', '%-%-%')
          ->orWhere('codigo', 'REGEXP', '-[A-Z][A-Z]+$');
    })
    ->whereNull('deleted_at')
    ->get();

foreach ($scopedAsigs as $scoped) {
    echo "Simulando limpieza fallida de: {$scoped->codigo}\n";
    
    DB::beginTransaction();
    try {
        // En la v1 "rota", simplemente borramos la materia scoped y sus grupos
        // sin transferir nada a la oficial. Esto deja el sistema en el estado
        // que causó el problema en el servidor.
        
        $gruposIds = DB::table('grupos')->where('asignatura_id', $scoped->id)->pluck('id');
        
        // Simular pérdida de datos (borrar subordinados)
        DB::table('seguimientos')->whereIn('grupo_id', $gruposIds)->delete();
        DB::table('horarios')->whereIn('grupo_id', $gruposIds)->delete();
        DB::table('cronogramas')->whereIn('grupo_id', $gruposIds)->delete();
        
        // Borrar grupos y asignatura
        DB::table('grupos')->where('asignatura_id', $scoped->id)->delete();
        DB::table('asignaturas')->where('id', $scoped->id)->delete();
        
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        echo "Error simulación: " . $e->getMessage() . "\n";
    }
}

echo "=== Simulación de Limpieza Completada (Estado: FALLIDO) ===\n";
