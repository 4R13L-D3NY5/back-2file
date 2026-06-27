<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateCampusUserTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('campus_user')) {
            Schema::create('campus_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campus')->onDelete('cascade');
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['campus_id', 'user_id']);
            });
        }

        $now = now();
        DB::table('users')
            ->whereNotNull('campus_id')
            ->select('id', 'campus_id')
            ->chunkById(100, function ($users) use ($now) {
                $rows = $users->map(function ($user) use ($now) {
                    return [
                        'campus_id' => $user->campus_id,
                        'user_id' => $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->toArray();

                DB::table('campus_user')->insertOrIgnore($rows);
            });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('campus_user');
    }
}
