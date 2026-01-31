<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Asignatura;

class StudentSeeder extends Seeder
{
    public function run()
    {
        // Fake student generation disabled.
        $this->command->info('Generación de estudiantes falsos omitida.');
    }
}
