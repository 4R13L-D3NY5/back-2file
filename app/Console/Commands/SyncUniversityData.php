<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\University\UniversityService;
use App\Services\AsignaturaSyncService;
use App\Models\Sede;
use App\Models\Asignatura;
use Illuminate\Support\Facades\Log;

class SyncUniversityData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-university-data {--force : Force update existing analytical programs} {--career= : Filter by career code}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync all subjects and analytical programs from University API (100% Consumption)';

    protected $universityService;
    protected $syncService;

    public function __construct(UniversityService $universityService, AsignaturaSyncService $syncService)
    {
        parent::__construct();
        $this->universityService = $universityService;
        $this->syncService = $syncService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting University Data Sync...');
        $force = $this->option('force');
        $careerFilter = $this->option('career');

        $sedes = Sede::where('activo', true)->get();

        foreach ($sedes as $sede) {
            $this->info("Processing Sede: {$sede->nombre} ({$sede->codigo})");

            $carreras = $sede->carreras()->where('activo', true);
            if ($careerFilter) {
                $carreras->where('codigo', $careerFilter);
            }
            $carreras = $carreras->get();

            foreach ($carreras as $carrera) {
                $this->info("  Processing Carrera: {$carrera->nombre} ({$carrera->codigo})");

                try {
                    $courses = $this->universityService->getCourses($sede->codigo, $carrera->codigo);

                    if (empty($courses)) {
                        $this->warn("    No courses found for {$carrera->codigo}");
                        continue;
                    }

                    $bar = $this->output->createProgressBar(count($courses));
                    $bar->start();

                    foreach ($courses as $courseData) {
                        // 1. Create/Update Asignatura
                        $asignatura = Asignatura::updateOrCreate(
                            ['codigo' => $courseData['courseCode']], // Unique Key: courseCode
                            [
                                'nombre' => $courseData['courseName'],
                                'semestre' => $courseData['semester'] ?? 1,
                                'creditos' => $courseData['credits'] ?? 0,
                                'carrera_id' => $carrera->id,
                                'tipo_curso' => $courseData['courseType'] ?? 'REGULAR',
                                // Mapeo correcto de horas desde la API
                                'horas_teoricas' => $courseData['theoryHours'] ?? 0,
                                'horas_practicas' => $courseData['practiceHours'] ?? 0,
                                'carga_horaria_total' => ($courseData['theoryHours'] ?? 0) + ($courseData['practiceHours'] ?? 0),
                                // Default placeholders for extended fields
                                'area_desempenio' => 'General',
                                'modalidad' => 'SEMIPRESENCIAL',
                            ]
                        );

                        // 2. Sync Analytical Program (Units & Biblio)
                        if ($force || $asignatura->unidades()->count() === 0) {
                            $this->syncService->syncAnalyticalProgram($asignatura, $sede->codigo, $carrera->codigo, $force);
                        }

                        $bar->advance();
                    }

                    $bar->finish();
                    $this->newLine();
                } catch (\Exception $e) {
                    $this->error("    Error fetching courses: " . $e->getMessage());
                    Log::error("MassSync Error [{$sede->codigo}/{$carrera->codigo}]: " . $e->getMessage());
                }
            }
        }

        $this->info('Sync Completed Successfully.');
    }
}
