<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. BLOQUES (Campus/Edificios)
        if (!Schema::hasTable('bloques')) {
            Schema::create('bloques', function (Blueprint $table) {
                $table->id();
                $table->string('nombre'); // Ej: FLORIDA NORTE
                $table->foreignId('sede_id')->constrained('sedes');
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['sede_id', 'nombre']);
            });
        }

        // 2. AULAS
        if (!Schema::hasTable('aulas')) {
            Schema::create('aulas', function (Blueprint $table) {
                $table->id();
                $table->string('nombre'); // Ej: 101 A
                $table->foreignId('bloque_id')->constrained('bloques');
                $table->integer('capacidad')->nullable();
                $table->integer('pupitres')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['bloque_id', 'nombre']);
            });
        }

        // 3. ACTUALIZAR CARRERAS (Agregar Sigla)
        Schema::table('carreras', function (Blueprint $table) {
            if (!Schema::hasColumn('carreras', 'sigla')) {
                $table->string('sigla')->nullable()->after('nombre')->index(); // CARMED
            }
            if (!Schema::hasColumn('carreras', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Pivot Carrera-Sede (Una carrera puede estar en varias sedes)
        if (!Schema::hasTable('carrera_sede')) {
            Schema::create('carrera_sede', function (Blueprint $table) {
                $table->id();
                $table->foreignId('carrera_id')->constrained('carreras');
                $table->foreignId('sede_id')->constrained('sedes');
                $table->timestamps();
                $table->unique(['carrera_id', 'sede_id']);
            });
        }

        // 4. ACTUALIZAR ASIGNATURAS (SoftDeletes y Carrera)
        Schema::table('asignaturas', function (Blueprint $table) {
            if (!Schema::hasColumn('asignaturas', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // 4.1 ACTUALIZAR DOCENTES (Agregar CI y SoftDeletes)
        Schema::table('docentes', function (Blueprint $table) {
            if (!Schema::hasColumn('docentes', 'ci')) {
                $table->string('ci')->nullable()->after('id')->index();
            }
            if (!Schema::hasColumn('docentes', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // 5. GRUPOS (El núcleo de la programación académica)
        if (!Schema::hasTable('grupos')) {
            Schema::create('grupos', function (Blueprint $table) {
                $table->id();
                $table->string('nombre'); // 1, A, GR-1
                $table->string('tipo')->default('TEORICO'); // TEORICO, PRACTICO
                $table->string('gestion'); // 1-2026

                $table->foreignId('asignatura_id')->constrained('asignaturas');
                $table->foreignId('docente_id')->nullable()->constrained('docentes');

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['asignatura_id', 'nombre', 'tipo', 'gestion'], 'grupo_unico_idx');
            });
        }

        // 6. HORARIOS (1 Grupo -> N Horarios)
        if (!Schema::hasTable('horarios')) {
            Schema::create('horarios', function (Blueprint $table) {
                $table->id();
                $table->foreignId('grupo_id')->constrained('grupos')->cascadeOnDelete();
                $table->foreignId('aula_id')->nullable()->constrained('aulas');

                $table->string('dia'); // LUNES, MARTES...
                $table->time('hora_inicio');
                $table->time('hora_fin');

                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('horarios');
        Schema::dropIfExists('grupos');
        Schema::dropIfExists('aulas');
        Schema::dropIfExists('bloques');
        Schema::dropIfExists('carrera_sede');

        if (Schema::hasColumn('carreras', 'sigla')) {
            Schema::table('carreras', function (Blueprint $table) {
                $table->dropColumn('sigla');
            });
        }

        if (Schema::hasColumn('docentes', 'ci')) {
            Schema::table('docentes', function (Blueprint $table) {
                $table->dropColumn('ci');
                $table->dropSoftDeletes();
            });
        }
    }
};
