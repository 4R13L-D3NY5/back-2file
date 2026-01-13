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
        // 1. Modificar tabla temas para incluir campos ricos y eliminar relaciones innecesarias
        Schema::table('temas', function (Blueprint $table) {
            // Contenidos (Saberes) como JSON para arrays de strings
            // Modificamos columnas existentes de text a json si la DB lo permite, o las reutilizamos
            // SQLite no tiene tipo JSON nativo estricto (es TEXT), pero MySQL/Postgres sí.
            // Para consistencia y "reset", mejor usaremos json()
            
            // Ya existian como TEXT, las redefinimos conceptualmente como JSON en el modelo
            // Pero agregamos los campos faltantes de Estrategias y Evaluaciones
            
            // Estrategias (Textos simples + Lista de recursos JSON)
            $table->text('estrategias_metodologicas')->nullable();
            $table->text('estrategias_aprendizaje')->nullable();
            $table->json('estrategias_recursos')->nullable(); // Array of strings
            
            // Evaluaciones (Objetos JSON complejos)
            $table->json('evaluacion_formativa')->nullable(); // { actividades:[], instrumentos:[], evidencias:[] }
            $table->json('evaluacion_sumativa')->nullable(); // { actividades:[], instrumentos:[], evidencias:[] }
        });

        // 2. Eliminar tablas redundantes (Ojo: causará pérdida de datos si ya hubiera)
        Schema::dropIfExists('estrategias_temas');
        Schema::dropIfExists('evaluaciones_temas');

        // 3. Crear tabla pivot para bibliografía por tema
        if (!Schema::hasTable('tema_bibliografia')) {
            Schema::create('tema_bibliografia', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tema_id')->constrained('temas')->cascadeOnDelete();
                $table->foreignId('bibliografia_id')->constrained('bibliografias')->cascadeOnDelete();
                $table->integer('pagina_desde')->nullable();
                $table->integer('pagina_hasta')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tema_bibliografia');

        // Re-crear tablas eliminadas (simplificado para rollback)
        Schema::create('evaluaciones_temas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tema_id');
            $table->timestamps();
        });
        Schema::create('estrategias_temas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tema_id');
            $table->timestamps();
        });

        Schema::table('temas', function (Blueprint $table) {
            $table->dropColumn([
                'estrategias_metodologicas',
                'estrategias_aprendizaje',
                'estrategias_recursos',
                'evaluacion_formativa',
                'evaluacion_sumativa'
            ]);
        });
    }
};
