<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\RolExamenController;
use App\Models\Asignatura;
use App\Models\Grupo;
use App\Models\Materia; // Assuming Materia/Asignatura alias
use Illuminate\Support\Facades\DB;

// Mock Request? No need if we test method directly via reflection.

$daysMap = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

$controller = new RolExamenController();
$reflection = new ReflectionClass($controller);
$method = $reflection->getMethod('validateExamRules');
$method->setAccessible(true);

try {
    echo "--- Testing Exam Rules ---\n";

    // Case 1: Valid (Week 8, 1st Partial)
    // $result = $method->invokeArgs($controller, ['CODIGO', 'GRUPO', 8, '2026-03-20', '1er Parcial']);
    // We need valid data.
    // Since we don't have easy dummy data setup for Asignatura/Grupo in this script, we can mock or just test the WEEK validation part first.
    // Week validation doesn't hit DB.

    $result1 = $method->invokeArgs($controller, ['ANY', 'ANY', 8, null, '1er Parcial']);
    echo "Case 1: " . (empty($result1['error']) ? 'PASS' : 'FAIL: ' . $result1['error']) . "\n";

    $result2 = $method->invokeArgs($controller, ['ANY', 'ANY', 2, null, '1er Parcial']);
    echo "Case 2: " . (!empty($result2['error']) ? 'PASS (Error matched)' : 'FAIL') . "\n";

    // Case 3: Class Day Validation (Requires DB hits)
    // We will skip this in pure script unless we know an existing Asignatura/Grupo.
    // Use 'skip' for now unless we search for one.

    $asig = Asignatura::first();
    if ($asig) {
        $grupo = $asig->grupos()->first();
        if ($grupo) {
            echo "Found Asignatura: {$asig->codigo}, Grupo: {$grupo->nombre}\n";
            // Get class days
            $dias = $grupo->horarios()->pluck('dia')->toArray();
            echo "Class Days (Raw): " . var_export($dias, true) . "\n";
            exit; // Stop here to check output
            echo "Class Days: " . implode(', ', $dias) . "\n";

            // Pick a date that is NOT in class days
            // 1=Mon, 7=Sun
            $testDay = 1;
            while (in_array($testDay, $dias)) $testDay++;
            if ($testDay > 7) $testDay = 1; // Should find one

            // Find a date for this day
            $dayName = $daysMap[$testDay];
            $date = date('Y-m-d', strtotime("next $dayName"));

            $result3 = $method->invokeArgs($controller, [$asig->codigo, $grupo->nombre, 8, $date, '1er Parcial']);
            echo "Case 3 (Day Mismatch): " . (!empty($result3['warning']) ? 'PASS (Warning matched)' : 'FAIL') . "\n";

            // Pick a date THAT IS in class days
            if (!empty($dias)) {
                $validDay = $dias[0];
                $validDate = date('Y-m-d', strtotime("next " . $daysMap[$validDay]));
                $result4 = $method->invokeArgs($controller, [$asig->codigo, $grupo->nombre, 8, $validDate, '1er Parcial']);
                echo "Case 4 (Day Match): " . (empty($result4['warning']) ? 'PASS' : 'FAIL: ' . $result4['warning']) . "\n";
            }
        } else {
            echo "Skipping DB tests (No Grupo found)\n";
        }
    } else {
        echo "Skipping DB tests (No Asignatura found)\n";
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
