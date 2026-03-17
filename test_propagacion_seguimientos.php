<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Asignatura;
use App\Models\Grupo;
use App\Models\Cronograma;
use App\Models\Seguimiento;
use App\Http\Controllers\PlanificacionSemestralController;

echo "=== Test de Propagación de Seguimientos ===\n\n";

// Datos de prueba usando asignaturas con comun_token compartido
$asignaturaOrigenId = 65;   // NYD-316
$asignaturaDestinoId = 1612; // SIS-326
$comunToken = 'b91dbd9f-53e2-4436-b0ec-956234b97a2a';

// Verificar que compartan comun_token
$asignaturaOrigen = Asignatura::find($asignaturaOrigenId);
$asignaturaDestino = Asignatura::find($asignaturaDestinoId);

if (!$asignaturaOrigen || !$asignaturaDestino) {
    die("Error: Asignaturas no encontradas.\n");
}

if ($asignaturaOrigen->comun_token !== $asignaturaDestino->comun_token) {
    die("Error: Las asignaturas no comparten comun_token.\n");
}

echo "Asignatura Origen: {$asignaturaOrigen->codigo} (ID: {$asignaturaOrigen->id})\n";
echo "Asignatura Destino: {$asignaturaDestino->codigo} (ID: {$asignaturaDestino->id})\n";
echo "Comun Token: {$asignaturaOrigen->comun_token}\n\n";

// Grupos con mismo docente (docente_id = 46)
$grupoOrigenId = 11649; // NYD-316, docente 46
$grupoDestinoId = 13236; // SIS-326, docente 46

$grupoOrigen = Grupo::find($grupoOrigenId);
$grupoDestino = Grupo::find($grupoDestinoId);

if (!$grupoOrigen || !$grupoDestino) {
    die("Error: Grupos no encontrados.\n");
}

if ($grupoOrigen->docente_id !== $grupoDestino->docente_id) {
    die("Error: Los grupos no tienen el mismo docente.\n");
}

echo "Grupo Origen: ID {$grupoOrigen->id}, Docente: {$grupoOrigen->docente_id}\n";
echo "Grupo Destino: ID {$grupoDestino->id}, Docente: {$grupoDestino->docente_id}\n\n";

// Cronogramas
// Cronograma origen: con grupo 11649, sesión 1
$cronogramaOrigen = Cronograma::where('asignatura_id', $asignaturaOrigenId)
    ->where('grupo_id', $grupoOrigenId)
    ->where('numero_sesion', 1)
    ->first();

if (!$cronogramaOrigen) {
    die("Error: No se encontró cronograma origen.\n");
}

echo "Cronograma Origen: ID {$cronogramaOrigen->id}, Sesión: {$cronogramaOrigen->numero_sesion}\n";

// Cronograma destino: sin grupo, misma sesión
$cronogramaDestino = Cronograma::where('asignatura_id', $asignaturaDestinoId)
    ->whereNull('grupo_id')
    ->where('numero_sesion', $cronogramaOrigen->numero_sesion)
    ->first();

if (!$cronogramaDestino) {
    die("Error: No se encontró cronograma destino (master).\n");
}

echo "Cronograma Destino (master): ID {$cronogramaDestino->id}, Sesión: {$cronogramaDestino->numero_sesion}\n\n";

// Verificar si ya existe seguimiento para evitar duplicados
$seguimientoExistente = Seguimiento::where('cronograma_id', $cronogramaOrigen->id)
    ->where('grupo_id', $grupoOrigenId)
    ->first();

if ($seguimientoExistente) {
    echo "Ya existe un seguimiento para este cronograma. Eliminándolo...\n";
    $seguimientoExistente->delete();
}

// Crear seguimiento de prueba
$seguimiento = Seguimiento::create([
    'cronograma_id' => $cronogramaOrigen->id,
    'grupo_id' => $grupoOrigenId,
    'user_id' => 1,
    'fecha' => now()->format('Y-m-d'),
    'cumplido' => true,
    'tema_cumplido' => true,
    'estado_cumplimiento' => 'TOTAL',
    'observaciones' => 'Seguimiento de prueba para test de propagación',
    'pedagogico' => [
        'estrategias' => [],
        'evaluacion' => [],
        'secuencia' => [],
    ],
    'es_examen' => false,
    'tipo_examen' => null,
    'georeferencia' => null,
    'evidencias' => [],
    'integracion_transversal' => [],
    'es_propagado' => false,
    'propagado_de_id' => null,
]);

echo "Seguimiento creado - ID: {$seguimiento->id}\n";
echo "  es_propagado: " . ($seguimiento->es_propagado ? 'true' : 'false') . "\n";
echo "  propagado_de_id: " . ($seguimiento->propagado_de_id ?? 'NULL') . "\n\n";

// Llamar al método de propagación
$controller = new PlanificacionSemestralController();
$reflection = new ReflectionClass($controller);
$method = $reflection->getMethod('propagarSeguimientoAComunes');
$method->setAccessible(true);

echo "Ejecutando propagación...\n";
$method->invoke($controller, $cronogramaOrigen->id, $grupoOrigenId, $seguimiento);

// Verificar propagación
$seguimientoPropagado = Seguimiento::where('cronograma_id', $cronogramaDestino->id)
    ->where('grupo_id', $grupoDestinoId)
    ->first();

if ($seguimientoPropagado) {
    echo "✓ Propagación exitosa!\n";
    echo "  Seguimiento propagado ID: {$seguimientoPropagado->id}\n";
    echo "  es_propagado: " . ($seguimientoPropagado->es_propagado ? 'true' : 'false') . "\n";
    echo "  propagado_de_id: {$seguimientoPropagado->propagado_de_id}\n";
    
    // Verificar que apunte al seguimiento original
    if ($seguimientoPropagado->propagado_de_id == $seguimiento->id) {
        echo "  ✓ propagado_de_id correctamente referenciado\n";
    } else {
        echo "  ✗ ERROR: propagado_de_id no apunta al seguimiento original\n";
    }
    
    // Verificar que el seguimiento original no esté marcado como propagado
    $seguimiento->refresh();
    if (!$seguimiento->es_propagado && $seguimiento->propagado_de_id === null) {
        echo "  ✓ Seguimiento original mantiene es_propagado=false\n";
    } else {
        echo "  ✗ ERROR: Seguimiento original modificado incorrectamente\n";
    }
    
    // Limpiar: eliminar seguimientos de prueba
    echo "\nLimpiando seguimientos de prueba...\n";
    $seguimientoPropagado->delete();
    $seguimiento->delete();
    echo "✓ Seguimientos de prueba eliminados.\n";
} else {
    echo "✗ ERROR: No se creó el seguimiento propagado\n";
    
    // Verificar si hubo error en la lógica de búsqueda
    echo "Debug: Buscando cronograma destino ID {$cronogramaDestino->id}, grupo destino ID {$grupoDestinoId}\n";
    
    // Limpiar seguimiento original
    $seguimiento->delete();
    echo "Seguimiento original eliminado.\n";
}

echo "\n=== Test completado ===\n";