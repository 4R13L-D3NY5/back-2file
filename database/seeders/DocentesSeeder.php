<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DocentesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = \Faker\Factory::create('es_ES');

        $sedes = \App\Models\Sede::all();
        if ($sedes->count() === 0) {
            $sedes = collect([\App\Models\Sede::create(['nombre' => 'Cochabamba', 'codigo' => 'CBA'])]);
        }

        $titulos = ['Lic.', 'Ing.', 'MSc.', 'PhD.', 'Dr.', 'Arq.'];
        $dedicaciones = ['Tiempo Completo', 'Medio Tiempo', 'Tiempo Parcial'];
        $especialidades = ['Ingeniería de Software', 'Matemáticas', 'Derecho Civil', 'Contaduría', 'Psicología', 'Administración', 'Economía', 'Anatomía', 'Física'];

        // Generar 800 docentes para distribución realista de carga (approx 7 materias por docente con 5600 materias)
        // Fake data generation is disabled to work with real data.
        /*
        for ($i = 0; $i < 800; $i++) {
            // ... loop content ...
        }
        */
        $this->command->info('Generación de docentes falsos omitida.');
    }
}
