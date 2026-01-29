<?php
try {
    $request = new \Illuminate\Http\Request();
    $controller = app(\App\Http\Controllers\DocenteController::class);
    $response = $controller->index($request);
    echo "SUCCESS: " . json_encode($response);
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}
