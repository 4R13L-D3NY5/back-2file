<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class UserRequestSeeder extends Seeder
{
    public function run(): void
    {
        // Fake user request generation disabled.
        $this->command->info('Generación de usuarios de prueba omitida.');
    }
}
