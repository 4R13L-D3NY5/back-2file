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
        Schema::create('fusion_backups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('comun_token');
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();
            
            $table->index('comun_token');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fusion_backups');
    }
};