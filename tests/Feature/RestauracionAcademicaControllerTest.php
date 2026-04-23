<?php

namespace Tests\Feature;

use App\Http\Controllers\RestauracionAcademicaController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RestauracionAcademicaControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createMinimalSchema();
    }

    public function test_restauracion_preserva_identidad_local_y_limpia_contenido_previo(): void
    {
        $carreraId = DB::table('carreras')->insertGetId([
            'nombre' => 'Ingenieria Electronica',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sedeId = DB::table('sedes')->insertGetId([
            'nombre' => 'Cochabamba',
            'codigo' => 'cba',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $asignaturaId = DB::table('asignaturas')->insertGetId([
            'codigo' => 'ELC-222',
            'sigla' => 'ELC-222',
            'nombre' => 'ALGEBRA II',
            'plan_estudios' => 'N',
            'descripcion' => 'Descripcion antigua',
            'justificacion' => 'Justificacion antigua',
            'proposito_general' => 'Proposito antiguo',
            'modificado_localmente' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('asignatura_carrera')->insert([
            'asignatura_id' => $asignaturaId,
            'carrera_id' => $carreraId,
            'sede_id' => $sedeId,
            'semestre' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oldUnitId = DB::table('unidades')->insertGetId([
            'asignatura_id' => $asignaturaId,
            'numero' => '1',
            'titulo' => 'Unidad vieja',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oldTemaId = DB::table('temas')->insertGetId([
            'unidad_id' => $oldUnitId,
            'orden' => 1,
            'titulo' => 'Tema viejo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bibliografias')->insert([
            'asignatura_id' => $asignaturaId,
            'titulo' => 'Bibliografia vieja',
            'tipo' => 'BASICA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/api/restauracion/asignatura', 'POST', [
            'asignatura_id' => $asignaturaId,
            'codigo' => 'ELC-222',
            'nombre' => 'ALGEBRA LINEAL SISTEMAS',
            'carrera_id' => $carreraId,
            'sede_id' => $sedeId,
            'plan_estudios' => 'N',
            'justificacion' => 'Justificacion nueva',
            'unidades' => [
                [
                    'numero' => '1',
                    'titulo' => 'Nueva unidad',
                    'temas' => [
                        [
                            'orden' => 1,
                            'titulo' => 'Nuevo tema',
                        ],
                    ],
                ],
            ],
            'bibliografias' => [
                [
                    'titulo' => 'Bibliografia nueva',
                    'autor' => 'Autor nuevo',
                    'tipo' => 'BASICA',
                ],
            ],
        ]);

        $response = app(RestauracionAcademicaController::class)->restaurarAsignatura($request);

        $this->assertSame(200, $response->getStatusCode());

        $payload = $response->getData(true);
        $this->assertSame('success', $payload['status']);

        $asignatura = DB::table('asignaturas')->where('id', $asignaturaId)->first();

        $this->assertSame('ELC-222', $asignatura->codigo);
        $this->assertSame('ALGEBRA II', $asignatura->nombre);
        $this->assertSame('Justificacion nueva', $asignatura->justificacion);
        $this->assertNull($asignatura->descripcion);
        $this->assertNull($asignatura->proposito_general);

        $newUnitIds = DB::table('unidades')->where('asignatura_id', $asignaturaId)->pluck('id');

        $this->assertCount(1, $newUnitIds);
        $this->assertFalse($newUnitIds->contains($oldUnitId));
        $this->assertDatabaseMissing('temas', ['id' => $oldTemaId]);
        $this->assertDatabaseHas('temas', [
            'unidad_id' => $newUnitIds->first(),
            'titulo' => 'Nuevo tema',
        ]);

        $this->assertDatabaseMissing('bibliografias', [
            'asignatura_id' => $asignaturaId,
            'titulo' => 'Bibliografia vieja',
        ]);
        $this->assertDatabaseHas('bibliografias', [
            'asignatura_id' => $asignaturaId,
            'titulo' => 'Bibliografia nueva',
        ]);
    }

    private function createMinimalSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'planificaciones_personales',
            'tema_bibliografia',
            'bibliografias',
            'indicadores',
            'logros_esperados',
            'temas',
            'unidades',
            'asignatura_carrera',
            'asignaturas',
            'carreras',
            'sedes',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('carreras', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('plan_estudios')->nullable();
            $table->timestamps();
        });

        Schema::create('sedes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo')->nullable();
            $table->timestamps();
        });

        Schema::create('asignaturas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo');
            $table->string('sigla')->nullable();
            $table->string('nombre');
            $table->string('plan_estudios')->nullable();
            $table->string('estado')->nullable();
            $table->text('descripcion')->nullable();
            $table->text('justificacion')->nullable();
            $table->text('proposito_general')->nullable();
            $table->text('metodologia_general')->nullable();
            $table->text('sistema_evaluacion')->nullable();
            $table->text('contenido_minimo')->nullable();
            $table->text('requisitos')->nullable();
            $table->text('competencia_asignatura')->nullable();
            $table->text('competencia_global_especifica')->nullable();
            $table->text('elementos_competencia')->nullable();
            $table->boolean('modificado_localmente')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('asignatura_carrera', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignatura_id');
            $table->foreignId('carrera_id');
            $table->foreignId('sede_id');
            $table->integer('semestre')->nullable();
            $table->timestamps();
        });

        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignatura_id');
            $table->string('numero')->nullable();
            $table->string('titulo');
            $table->string('tipo')->nullable();
            $table->text('objetivo')->nullable();
            $table->text('contenido_minimo')->nullable();
            $table->text('elemento_competencia')->nullable();
            $table->integer('orden')->nullable();
            $table->timestamps();
        });

        Schema::create('temas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidad_id');
            $table->integer('orden')->nullable();
            $table->string('titulo');
            $table->string('tipo')->nullable();
            $table->text('resultado_aprendizaje')->nullable();
            $table->text('contenido_conceptual')->nullable();
            $table->text('contenido_procedimental')->nullable();
            $table->text('contenido_actitudinal')->nullable();
            $table->integer('horas_practicas')->nullable();
            $table->integer('horas_teoricas')->nullable();
            $table->text('estrategias_metodologicas')->nullable();
            $table->text('estrategias_aprendizaje')->nullable();
            $table->text('estrategias_recursos')->nullable();
            $table->text('evaluacion_formativa')->nullable();
            $table->text('evaluacion_sumativa')->nullable();
            $table->text('contenido_items')->nullable();
            $table->timestamps();
        });

        Schema::create('logros_esperados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tema_id');
            $table->text('descripcion')->nullable();
            $table->string('tipo_logro')->nullable();
            $table->string('periodo')->nullable();
            $table->timestamps();
        });

        Schema::create('indicadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logro_esperado_id');
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::create('bibliografias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignatura_id');
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->string('autor')->nullable();
            $table->string('editorial')->nullable();
            $table->string('edicion')->nullable();
            $table->string('anio')->nullable();
            $table->string('tipo')->nullable();
            $table->string('isbn')->nullable();
            $table->string('paginas')->nullable();
            $table->timestamps();
        });

        Schema::create('tema_bibliografia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tema_id');
            $table->foreignId('bibliografia_id');
            $table->string('pagina_desde')->nullable();
            $table->string('pagina_hasta')->nullable();
            $table->timestamps();
        });

        Schema::create('planificaciones_personales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tema_id');
            $table->foreignId('user_id')->nullable();
            $table->text('estrategias_metodologicas')->nullable();
            $table->text('estrategias_aprendizaje')->nullable();
            $table->text('estrategias_recursos')->nullable();
            $table->text('evaluacion_formativa')->nullable();
            $table->text('evaluacion_sumativa')->nullable();
            $table->text('secuencia_didactica')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }
}
