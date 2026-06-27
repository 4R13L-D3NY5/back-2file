<?php

namespace Tests\Feature;

use App\Models\Asignatura;
use App\Models\Carrera;
use App\Models\Sede;
use App\Services\University\UniversityService;
use App\Services\UniversitySyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SyncPlanNHoursTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createMinimalSchema();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_sync_plan_n_hours_updates_matching_subjects_and_reports_edge_cases(): void
    {
        Log::spy();

        $sede = $this->createSede('Cochabamba', 'cba');
        $systems = $this->createCarrera('Ingeniería de Sistemas', 'CARSIS');
        $sound = $this->createCarrera('Ingeniería de Sonido', 'CARSON');

        $sharedPlanN = $this->createAsignatura('SIS-111', 'N', 'Cálculo I');
        $duplicatePlanN = $this->createAsignatura('SIS-111', 'N', 'Cálculo I Duplicada');
        $planA = $this->createAsignatura('SIS-111', 'A', 'Cálculo I Plan A', 9, 9, 360);
        $planNull = $this->createAsignatura('SIS-111', null, 'Cálculo I Plan Legacy', 7, 7, 280);

        $this->attachAsignatura($sharedPlanN, $systems, $sede, 1);
        $this->attachAsignatura($duplicatePlanN, $systems, $sede, 1);
        $this->attachAsignatura($planA, $systems, $sede, 1);
        $this->attachAsignatura($planNull, $systems, $sede, 1);
        $planA->update([
            'creditos' => 6,
            'horas_teoricas' => 9,
            'horas_practicas' => 9,
            'carga_horaria_total' => 360,
        ]);
        $planNull->update([
            'creditos' => 5,
            'horas_teoricas' => 7,
            'horas_practicas' => 7,
            'carga_horaria_total' => 280,
        ]);

        // Shared across another local career to trigger conflict protection.
        $this->attachAsignatura($sharedPlanN, $sound, $sede, 1);

        $this->bindUniversityMock(function ($mock) {
            $mock->shouldReceive('getCareers')
                ->once()
                ->with('cba')
                ->andReturn([
                    ['careerCode' => 'CARSIS', 'careerName' => 'Ingeniería de Sistemas'],
                    ['careerCode' => 'CARSON', 'careerName' => 'Ingeniería de Sonido'],
                    ['careerCode' => 'CARELE', 'careerName' => 'Ingeniería Electrónica'],
                ]);

            $mock->shouldReceive('getCourses')
                ->once()
                ->with('cba', 'CARSIS')
                ->andReturn([
                    ['courseCode' => 'SIS-111', 'credits' => 5, 'theoryHours' => 4, 'practiceHours' => 2],
                    ['courseCode' => 'SIS-999', 'credits' => 2, 'theoryHours' => 1, 'practiceHours' => 1],
                ]);

            $mock->shouldReceive('getCourses')
                ->once()
                ->with('cba', 'CARSON')
                ->andReturn([
                    ['courseCode' => 'SIS-111', 'credits' => 6, 'theoryHours' => 5, 'practiceHours' => 1],
                ]);
        });

        $stats = $this->app->make(UniversitySyncService::class)->syncPlanNHours();

        $sharedPlanN->refresh();
        $duplicatePlanN->refresh();
        $planA->refresh();
        $planNull->refresh();

        $this->assertSame(5, $sharedPlanN->creditos);
        $this->assertSame(4, $sharedPlanN->horas_teoricas);
        $this->assertSame(2, $sharedPlanN->horas_practicas);
        $this->assertSame(120, $sharedPlanN->carga_horaria_total);

        $this->assertSame(5, $duplicatePlanN->creditos);
        $this->assertSame(4, $duplicatePlanN->horas_teoricas);
        $this->assertSame(2, $duplicatePlanN->horas_practicas);
        $this->assertSame(120, $duplicatePlanN->carga_horaria_total);

        $this->assertSame(6, $planA->creditos);
        $this->assertSame(9, $planA->horas_teoricas);
        $this->assertSame(9, $planA->horas_practicas);
        $this->assertSame(360, $planA->carga_horaria_total);

        $this->assertSame(5, $planNull->creditos);
        $this->assertSame(7, $planNull->horas_teoricas);
        $this->assertSame(7, $planNull->horas_practicas);
        $this->assertSame(280, $planNull->carga_horaria_total);

        $this->assertSame([
            'sedes_processed' => 1,
            'careers_consulted' => 3,
            'courses_read' => 3,
            'subjects_updated' => 2,
            'subjects_not_found' => 1,
            'local_careers_not_found' => 1,
            'duplicate_matches' => 1,
            'conflicts_detected' => 1,
            'errors' => 0,
        ], $stats);

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            return str_contains($message, 'duplicate local matches')
                && ($context['course_code'] ?? null) === 'SIS-111';
        })->once();

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            return str_contains($message, 'no local subject match')
                && ($context['course_code'] ?? null) === 'SIS-999';
        })->once();

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) use ($sharedPlanN) {
            return str_contains($message, 'shared subject')
                && ($context['asignatura_id'] ?? null) === $sharedPlanN->id;
        })->once();
    }

    public function test_command_respects_sede_and_carrera_filters(): void
    {
        $cba = $this->createSede('Cochabamba', 'cba');
        $this->createSede('Santa Cruz', 'scz');
        $systems = $this->createCarrera('Ingeniería de Sistemas', 'CARSIS');

        $subject = $this->createAsignatura('SIS-222', 'N', 'Programación II');
        $this->attachAsignatura($subject, $systems, $cba, 2);

        $this->bindUniversityMock(function ($mock) {
            $mock->shouldReceive('getCareers')
                ->once()
                ->with('cba')
                ->andReturn([
                    ['careerCode' => 'CARSIS', 'careerName' => 'Ingeniería de Sistemas'],
                    ['careerCode' => 'CARMED', 'careerName' => 'Medicina'],
                ]);

            $mock->shouldReceive('getCourses')
                ->once()
                ->with('cba', 'CARSIS')
                ->andReturn([
                    ['courseCode' => 'SIS-222', 'credits' => 4, 'theoryHours' => 3, 'practiceHours' => 2],
                ]);
        });

        $this->artisan('academic:sync-plan-n-hours', [
            '--sede' => 'cba',
            '--carrera' => 'CARSIS',
        ])->assertExitCode(0);

        $subject->refresh();

        $this->assertSame(4, $subject->creditos);
        $this->assertSame(3, $subject->horas_teoricas);
        $this->assertSame(2, $subject->horas_practicas);
        $this->assertSame(100, $subject->carga_horaria_total);
    }

    private function bindUniversityMock(callable $expectations): void
    {
        $mock = Mockery::mock(UniversityService::class);
        $expectations($mock);

        $this->app->instance(UniversityService::class, $mock);
    }

    private function createSede(string $nombre, string $codigo): Sede
    {
        return Sede::create([
            'nombre' => $nombre,
            'codigo' => $codigo,
            'activo' => true,
        ]);
    }

    private function createCarrera(string $nombre, string $sigla): Carrera
    {
        return Carrera::create([
            'nombre' => $nombre,
            'sigla' => $sigla,
            'activo' => true,
        ]);
    }

    private function createAsignatura(
        string $codigo,
        ?string $planEstudios,
        string $nombre,
        int $creditos = 0,
        int $horasTeoricas = 0,
        int $horasPracticas = 0,
        int $cargaHorariaTotal = 0,
    ): Asignatura {
        return Asignatura::create([
            'codigo' => $codigo,
            'plan_estudios' => $planEstudios,
            'nombre' => $nombre,
            'creditos' => $creditos,
            'horas_teoricas' => $horasTeoricas,
            'horas_practicas' => $horasPracticas,
            'carga_horaria_total' => $cargaHorariaTotal,
        ]);
    }

    private function attachAsignatura(Asignatura $asignatura, Carrera $carrera, Sede $sede, int $semestre): void
    {
        $asignatura->carreras()->attach($carrera->id, [
            'sede_id' => $sede->id,
            'semestre' => $semestre,
        ]);
    }

    private function createMinimalSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('asignatura_carrera');
        Schema::dropIfExists('asignaturas');
        Schema::dropIfExists('carreras');
        Schema::dropIfExists('sedes');

        Schema::create('sedes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('carreras', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('sigla')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('asignaturas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->index();
            $table->string('plan_estudios')->nullable();
            $table->string('nombre');
            $table->integer('creditos')->default(0);
            $table->integer('horas_teoricas')->default(0);
            $table->integer('horas_practicas')->default(0);
            $table->integer('carga_horaria_total')->default(0);
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

        Schema::enableForeignKeyConstraints();
    }
}
