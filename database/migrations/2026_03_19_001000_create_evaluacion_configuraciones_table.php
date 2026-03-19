<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEvaluacionConfiguracionesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('evaluacion_configuraciones', function (Blueprint $table) {
            $table->id();
            
            // Nivel de configuración: 'nacional', 'sede', 'carrera'
            $table->string('nivel', 20)->default('nacional');
            
            // Relaciones opcionales para configuraciones específicas
            $table->unsignedBigInteger('sede_id')->nullable();
            $table->unsignedBigInteger('carrera_id')->nullable();
            
            // JSON para guardar toda la configuración combinada (tiempos, parciales, etc.)
            $table->json('configuracion');
            
            $table->timestamps();
            
            // Índices y restricciones
            // Para asegurar que no haya duplicados de la misma configuración (ej. dos registros nacionales)
            // Esto evita crear configuraciones contradictorias.
            $table->unique(['nivel', 'sede_id', 'carrera_id'], 'eval_config_unique_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('evaluacion_configuraciones');
    }
}
