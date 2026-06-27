<?php

namespace App\Console\Commands;

use App\Models\Sede;
use App\Services\UniversitySyncService;
use Illuminate\Console\Command;

class SyncPlanNHoursCommand extends Command
{
    protected $signature = 'academic:sync-plan-n-hours {--sede=} {--carrera=}';

    protected $description = 'Synchronize horas_teoricas, horas_practicas and carga_horaria_total for plan N subjects from University';

    public function handle(UniversitySyncService $universityService): int
    {
        $sedeOption = $this->option('sede');
        $careerOption = $this->option('carrera');

        $sedeCode = null;
        if ($sedeOption) {
            if (is_numeric($sedeOption)) {
                $sede = Sede::find($sedeOption);
                if (!$sede) {
                    $this->error("Sede ID '{$sedeOption}' no encontrada.");

                    return Command::FAILURE;
                }

                $sedeCode = strtolower($sede->codigo);
            } else {
                $sedeCode = strtolower(trim((string) $sedeOption));
            }
        }

        $careerCode = $careerOption ? strtoupper(trim((string) $careerOption)) : null;

        $this->info('Iniciando sincronización de horas University para asignaturas plan N...');

        if ($sedeCode) {
            $this->line("Filtro de sede: {$sedeCode}");
        }

        if ($careerCode) {
            $this->line("Filtro de carrera: {$careerCode}");
        }

        $stats = $universityService->syncPlanNHours(
            $sedeCode,
            $careerCode,
            fn($message) => $this->line("  → {$message}"),
        );

        $this->newLine();
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Sedes procesadas', $stats['sedes_processed']],
                ['Carreras consultadas', $stats['careers_consulted']],
                ['Cursos leídos', $stats['courses_read']],
                ['Asignaturas actualizadas', $stats['subjects_updated']],
                ['Asignaturas no encontradas localmente', $stats['subjects_not_found']],
                ['Carreras locales no encontradas', $stats['local_careers_not_found']],
                ['Duplicados detectados', $stats['duplicate_matches']],
                ['Conflictos detectados', $stats['conflicts_detected']],
                ['Errores', $stats['errors']],
            ],
        );

        $this->info('Sincronización de horas plan N finalizada.');

        return Command::SUCCESS;
    }
}
