<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBancoPreguntasConfiguracionesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('banco_preguntas_configuraciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asignatura_id');
            $table->string('grupo_teorico', 50);
            $table->string('parcial', 20); // 1P, 2P, EF, 2I
            $table->boolean('con_cartilla')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['asignatura_id', 'grupo_teorico', 'parcial'], 'banco_config_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('banco_preguntas_configuraciones');
    }
}
