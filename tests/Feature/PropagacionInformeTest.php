<?php

namespace Tests\Feature;

use App\Models\Asignatura;
use App\Models\Grupo;
use App\Models\InformeSemanal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class PropagacionInformeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test que verifica la propagación automática de informes semanales
     * entre materias comunes (mismo comun_token, mismo docente).
     */
    public function test_propagacion_informe_entre_materias_comunes()
    {
        // 1. Crear datos de prueba
        $comunToken = 'test-propagation-token-' . uniqid();
        
        // Crear dos asignaturas con el mismo comun_token
        $asignatura1 = Asignatura::factory()->create([
            'codigo' => 'TEST-101',
            'nombre' => 'Materia Test 1',
            'comun_token' => $comunToken,
        ]);
        
        $asignatura2 = Asignatura::factory()->create([
            'codigo' => 'TEST-102',
            'nombre' => 'Materia Test 2',
            'comun_token' => $comunToken,
        ]);
        
        // Crear docente (user)
        $docente = User::factory()->create([
            'name' => 'Docente Test',
            'email' => 'docente.test@unitepc.edu.bo',
        ]);
        
        // Crear grupos para ambas asignaturas con el mismo docente
        $grupo1 = Grupo::factory()->create([
            'asignatura_id' => $asignatura1->id,
            'docente_id' => $docente->id,
            'sede_id' => 1,
            'nombre' => 'Grupo Test 1',
        ]);
        
        $grupo2 = Grupo::factory()->create([
            'asignatura_id' => $asignatura2->id,
            'docente_id' => $docente->id,
            'sede_id' => 1,
            'nombre' => 'Grupo Test 2',
        ]);
        
        // 2. Crear informe semanal para el primer grupo (simulando storeWeeklyReport)
        $semanaInicio = now()->startOfWeek()->format('Y-m-d');
        $semanaFin = now()->endOfWeek()->format('Y-m-d');
        
        $informeData = [
            'grupo_id' => $grupo1->id,
            'docente_id' => $docente->id,
            'semana_inicio' => $semanaInicio,
            'semana_fin' => $semanaFin,
            'criterios' => [
                ['nombre' => 'Criterio 1', 'cumple' => true],
                ['nombre' => 'Criterio 2', 'cumple' => false],
            ],
            'observaciones' => 'Observaciones de prueba',
            'escala_alerta' => 'VERDE',
            'cumplimiento_porcentaje' => 50,
            'created_by' => $docente->id,
        ];
        
        $informe = InformeSemanal::create($informeData);
        
        // 3. Llamar manualmente al método de propagación
        $controller = new \App\Http\Controllers\ReporteController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('propagarInformeAComunes');
        $method->setAccessible(true);
        $method->invoke($controller, $grupo1->id, $informe);
        
        // 4. Verificar que se creó el informe propagado para el segundo grupo
        $informePropagado = InformeSemanal::where('grupo_id', $grupo2->id)
            ->where('semana_inicio', $semanaInicio)
            ->first();
        
        $this->assertNotNull($informePropagado, 'No se creó el informe propagado para el segundo grupo');
        $this->assertTrue($informePropagado->es_propagado, 'El informe propagado debe tener es_propagado = true');
        $this->assertEquals($informe->id, $informePropagado->propagado_de_id, 'El campo propagado_de_id debe apuntar al informe original');
        $this->assertEquals($informe->docente_id, $informePropagado->docente_id, 'Debe tener el mismo docente');
        $this->assertEquals($informe->criterios, $informePropagado->criterios, 'Debe tener los mismos criterios');
        $this->assertEquals($informe->escala_alerta, $informePropagado->escala_alerta, 'Debe tener la misma escala de alerta');
        
        // 5. Verificar que el informe original no esté marcado como propagado
        $this->assertFalse($informe->es_propagado, 'El informe original debe tener es_propagado = false');
        $this->assertNull($informe->propagado_de_id, 'El informe original debe tener propagado_de_id = null');
    }
    
    /**
     * Test que verifica que no haya propagación cuando no hay comun_token.
     */
    public function test_no_propagacion_sin_comun_token()
    {
        // Crear asignatura sin comun_token
        $asignatura = Asignatura::factory()->create([
            'codigo' => 'TEST-103',
            'nombre' => 'Materia Sin Token',
            'comun_token' => null,
        ]);
        
        $docente = User::factory()->create();
        $grupo = Grupo::factory()->create([
            'asignatura_id' => $asignatura->id,
            'docente_id' => $docente->id,
            'sede_id' => 1,
        ]);
        
        // Crear informe
        $informe = InformeSemanal::factory()->create([
            'grupo_id' => $grupo->id,
            'docente_id' => $docente->id,
            'semana_inicio' => now()->startOfWeek()->format('Y-m-d'),
            'es_propagado' => false,
            'propagado_de_id' => null,
        ]);
        
        // Llamar al método de propagación
        $controller = new \App\Http\Controllers\ReporteController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('propagarInformeAComunes');
        $method->setAccessible(true);
        $method->invoke($controller, $grupo->id, $informe);
        
        // Verificar que no se crearon informes adicionales
        $totalInformes = InformeSemanal::count();
        $this->assertEquals(1, $totalInformes, 'No debería haber propagación sin comun_token');
    }
}