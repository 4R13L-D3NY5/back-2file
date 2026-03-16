<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Asignatura;
use App\Models\Grupo;
use App\Models\InformeSemanal;
use App\Http\Controllers\ReporteController;

echo "=== Test de Propagación de Informes Semanales ===\n\n";

// Usar grupos existentes con comun_token compartido
$grupoOrigenId = 11649; // NYD-316
$grupoDestinoId = 13236; // SIS-326 (mismo docente, mismo comun_token)

$grupoOrigen = Grupo::with('asignatura')->find($grupoOrigenId);
$grupoDestino = Grupo::with('asignatura')->find($grupoDestinoId);

if (!$grupoOrigen || !$grupoDestino) {
    die("Error: No se encontraron los grupos.\n");
}

echo "Grupo Origen: ID {$grupoOrigen->id}, Asignatura: {$grupoOrigen->asignatura->codigo}, Docente: {$grupoOrigen->docente_id}\n";
echo "Grupo Destino: ID {$grupoDestino->id}, Asignatura: {$grupoDestino->asignatura->codigo}, Docente: {$grupoDestino->docente_id}\n";
echo "Comun Token Origen: " . ($grupoOrigen->asignatura->comun_token ?? 'NULL') . "\n";
echo "Comun Token Destino: " . ($grupoDestino->asignatura->comun_token ?? 'NULL') . "\n\n";

// Verificar que compartan comun_token
if ($grupoOrigen->asignatura->comun_token !== $grupoDestino->asignatura->comun_token) {
    die("Error: Los grupos no comparten comun_token.\n");
}

// Verificar que tengan el mismo docente
if ($grupoOrigen->docente_id !== $grupoDestino->docente_id) {
    die("Error: Los grupos no tienen el mismo docente.\n");
}

// Crear semana de prueba (semana siguiente a la actual)
$semanaInicio = now()->addWeek()->startOfWeek()->format('Y-m-d');
$semanaFin = now()->addWeek()->endOfWeek()->format('Y-m-d');

echo "Creando informe para semana: {$semanaInicio} a {$semanaFin}\n";

// Verificar si ya existe un informe para esa semana (evitar duplicados)
$informeExistente = InformeSemanal::where('grupo_id', $grupoOrigenId)
    ->where('semana_inicio', $semanaInicio)
    ->first();

if ($informeExistente) {
    echo "Ya existe un informe para esta semana. Eliminándolo...\n";
    $informeExistente->delete();
}

// Crear informe de prueba
$informe = InformeSemanal::create([
    'grupo_id' => $grupoOrigenId,
    'docente_id' => $grupoOrigen->docente_id,
    'semana_inicio' => $semanaInicio,
    'semana_fin' => $semanaFin,
    'criterios' => [
        ['nombre' => 'Tema impartido', 'cumple' => true],
        ['nombre' => 'Material didáctico', 'cumple' => true],
        ['nombre' => 'Evaluación formativa', 'cumple' => false],
    ],
    'observaciones' => 'Informe de prueba para test de propagación',
    'escala_alerta' => 'AMARILLO',
    'cumplimiento_porcentaje' => 67,
    'created_by' => 1, // Admin user
    'es_propagado' => false,
    'propagado_de_id' => null,
]);

echo "Informe creado - ID: {$informe->id}\n";

// Llamar al método de propagación
$controller = new ReporteController();
$reflection = new ReflectionClass($controller);
$method = $reflection->getMethod('propagarInformeAComunes');
$method->setAccessible(true);

echo "Ejecutando propagación...\n";
$method->invoke($controller, $grupoOrigenId, $informe);

// Verificar propagación
$informePropagado = InformeSemanal::where('grupo_id', $grupoDestinoId)
    ->where('semana_inicio', $semanaInicio)
    ->first();

if ($informePropagado) {
    echo "✓ Propagación exitosa!\n";
    echo "  Informe propagado ID: {$informePropagado->id}\n";
    echo "  es_propagado: " . ($informePropagado->es_propagado ? 'true' : 'false') . "\n";
    echo "  propagado_de_id: {$informePropagado->propagado_de_id}\n";
    
    // Verificar que apunte al informe original
    if ($informePropagado->propagado_de_id == $informe->id) {
        echo "  ✓ propagado_de_id correctamente referenciado\n";
    } else {
        echo "  ✗ ERROR: propagado_de_id no apunta al informe original\n";
    }
    
    // Verificar que el informe original no esté marcado como propagado
    $informe->refresh();
    if (!$informe->es_propagado && $informe->propagado_de_id === null) {
        echo "  ✓ Informe original mantiene es_propagado=false\n";
    } else {
        echo "  ✗ ERROR: Informe original modificado incorrectamente\n";
    }
    
    // Limpiar: eliminar informes de prueba
    echo "\nLimpiando informes de prueba...\n";
    $informePropagado->delete();
    $informe->delete();
    echo "✓ Informes de prueba eliminados.\n";
} else {
    echo "✗ ERROR: No se creó el informe propagado\n";
    
    // Limpiar informe original
    $informe->delete();
    echo "Informe original eliminado.\n";
}

echo "\n=== Test completado ===\n";