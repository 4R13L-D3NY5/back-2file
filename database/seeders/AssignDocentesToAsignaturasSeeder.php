<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Asignatura;
use App\Models\Docente;

class AssignDocentesToAsignaturasSeeder extends Seeder
{
    public function run()
    {
        $asignaturas = Asignatura::all();
        $docentes = Docente::all();

        if ($docentes->isEmpty()) {
            $this->command->info('No hay docentes para asignar.');
            return;
        }

        // Logic to distribute evenly
        // We have 200 teachers and N subjects.
        // Instead of random(), let's iterate teachers cyclicly to ensure spread.
        $docentesCount = $docentes->count(); // Renamed from $docenteCount to $docentesCount
        $docenteIndex = 0; // Renamed from $i to $docenteIndex

        foreach ($asignaturas as $asignatura) {
            $docenteId = $docentes[$docenteIndex]->id;

            // Generate realistic classroom and schedule
            $aulas = ['Aula 101', 'Aula 202', 'Anfiteatro A', 'Anfiteatro B', 'Lab 1', 'Lab Computación'];
            $horarios = ['Lun-Mie-Vie 08:30-10:00', 'Mar-Jue 10:00-12:00', 'Lun-Mie 14:00-16:00', 'Sab 08:00-12:00'];

            $docenteIndex = ($docenteIndex + 1) % $docentesCount;

            // Grupo 1 (Canonical)
            DB::table('asignatura_docente')->updateOrInsert(
                ['asignatura_id' => $asignatura->id, 'grupo' => 'GR-1'],
                [
                    'docente_id' => $docenteId,
                    'aula' => $aulas[array_rand($aulas)],
                    'horario' => $horarios[array_rand($horarios)],
                    'cupo' => 50,
                    'estudiantes_inscritos' => rand(15, 48),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Assign GR-2 to 20% of subjects
            if (rand(1, 100) <= 20) {
                $docenteId2 = $docentes[rand(0, $docentesCount - 1)]->id;
                DB::table('asignatura_docente')->updateOrInsert(
                    ['asignatura_id' => $asignatura->id, 'grupo' => 'GR-2'],
                    [
                        'docente_id' => $docenteId2,
                        'aula' => $aulas[array_rand($aulas)],
                        'horario' => $horarios[array_rand($horarios)],
                        'cupo' => 40,
                        'estudiantes_inscritos' => rand(10, 38),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
