<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddConCartillaToBancoPreguntas extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('banco_preguntas', function (Blueprint $table) {
            $table->boolean('con_cartilla')->default(true)->after('parcial');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('banco_preguntas', function (Blueprint $table) {
            $table->dropColumn('con_cartilla');
        });
    }
}
