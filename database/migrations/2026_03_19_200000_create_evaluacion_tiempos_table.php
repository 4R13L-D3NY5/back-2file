<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEvaluacionTiemposTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('evaluacion_tiempos', function (Blueprint $table) {
            $table->id();
            $table->string('gestion')->unique()->comment('Ej. 1/2026, 2/2026');
            $table->integer('minutos_antes_entrega')->default(15);
            $table->integer('horas_antes_generacion')->default(48);
            $table->integer('horas_post_patron')->default(0);
            $table->integer('alerta_horas_antes')->default(24);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('evaluacion_tiempos');
    }
}
