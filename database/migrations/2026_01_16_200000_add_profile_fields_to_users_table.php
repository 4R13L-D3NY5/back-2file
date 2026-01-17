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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'nombre')) {
                $table->string('nombre')->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'apellido')) {
                $table->string('apellido')->nullable()->after('nombre');
            }
            if (!Schema::hasColumn('users', 'ci')) {
                $table->string('ci')->nullable()->after('apellido');
            }
            if (!Schema::hasColumn('users', 'telefono')) {
                $table->string('telefono')->nullable()->after('ci');
            }
            if (!Schema::hasColumn('users', 'carrera')) {
                $table->string('carrera')->nullable()->after('telefono');
            }
            if (!Schema::hasColumn('users', 'rol_id')) {
                $table->foreignId('rol_id')->nullable()->constrained('roles')->nullOnDelete()->after('estado');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // No drop columns to avoid data loss in rollback of fix
        });
    }
};
