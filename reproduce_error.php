<?php
try {
    // Authenticate as a user (e.g., ID 1 or a known admin/director)
    Auth::loginUsingId(1); // Adjust ID if necessary

    $controller = app()->make(\App\Http\Controllers\AsignaturaController::class);
    $request = \Illuminate\Http\Request::create('/api/asignaturas/360', 'GET');

    // Allow input injection if needed
    // $request->merge(['carrera_id' => ...]);

    $response = $controller->show($request, 360);
    echo "Response Status: " . $response->status() . "\n";
    if ($response->status() != 200) {
        echo "Content: " . $response->content() . "\n";
    } else {
        echo "Success!\n";
    }
} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}
