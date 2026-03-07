<?php

use App\Models\Docente;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- INICIANDO REPARACIÓN DE DOCENTES DUPLICADOS ---\n";

DB::beginTransaction();

try {
    // 1. Encontrar Docentes con user_id pero sin CI (o CI vacío)
    $problematicDocentes = Docente::whereNotNull('user_id')
        ->where(function ($q) {
            $q->whereNull('ci')->orWhere('ci', '');
        })
        ->get();

    echo "Procesando " . $problematicDocentes->count() . " registros problemáticos...\n";

    foreach ($problematicDocentes as $docenteConUser) {
        $user = User::find($docenteConUser->user_id);
        if (!$user) {
            echo "[!] Docente ID: {$docenteConUser->id} tiene User ID: {$docenteConUser->user_id} pero el usuario no existe. Omitiendo.\n";
            continue;
        }

        $ciReal = $user->username; // En este sistema, el username del usuario es el CI
        echo "[*] Procesando: {$docenteConUser->nombre_completo} (User: {$ciReal})\n";

        // Buscar el otro registro que tiene el CI pero no el usuario
        $docenteConCI = Docente::where('ci', $ciReal)
            ->where('id', '!=', $docenteConUser->id)
            ->first();

        if ($docenteConCI) {
            echo "    -> Encontrado duplicado con CI: ID {$docenteConCI->id}\n";

            // Mover grupos
            $gruposMovidos = DB::table('grupos')
                ->where('docente_id', $docenteConCI->id)
                ->update(['docente_id' => $docenteConUser->id, 'updated_at' => now()]);
            echo "    -> Grupos movidos: {$gruposMovidos}\n";

            // Mover otros datos relacionados
            $tablasConDocente = ['seguimiento_semanal', 'informe_semanals', 'auditorias'];
            foreach ($tablasConDocente as $tabla) {
                if (Schema::hasTable($tabla)) {
                    $movidos = DB::table($tabla)
                        ->where('docente_id', $docenteConCI->id)
                        ->update(['docente_id' => $docenteConUser->id, 'updated_at' => now()]);
                    echo "    -> Registro en {$tabla} movidos: {$movidos}\n";
                }
            }

            // Eliminar el duplicado sin usuario
            $docenteConCI->delete();
            echo "    -> Registro duplicado eliminado (ID {$docenteConCI->id}).\n";
        }

        // Asegurar que el registro con usuario tenga el CI correcto
        $docenteConUser->ci = $ciReal;
        $docenteConUser->save();
        echo "    -> CI actualizado en registro principal (ID {$docenteConUser->id}).\n";
    }

    DB::commit();
    echo "--- REPARACIÓN COMPLETADA EXITOSAMENTE ---\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "--- !!! ERROR DURANTE LA REPARACIÓN !!! ---\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
