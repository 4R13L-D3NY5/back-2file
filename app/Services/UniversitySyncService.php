<?php

namespace App\Services;

use App\Services\University\UniversityService;
use App\Models\Asignatura;
use App\Models\Carrera;
use App\Models\Sede;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * University Sync Service
 * Syncs careers and courses from University API to enrich local database
 */
class UniversitySyncService
{
    private UniversityService $client;

    // BRANCH_OFFICE_MAP removed - now using database Sedes with 'codigo' field

    public function __construct(UniversityService $client)
    {
        $this->client = $client;
    }

    /**
     * Sync all data from University API
     * Dynamically reads active Sedes from database
     */
    public function syncAll(callable $progressCallback = null): array
    {
        $stats = [
            'careers_synced' => 0,
            'courses_synced' => 0,
            'pivot_created' => 0,
            'errors' => 0,
        ];

        return DB::transaction(function () use (&$stats, $progressCallback) {
            // Dynamic: Fetch all active Sedes from database
            $sedes = Sede::where('activo', true)->get();

            foreach ($sedes as $sede) {
                // Use the 'codigo' field (lowercase) as the branchCode for the API
                $branchCode = strtolower($sede->codigo);

                try {
                    if ($progressCallback) {
                        $progressCallback("Syncing {$branchCode} (Sede: {$sede->nombre})...");
                    }

                    // Sync careers
                    $careers = $this->client->getCareers($branchCode);
                    foreach ($careers as $careerData) {
                        $this->syncCareer($careerData, $sede, $stats);
                    }

                    // Sync courses per career
                    foreach ($careers as $careerData) {
                        $careerCode = $careerData['careerCode'] ?? null;
                        if (!$careerCode) continue;

                        $courses = $this->client->getCourses($branchCode, $careerCode);
                        foreach ($courses as $courseData) {
                            $this->syncCourse($courseData, $careerCode, $sede, $stats);
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("University Sync error for {$branchCode}: " . $e->getMessage());
                    $stats['errors']++;
                }
            }

            return $stats;
        });
    }

    /**
     * Sync a single career
     */
    private function syncCareer(array $data, Sede $sede, array &$stats): void
    {
        $carrera = Carrera::updateOrCreate(
            ['sigla' => $data['careerCode']],
            ['nombre' => $data['careerName']]
        );

        // Attach to sede if not already
        if (!$carrera->sedes()->where('sede_id', $sede->id)->exists()) {
            $carrera->sedes()->attach($sede->id);
        }

        $stats['careers_synced']++;
    }

    /**
     * Sync a single course (asignatura) with enriched data
     */
    private function syncCourse(array $data, string $careerCode, Sede $sede, array &$stats): void
    {
        $courseCode = $data['courseCode'] ?? null;
        if (!$courseCode) return;

        // Find or create asignatura
        $asignatura = Asignatura::updateOrCreate(
            ['codigo' => $courseCode],
            [
                'nombre' => $data['courseName'] ?? $courseCode,
                'creditos' => $data['credits'] ?? 0,
                'horas_teoricas' => $data['theoryHours'] ?? 0,
                'horas_practicas' => $data['practiceHours'] ?? 0,
            ]
        );

        // Find carrera
        $carrera = Carrera::where('sigla', $careerCode)->first();
        if (!$carrera) return;

        // Sync pivot with semestre
        $semestre = $data['semester'] ?? null;
        $asignatura->carreras()->syncWithoutDetaching([
            $carrera->id => [
                'semestre' => $semestre,
                'sede_id' => $sede->id,
            ]
        ]);

        $stats['courses_synced']++;
        $stats['pivot_created']++;
    }
}
