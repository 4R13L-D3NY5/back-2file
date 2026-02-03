<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Cronograma;
use App\Models\Asignatura;
use Carbon\Carbon;

class SistemasDigitalesControlSeeder extends Seeder
{
    public function run()
    {
        $asignaturaId = 580; // Sistemas Digitales I

        // Only mark sessions as completed if they are date < today (simulation)
        // OR simply mark the first 10 sessions as completed for demonstration
        $sessions = Cronograma::where('asignatura_id', $asignaturaId)
            ->whereNotNull('tema_id') // Only sessions with themes (not purely exams sometimes)
            ->orderBy('fecha')
            ->take(8) // Mark first 8 sessions as COMPLETED
            ->get();

        $this->command->info("Marking " . $sessions->count() . " sessions as completed for Asignatura $asignaturaId");

        foreach ($sessions as $session) {
            // Build pedagogical data based on the theme (if available) or generic
            $pedagogico = [
                'estrategias' => [
                    ['nombre' => 'Pizarra', 'cumplido' => true],
                    ['nombre' => 'Proyector', 'cumplido' => true],
                    ['nombre' => 'Resolución de Ejercicios', 'cumplido' => true]
                ],
                'evaluacion' => [
                    ['nombre' => 'Participación en clase', 'cumplido' => true],
                    ['nombre' => 'Preguntas directas', 'cumplido' => true]
                ],
                'secuencia' => [
                    ['nombre' => 'Inicio: Motivación y repaso', 'cumplido' => true],
                    ['nombre' => 'Desarrollo: Explicación y práctica', 'cumplido' => true],
                    ['nombre' => 'Cierre: Conclusiones', 'cumplido' => true]
                ],
                'tema_cumplido' => true
            ];

            // Integración Transversal (optional but good for completeness)
            $integracion = [
                'investigacion' => [['nombre' => 'Búsqueda bibliográfica', 'cumplido' => false]],
                'interaccion' => [['nombre' => 'Trabajo grupal', 'cumplido' => true]],
                'internalizacion' => [['nombre' => 'Ética profesional', 'cumplido' => true]]
            ];
            $pedagogico['integracion'] = $integracion;

            $session->update([
                'cumplido' => true,
                'observaciones' => 'Sesión desarrollada con normalidad. Estudiantes participativos.',
                'pedagogico' => $pedagogico,
                // Ensure date is in the past if it wasn't already (optional, but keeps logic consistent)
                // 'fecha' => ... (we rely on the existing schedule)
            ]);

            // Also mark attendance as 100% for simulation?
            // The user didn't explicitly ask for attendance, but "Control de Clase" usually implies it.
            // For now, we stick to the requested "documentación" part (pedagogico).
        }
    }
}
