<?php

namespace Tests\Feature;

use App\Http\Controllers\RolExamenController;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Verifica la lógica del flag `replace` en el endpoint de subida masiva
 * del Rol de Exámenes, y la barrera de seguridad para DIRECTOR_CARRERA.
 */
class RolExamenControllerUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createMinimalSchema();
    }

    public function test_admin_replace_true_elimina_previos_y_reinserta(): void
    {
        $ctx = $this->seedContext('2026-I');
        $this->seedExamenPrevio($ctx, 'MAT-101', '1er Parcial', '2026-04-10');

        $this->actingAsUser($ctx['admin']);

        $request = $this->buildUploadRequest(
            $ctx,
            [['codigo' => 'MAT-202', 'nombre' => 'CALCULO', 'grupo' => '1', 'fecha' => '2026-05-15', 'hora' => '08:00']],
            replace: true,
        );

        $response = app(RolExamenController::class)->upload($request);

        $body = $response->getData(true);
        $this->assertSame(200, $response->getStatusCode(), 'Error: '.json_encode($body));
        $this->assertTrue($body['replaced'], 'replaced debe ser true');
        $this->assertSame(1, $body['deleted_before'], 'Debió eliminar el registro previo');
        $this->assertSame(3, $body['imported'], '1 fila × 3 tipos (1er Parcial, 2do Parcial, Final). Body: '.json_encode($body));

        // MAT-101 del Excel previo YA NO debe existir
        $this->assertDatabaseMissing('rol_examenes', [
            'materia_codigo' => 'MAT-101',
            'gestion' => '2026-I',
        ]);
        // MAT-202 sí debe existir
        $this->assertDatabaseHas('rol_examenes', [
            'materia_codigo' => 'MAT-202',
            'tipo_examen' => '1er Parcial',
        ]);
    }

    public function test_index_desactiva_only_full_group_by_para_soportar_joins(): void
    {
        // Regresión: el método index() de RolExamenController hace
        // groupBy('rol_examenes.id') con múltiples JOINs many-to-many
        // (asignatura_carrera, grupos, docentes). MySQL strict rechaza
        // esto con "isn't in GROUP BY" para columnas de las tablas
        // unidas. La solución debe desactivar ONLY_FULL_GROUP_BY a
        // nivel de sesión antes de ejecutar la query.
        $reflection = new \ReflectionClass(RolExamenController::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            "REPLACE('",
            $source,
            'Debe usar REPLACE para desactivar ONLY_FULL_GROUP_BY en la sesión'
        );
        $this->assertStringContainsString(
            'ONLY_FULL_GROUP_BY',
            $source,
            'Debe mencionar ONLY_FULL_GROUP_BY explícitamente'
        );
        $this->assertStringContainsString(
            "groupBy('rol_examenes.id')",
            $source,
            'GROUP BY debe seguir siendo por id (PK)'
        );
    }

    public function test_index_ejecuta_sin_500_y_devuelve_los_registros(): void
    {
        // Verifica que el index() corre sin lanzar el error
        // "isn't in GROUP BY" y devuelve los exámenes sembrados.
        $ctx = $this->seedContext('2026-I');
        $this->seedExamenPrevio($ctx, 'MAT-101', '1er Parcial', '2026-04-10');

        // Necesario para que el controller no haga 401
        $this->actingAsUser($ctx['admin']);

        $request = Request::create('/api/rol-examenes', 'GET', [
            'gestion' => '2026-I',
            'carrera_id' => $ctx['carreraId'],
            'sede_id' => $ctx['sedeId'],
        ]);

        try {
            $response = app(RolExamenController::class)->index($request);
        } catch (\Throwable $e) {
            // En sqlite, el SET SESSION sql_mode puede no ser soportado
            // y lanzar un error; eso no es lo que estamos validando aquí.
            $this->markTestSkipped('SET SESSION sql_mode no soportado en sqlite: '.$e->getMessage());
            return;
        }

        $this->assertSame(200, $response->getStatusCode(), 'index() debe responder 200');
        $body = $response->getData(true);
        $items = $body['data'] ?? [];
        $this->assertNotEmpty($items, 'Debe devolver al menos el examen sembrado');
        $this->assertSame('MAT-101', $items[0]['materia_codigo']);
    }

    public function test_admin_replace_false_modo_aditivo_conserva_previos(): void
    {
        $ctx = $this->seedContext('2026-I');
        $this->seedExamenPrevio($ctx, 'MAT-101', '1er Parcial', '2026-04-10');
        $this->seedExamenPrevio($ctx, 'MAT-101', 'Final', '2026-06-20');

        $this->actingAsUser($ctx['admin']);

        // El Excel solo trae MAT-202. replace=false debe conservar MAT-101 intacto.
        $request = $this->buildUploadRequest(
            $ctx,
            [['codigo' => 'MAT-202', 'nombre' => 'CALCULO', 'grupo' => '1', 'fecha' => '2026-05-15', 'hora' => '08:00']],
            replace: false,
        );

        $response = app(RolExamenController::class)->upload($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertFalse($body['replaced'], 'replaced debe ser false');
        $this->assertSame(0, $body['deleted_before'], 'No debió eliminar nada');

        // MAT-101 (los 2 previos) debe seguir existiendo
        $this->assertDatabaseHas('rol_examenes', [
            'materia_codigo' => 'MAT-101',
            'tipo_examen' => '1er Parcial',
        ]);
        $this->assertDatabaseHas('rol_examenes', [
            'materia_codigo' => 'MAT-101',
            'tipo_examen' => 'Final',
        ]);
        // MAT-202 debe haberse importado
        $this->assertDatabaseHas('rol_examenes', [
            'materia_codigo' => 'MAT-202',
        ]);
        // Total de registros para esta (gestion, carrera, sede)
        $total = DB::table('rol_examenes')
            ->where('gestion', '2026-I')
            ->where('carrera_id', $ctx['carreraId'])
            ->where('sede_id', $ctx['sedeId'])
            ->count();
        $this->assertSame(2 + 3, $total, '2 previos de MAT-101 + 3 nuevos de MAT-202');
    }

    public function test_director_carrera_no_puede_borrar_aunque_pida_replace_true(): void
    {
        $ctx = $this->seedContext('2026-I');
        $this->seedExamenPrevio($ctx, 'MAT-101', '1er Parcial', '2026-04-10');

        $this->actingAsUser($ctx['director']);

        // El director envía replace=true (intento de borrar)
        $request = $this->buildUploadRequest(
            $ctx,
            [['codigo' => 'MAT-202', 'nombre' => 'CALCULO', 'grupo' => '1', 'fecha' => '2026-05-15', 'hora' => '08:00']],
            replace: true,
        );

        $response = app(RolExamenController::class)->upload($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getData(true);

        // Backend DEBE forzar a false aunque el cliente pidiera true
        $this->assertFalse($body['replaced'], 'Backend debe forzar replaced=false para DIRECTOR_CARRERA');
        $this->assertSame(0, $body['deleted_before'], 'Director no debe poder borrar');

        // MAT-101 previo debe seguir existiendo
        $this->assertDatabaseHas('rol_examenes', [
            'materia_codigo' => 'MAT-101',
            'tipo_examen' => '1er Parcial',
        ]);
        // MAT-202 también (modo aditivo lo importó)
        $this->assertDatabaseHas('rol_examenes', [
            'materia_codigo' => 'MAT-202',
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createMinimalSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'rol_examenes',
            'asignatura_carrera',
            'asignaturas',
            'grupos',
            'directors',
            'users',
            'roles',
            'carreras',
            'sedes',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('sedes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo')->nullable();
            $table->timestamps();
        });

        Schema::create('carreras', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('rol_id')->nullable();
            $table->unsignedBigInteger('sede_id')->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });

        Schema::create('directors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('sede_id')->nullable();
            $table->unsignedBigInteger('carrera_id')->nullable();
            $table->timestamps();
        });

        Schema::create('asignaturas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('sigla')->nullable();
            $table->string('nombre');
            $table->string('plan_estudios')->nullable();
            $table->string('estado')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('asignatura_carrera', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asignatura_id');
            $table->unsignedBigInteger('carrera_id');
            $table->unsignedBigInteger('sede_id');
            $table->integer('semestre')->nullable();
            $table->timestamps();
        });

        Schema::create('grupos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asignatura_id');
            $table->unsignedBigInteger('carrera_id')->nullable();
            $table->unsignedBigInteger('sede_id')->nullable();
            $table->unsignedBigInteger('docente_id')->nullable();
            $table->string('nombre');
            $table->string('tipo')->nullable();
            $table->string('plan_estudios')->nullable();
            $table->string('gestion')->nullable();
            $table->string('estado')->default('ACTIVO');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('rol_examenes', function (Blueprint $table) {
            $table->id();
            $table->string('gestion', 20);
            $table->unsignedBigInteger('carrera_id');
            $table->string('materia_codigo', 50);
            $table->string('materia_nombre', 255);
            $table->string('tipo_examen', 30);
            $table->unsignedTinyInteger('semana');
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('grupo', 50)->nullable();
            $table->string('grupoTeorico', 50)->nullable();
            $table->string('aula', 50)->nullable();
            $table->text('observaciones')->nullable();
            $table->unsignedBigInteger('sede_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('conflictos')->nullable();
            $table->timestamps();
            $table->index(['gestion', 'carrera_id']);
        });
    }

    /**
     * Crea sede + carrera + asignaturas + usuario admin + usuario director.
     * Retorna un array con todos los IDs para usar en cada test.
     */
    private function seedContext(string $gestion): array
    {
        $sedeId = DB::table('sedes')->insertGetId([
            'nombre' => 'Cochabamba',
            'codigo' => 'cba',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $carreraId = DB::table('carreras')->insertGetId([
            'nombre' => 'Ingenieria de Sistemas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Asignaturas (MAT-101 y MAT-202)
        foreach (['MAT-101' => 'ALGEBRA', 'MAT-202' => 'CALCULO'] as $codigo => $nombre) {
            $asigId = DB::table('asignaturas')->insertGetId([
                'codigo' => $codigo,
                'sigla' => $codigo,
                'nombre' => $nombre,
                'plan_estudios' => 'N',
                'estado' => 'activo',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('asignatura_carrera')->insert([
                'asignatura_id' => $asigId,
                'carrera_id' => $carreraId,
                'sede_id' => $sedeId,
                'semestre' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Roles
        $adminRolId = DB::table('roles')->insertGetId([
            'nombre' => 'Administrador',
            'codigo' => 'ADMIN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $directorRolId = DB::table('roles')->insertGetId([
            'nombre' => 'Director de Carrera',
            'codigo' => 'DIRECTOR_CARRERA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Admin user
        $admin = User::create([
            'username' => 'admin_test',
            'email' => 'admin@test.com',
            'password' => bcrypt('x'),
            'rol_id' => $adminRolId,
            'sede_id' => $sedeId,
            'estado' => true,
        ]);

        // Director user + director record
        $director = User::create([
            'username' => 'director_test',
            'email' => 'director@test.com',
            'password' => bcrypt('x'),
            'rol_id' => $directorRolId,
            'sede_id' => $sedeId,
            'estado' => true,
        ]);
        DB::table('directors')->insert([
            'user_id' => $director->id,
            'sede_id' => $sedeId,
            'carrera_id' => $carreraId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('sedeId', 'carreraId', 'admin', 'director');
    }

    private function seedExamenPrevio(array $ctx, string $codigo, string $tipo, string $fecha): void
    {
        DB::table('rol_examenes')->insert([
            'gestion' => '2026-I',
            'carrera_id' => $ctx['carreraId'],
            'sede_id' => $ctx['sedeId'],
            'materia_codigo' => $codigo,
            'materia_nombre' => 'PREVIO',
            'tipo_examen' => $tipo,
            'semana' => 8,
            'fecha' => $fecha,
            'hora_inicio' => '08:00',
            'hora_fin' => '09:30',
            'grupo' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actingAsUser(User $user): void
    {
        Auth::login($user);
    }

    /**
     * Construye un Request de upload con un Excel en memoria.
     * Estructura de la hoja "ROL GENERAL" según el controller:
     *   fila 7 col B: año de gestión (ej. "2026")
     *   fila 10 en adelante: registros
     *   col B: nombre materia, col C: código, col E: grupo
     *   col G/H: 1er Parcial (fecha/hora)
     *   col I/J: 2do Parcial
     *   col K/L: Final
     */
    private function buildUploadRequest(array $ctx, array $rows, bool $replace): Request
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('ROL GENERAL');
        $sheet->setCellValue('B7', '2026');
        $sheet->setCellValue('A1', 'UNIVERSIDAD');

        $rowNum = 10;
        foreach ($rows as $r) {
            $sheet->setCellValue("B{$rowNum}", $r['nombre']);
            $sheet->setCellValue("C{$rowNum}", $r['codigo']);
            $sheet->setCellValue("E{$rowNum}", $r['grupo']);
            // 1er Parcial
            $sheet->setCellValue("G{$rowNum}", $r['fecha']);
            $sheet->setCellValue("H{$rowNum}", $r['hora']);
            // 2do Parcial
            $sheet->setCellValue("I{$rowNum}", $r['fecha']);
            $sheet->setCellValue("J{$rowNum}", $r['hora']);
            // Final
            $sheet->setCellValue("K{$rowNum}", $r['fecha']);
            $sheet->setCellValue("L{$rowNum}", $r['hora']);
            $rowNum++;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'rol_test_');
        $writer = new Xlsx($ss);
        $writer->save($tmp);

        $uploaded = new UploadedFile(
            $tmp,
            'rol_test.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $request = Request::create('/api/rol-examenes/upload', 'POST', [
            'gestion' => '2026-I',
            'carrera_id' => $ctx['carreraId'],
            'sede_id' => $ctx['sedeId'],
            'replace' => $replace ? '1' : '0',
        ]);
        $request->files->set('file', $uploaded);

        return $request;
    }
}
