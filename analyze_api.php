<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Get actual Careers from DB
$carreras = \App\Models\Carrera::pluck('sigla')->unique()->values()->toArray();

echo "--- STARTING API ANALYSIS (Sedes 1-20) ---\n";
echo "Carreras to check: " . count($carreras) . "\n";

$results = [];
$baseUrl = "http://181.188.185.211:9098/api/Grupos/listar/";

// Iterate Sedes
for ($sede = 1; $sede <= 20; $sede++) {
    echo "Checking Sede $sede...";
    $sedeHits = 0;

    foreach ($carreras as $carrera) {
        $url = $baseUrl . "?gestion=1-2026&carrera=" . urlencode($carrera) . "&sede=" . $sede;

        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 5.0]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response !== false) {
            $headers = $http_response_header ?? [];
            $statusLine = $headers[0] ?? '';

            if (strpos($statusLine, '200') !== false) {
                $json = json_decode($response, true);
                // Only count if data exists
                $count = count($json['data'] ?? []);
                if ($count > 0) {
                    $sedeHits++;
                    $results[] = "Sede $sede | $carrera | $count groups";
                    echo " [Hit: $carrera ($count)]";
                }
            }
        }
    }
    echo ($sedeHits > 0 ? " -> Found groups for $sedeHits careers" : " -> No data") . "\n";
}

echo "\n--- SUMMARY OF VALID DATA ---\n";
if (empty($results)) {
    echo "No data found for any Sede/Carrera combination.\n";
} else {
    foreach ($results as $line) {
        echo $line . "\n";
    }
}
