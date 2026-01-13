<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Asignatura;

class StudentSeeder extends Seeder
{
    public function run()
    {
        // 1. Crear Estudiantes Ficticios
        $nombres = ['Juan Perez', 'Maria Gomez', 'Carlos Lopez', 'Ana Martinez', 'Luis Rodriguez', 'Sofia Torres', 'Pedro Ruiz', 'Laura Diaz', 'Diego Herrera', 'Elena Castro'];
        $estudiantesIds = [];

        foreach ($nombres as $index => $nombreCompleto) {
            $parts = explode(' ', $nombreCompleto, 2);
            $nombre = $parts[0];
            $apellido = $parts[1] ?? 'Doe';

            $id = DB::table('estudiantes')->insertGetId([
                'codigo' => 'EST-' . (2000 + $index),
                'nombres' => $nombre,
                'apellidos' => $apellido,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $estudiantesIds[] = $id;
        }

        // 2. Matricularlos en la Asignatura con ID 1 (o la primera que exista)
        $asignatura = Asignatura::first();
        if ($asignatura) {
            foreach ($estudiantesIds as $estudianteId) {
                DB::table('matriculas')->insert([
                    'gestion' => '1-2026',
                    'estudiante_id' => $estudianteId,
                    'asignatura_id' => $asignatura->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
