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
    public function syncAll(callable $progressCallback = null, $sedeCode = null): array
    {
        $stats = [
            'careers_synced' => 0,
            'courses_synced' => 0,
            'pivot_created' => 0,
            'errors' => 0,
        ];

        return DB::transaction(function () use (&$stats, $progressCallback, $sedeCode) {
            // Dynamic: Fetch all active Sedes from database (optionally filtered)
            $query = Sede::where('activo', true);
            if ($sedeCode) {
                $query->where('codigo', $sedeCode);
            }
            $sedes = $query->get();

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

        // Find or create asignatura (evitar valores nulos en plan_estudios)
        $asignatura = Asignatura::updateOrCreate(
            [
                'codigo' => $courseCode,
                'plan_estudios' => 'N'
            ],
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

        // Sync pivot with semestre - using DB::table to handle duplicates gracefully
        $semestre = $data['semester'] ?? null;

        try {
            // Check if pivot entry exists
            $exists = DB::table('asignatura_carrera')
                ->where('asignatura_id', $asignatura->id)
                ->where('carrera_id', $carrera->id)
                ->where('sede_id', $sede->id)
                ->exists();

            if ($exists) {
                // Update existing
                DB::table('asignatura_carrera')
                    ->where('asignatura_id', $asignatura->id)
                    ->where('carrera_id', $carrera->id)
                    ->where('sede_id', $sede->id)
                    ->update([
                        'semestre' => $semestre,
                        'updated_at' => now()
                    ]);
            } else {
                // Insert new
                DB::table('asignatura_carrera')->insert([
                    'asignatura_id' => $asignatura->id,
                    'carrera_id' => $carrera->id,
                    'sede_id' => $sede->id,
                    'semestre' => $semestre,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
        } catch (\Exception $e) {
            // Log but don't fail the entire sync
            Log::warning("Failed to sync pivot for course {$courseCode}: " . $e->getMessage());
        }

        $stats['courses_synced']++;
        $stats['pivot_created']++;
    }
}
