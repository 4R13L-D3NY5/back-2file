<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Sede;
use App\Models\Carrera;
use App\Models\Asignatura;
use App\Services\University\UniversityService;
use Illuminate\Support\Facades\Log;

class SyncAcademicStructure extends Command
{
    protected $signature = 'sync:academic-structure';
    protected $description = 'Sincroniza Sedes (Estáticas), Carreras y Asignaturas desde la University API';

    protected $universityService;

    public function __construct(UniversityService $service)
    {
        parent::__construct();
        $this->universityService = $service;
    }

    public function handle()
    {
        $this->info("Iniciando Sincronización de Estructura Académica...");

        // 1. Sedes (Se asume que ya están pobladas por Seeder, iteramos sobre las activas)
        $sedes = Sede::where('activo', true)->get();
        $this->info("Se encontraron {$sedes->count()} sedes activas.");

        foreach ($sedes as $sede) {
            $this->info("Procesando Sede: {$sede->nombre} ({$sede->codigo})");

            try {
                // 2. Carreras
                $apiCarreras = $this->universityService->getCareers($sede->codigo);

                // La API podría devolver null o array vacío
                if (empty($apiCarreras)) {
                    $this->warn(" -> Sin carreras encontradas para {$sede->codigo}");
                    continue;
                }

                foreach ($apiCarreras as $apiCarrera) {
                    $careerCode = $apiCarrera['careerCode'] ?? null;
                    $careerName = $apiCarrera['careerName'] ?? 'Desconocida';

                    if (!$careerCode) continue;

                    // Update or Create Carrera
                    $carrera = Carrera::updateOrCreate(
                        [
                            'codigo' => $careerCode,
                            'sede_id' => $sede->id // Asumiendo que el código de carrera es único por sede o global?
                            // Ojo: Si el código es 'SIS' para todas las sedes, la clave única debe ser compuesta.
                            // Pero en la migración 'codigo' es string sin unique?
                            // O unique global?
                            // REVISIÓN: En la migración 006 'codigo' es index, NO unique.
                            // Por lo tanto podemos tener SIS en Sede 1 y SIS en Sede 2.
                        ],
                        [
                            'nombre' => $careerName,
                            // Mantenemos los campos ricos si ya existen, no los sobreescribimos con null
                            // 'mision' => ... (No viene de la API)
                        ]
                    );

                    $this->line("   -> Carrera: {$careerName} ({$careerCode}) [OK]");

                    // 3. Asignaturas (Courses)
                    // Opcional: Podríamos hacerlo en otro comando si es muy lento
                    $this->syncAsignaturas($sede, $carrera);
                }
            } catch (\Exception $e) {
                $this->error("Error en Sede {$sede->codigo}: " . $e->getMessage());
                Log::error("Sync Error Sede {$sede->codigo}: " . $e->getMessage());
            }
        }

        $this->info("Sincronización Completada.");
    }

    private function syncAsignaturas($sede, $carrera)
    {
        try {
            $apiCourses = $this->universityService->getCourses($sede->codigo, $carrera->codigo);

            if (empty($apiCourses)) return;

            foreach ($apiCourses as $course) {
                // Mapeo según screenshot: courseName, courseCode, credits, semester...

                Asignatura::updateOrCreate(
                    [
                        'codigo' => $course['courseCode'],
                        'carrera_id' => $carrera->id
                    ],
                    [
                        'nombre' => $course['courseName'],
                        'semestre' => $course['semester'] ?? null,
                        'creditos' => $course['credits'] ?? 0,
                        'horas_teoricas' => $course['theoryHours'] ?? 0,
                        'horas_practicas' => $course['practiceHours'] ?? 0,
                        // 'carga_horaria_total' => ...
                    ]
                );
            }
            // $this->line("      -> Asignaturas sincronizadas: " . count($apiCourses));

        } catch (\Exception $e) {
            $this->warn("      -> Error sincronizando asignaturas: " . $e->getMessage());
        }
    }
}
