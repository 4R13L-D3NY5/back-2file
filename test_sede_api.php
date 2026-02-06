<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

function testSede($val, $label)
{
    echo "Testing Sede: $label ($val)...\n";
    try {
        $response = Http::timeout(10)->get("http://181.188.185.211:9098/api/Grupos/listar/", [
            'gestion' => '1-2026',
            'carrera' => 'carmed', // Use verifyable career
            'sede' => $val
        ]);

        if ($response->successful()) {
            $count = count($response->json());
            echo "   ✅ Success! Count: $count\n";
        } else {
            echo "   ⚠️ Failed. Status: " . $response->status() . "\n";
        }
    } catch (\Exception $e) {
        echo "   ❌ Error: " . $e->getMessage() . "\n";
    }
    echo "--------------------------------\n";
}

echo "=== TEST SEDE PARAMETERS ===\n";
testSede(1, "ID 1 (Cochabamba)");
testSede('CBA', "Code CBA (Cochabamba)");
testSede(6, "ID 6 (La Paz)");
testSede('LPZ', "Code LPZ (La Paz)");
