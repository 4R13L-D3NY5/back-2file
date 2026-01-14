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
        for ($i = 0; $i < 800; $i++) {
            $nombre = $faker->firstName;
            $apellido = $faker->lastName . ' ' . $faker->lastName; // Dos apellidos para más realismo
            $titulo = $faker->randomElement($titulos);

            // Crear Usuario (Foolproof Unique)
            $uniq = uniqid();
            $username = "docente.{$i}.{$uniq}";
            $email = "{$username}@unitepc.edu.bo";

            $user = \App\Models\User::create(
                [
                    'email' => $email,
                    'username' => $username,
                    'nombre' => $nombre,
                    'apellido' => $apellido,
                    'password' => '$2y$12$J/wJ0cK0.sK0.sK0.sK0.sK0.sK0.sK0.sK0.sK0.sK0.sK0', // dummy
                    'rol_id' => 3, // Docente
                    'estado' => true,
                ]
            );

            // Mapeo Título -> Grado para Frontend
            // El frontend usa 'grado_academico' para el badge.
            // Si el frontend muestra "Licenciatura Cristina", es porque usa grado como titulo.
            // Vamos a alinear: Grado = Titulo corto para este seeder, o corregir frontend.
            // User quiere "Lic. Cristina". Frontend usa: `d.grado_academico || 'Lic.'` como titulo.
            // Entonces enviamos 'Lic.', 'Ing.', etc. en grado_academico por ahora.

            \App\Models\Docente::create([
                'user_id' => $user->id,
                'nombre_completo' => "{$titulo} {$nombre} {$apellido}",
                'email' => $user->email,
                'celular' => $faker->phoneNumber,
                'especialidad' => $faker->randomElement($especialidades),
                'grado_academico' => $titulo, // Usamos el título corto (Lic., MSc.) para que el frontend lo muestre bien
                'tipo_dedicacion' => $faker->randomElement($dedicaciones),
                'sede_id' => $sedes->random()->id,
                'estado' => true,
                'foto' => null
            ]);
        }
    }
}
