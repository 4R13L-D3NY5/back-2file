<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SeguimientoSemanal;
use App\Models\Asignatura;
use App\Models\Grupo;
use App\Models\Cronograma;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportesDirectorSeeder extends Seeder
{
    public function run()
    {
        $carreraId = 13; // Ingeniería de Sonido (as used in previous tasks)
        $sedeId = 1;

        // Define weeks to seed (e.g., Start of semester Feb 2026)
        $startDate = Carbon::create(2026, 2, 2); // Monday

        // Let's generate reports for 4 weeks
        for ($i = 0; $i < 4; $i++) {
            $weekStart = $startDate->copy()->addWeeks($i);
            $weekEnd = $weekStart->copy()->addDays(6);

            $this->command->info("Generando reportes para la semana: " . $weekStart->toDateString());

            // Get subjects for this career/sede
            $asignaturas = Asignatura::whereHas('carreras', function ($q) use ($carreraId, $sedeId) {
                $q->where('carreras.id', $carreraId)
                    ->where('asignatura_carrera.sede_id', $sedeId);
            })->get();

            foreach ($asignaturas as $asignatura) {
                // Check if report exists
                $exists = SeguimientoSemanal::where('asignatura_id', $asignatura->id)
                    ->where('semana_inicio', $weekStart->toDateString())
                    ->exists();

                if ($exists) {
                    $this->command->info(" - Reporte ya existe para {$asignatura->nombre}");
                    continue;
                }

                // Find Docente via Groups (More reliable given previous tasks)
                $grupo = Grupo::where('asignatura_id', $asignatura->id)->latest()->first();
                $docenteId = $grupo?->docente_id;

                if (!$docenteId) {
                    $this->command->info(" - No docente found for {$asignatura->nombre}");
                    continue;
                }

                if (!$docenteId) continue;

                // Calculate Compliance (Simplified logic for Seeder)
                $cronogramas = Cronograma::where('asignatura_id', $asignatura->id)
                    ->whereBetween('fecha', [$weekStart->toDateString(), $weekEnd->toDateString()])
                    ->withCount(['asistencias' => function ($q) {
                        $q->where('asistio', true);
                    }])
                    ->get();

                $statusData = [
                    'temaImpartido' => false,
                    'actividadesFormativas' => false,
                    'secuenciaDidactica' => false,
                    'plataformaVirtual' => true,
                    'evidencias' => false,
                    'evaluaciones' => false,
                    'integracionTransversal' => false
                ];

                $alerta = 'ROJO';

                if ($cronogramas->isNotEmpty()) {
                    // Apply logic
                    $anyAsistenciaOk = $cronogramas->contains(function ($s) {
                        return $s->asistencias_count > 0; // Simple check
                    });
                    $anyContentOk = $cronogramas->contains(fn($s) => !empty($s->tema_id));
                    $allCompleted = $cronogramas->every(fn($s) => $s->cumplido);
                    $anyPlanning = $cronogramas->contains(fn($s) => !empty($s->pedagogico));

                    $statusData['temaImpartido'] = $anyContentOk;
                    $statusData['actividadesFormativas'] = $anyPlanning;
                    $statusData['secuenciaDidactica'] = $anyPlanning;
                    $statusData['evidencias'] = $anyAsistenciaOk;
                    $statusData['evaluaciones'] = $allCompleted;

                    // Calculate Alert
                    $checked = collect($statusData)->filter()->count();
                    if ($checked >= 7) $alerta = 'VERDE';
                    elseif ($checked >= 5) $alerta = 'AMARILLO';
                }

                SeguimientoSemanal::create([
                    'asignatura_id' => $asignatura->id,
                    'docente_id' => $docenteId,
                    'semana_inicio' => $weekStart->toDateString(),
                    'criterios' => $statusData,
                    'alerta' => $alerta,
                    'sede_id' => $sedeId,
                    'carrera_id' => $carreraId,
                    'observaciones_generales' => $alerta === 'VERDE'
                        ? 'Buen desempeño semanal. Cumplimiento total.'
                        : 'Se requiere actualizar la documentación y registro de clases.',
                    'created_by' => 1 // Admin
                ]);

                $this->command->info(" - Reporte creado para {$asignatura->nombre} ({$alerta})");
            }
        }
    }
}
