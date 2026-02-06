<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Horario;
use App\Models\Grupo;
use Illuminate\Support\Facades\Http;

$gestion = '1-2026';
$carrera = 'carenl'; // Nursing as sample
$sedeId = 1; // Cochabamba

echo "=== DEBUG ID MATCH ($gestion) ===\n";

// 1. Fetch API IDs
echo "Fetching API for $carrera in Sede $sedeId...\n";
$response = Http::get("http://181.188.185.211:9098/api/Grupos/listar/", [
    'gestion' => $gestion,
    'carrera' => $carrera,
    'sede' => $sedeId
]);

if ($response->successful()) {
    $data = $response->json();
    $sample = array_slice($data, 0, 5); // Check first 5

    foreach ($sample as $item) {
        $apiId = $item['idHorario'];
        $docente = $item['docente'];

        echo "\nAPI ID: $apiId (Docente: $docente)\n";

        // 2. Check DB
        $horario = Horario::where('id_horario_api', $apiId)->first();

        if ($horario) {
            echo "   ✅ Found in DB! Group ID: {$horario->grupo_id}\n";
            echo "      Current Docente ID: " . ($horario->grupo->docente_id ?? 'NULL') . "\n";
        } else {
            echo "   ❌ NOT FOUND in DB.\n";
            // Check if maybe the Group exists but Horario ID is null?
            // Try to match by name to see if "Ghost Group" exists
            $grupo = Grupo::where('nombre', $item['grupo'])
                ->where('sede_id', $sedeId)
                ->where('gestion', $gestion)
                ->first();
            if ($grupo) {
                echo "      (However, Group named '{$item['grupo']}' exists with ID {$grupo->id} but has NO API LINK)\n";
            }
        }
    }
} else {
    echo "API Error.\n";
}
