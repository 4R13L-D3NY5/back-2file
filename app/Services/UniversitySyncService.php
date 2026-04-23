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
     * Sync only hour fields for subjects in plan N using University /courses.
     */
    public function syncPlanNHours(
        ?string $sedeCode = null,
        ?string $careerCodeFilter = null,
        callable $progressCallback = null,
    ): array {
        $stats = [
            'sedes_processed' => 0,
            'careers_consulted' => 0,
            'courses_read' => 0,
            'subjects_updated' => 0,
            'subjects_not_found' => 0,
            'local_careers_not_found' => 0,
            'duplicate_matches' => 0,
            'conflicts_detected' => 0,
            'errors' => 0,
        ];

        $appliedHoursByAsignatura = [];

        $sedes = Sede::query()
            ->where('activo', true)
            ->when($sedeCode, function ($query) use ($sedeCode) {
                $query->whereRaw('LOWER(codigo) = ?', [strtolower($sedeCode)]);
            })
            ->get();

        foreach ($sedes as $sede) {
            $stats['sedes_processed']++;
            $branchCode = strtolower($sede->codigo);

            try {
                if ($progressCallback) {
                    $progressCallback("Syncing plan N hours for {$branchCode} ({$sede->nombre})...");
                }

                $careers = collect($this->client->getCareers($branchCode));
            } catch (\Throwable $e) {
                Log::error("Plan N hour sync failed while fetching careers for {$branchCode}: " . $e->getMessage(), [
                    'sede_id' => $sede->id,
                    'sede_codigo' => $sede->codigo,
                ]);
                $stats['errors']++;
                continue;
            }

            if ($careerCodeFilter) {
                $careers = $careers
                    ->filter(fn($career) => strtoupper($career['careerCode'] ?? '') === $careerCodeFilter)
                    ->values();
            }

            foreach ($careers as $careerData) {
                $careerCode = strtoupper(trim((string) ($careerData['careerCode'] ?? '')));
                if ($careerCode === '') {
                    continue;
                }

                $stats['careers_consulted']++;

                $localCareer = Carrera::where('sigla', $careerCode)->first();
                if (!$localCareer) {
                    Log::warning('Plan N hour sync skipped because local career was not found.', [
                        'career_code' => $careerCode,
                        'sede_id' => $sede->id,
                        'sede_codigo' => $sede->codigo,
                    ]);
                    $stats['local_careers_not_found']++;
                    continue;
                }

                try {
                    $courses = $this->client->getCourses($branchCode, $careerCode);
                } catch (\Throwable $e) {
                    Log::error("Plan N hour sync failed while fetching courses for {$careerCode} in {$branchCode}: " . $e->getMessage(), [
                        'career_id' => $localCareer->id,
                        'career_code' => $careerCode,
                        'sede_id' => $sede->id,
                    ]);
                    $stats['errors']++;
                    continue;
                }

                foreach ($courses as $courseData) {
                    $courseCode = trim((string) ($courseData['courseCode'] ?? ''));
                    if ($courseCode === '') {
                        continue;
                    }

                    $stats['courses_read']++;

                    $hourPayload = $this->buildPlanNHourPayload($courseData);
                    $matches = $this->findPlanNSubjects($courseCode, $localCareer, $sede);

                    if ($matches->isEmpty()) {
                        Log::warning('Plan N hour sync found no local subject match.', [
                            'course_code' => $courseCode,
                            'career_code' => $careerCode,
                            'career_id' => $localCareer->id,
                            'sede_id' => $sede->id,
                            'sede_codigo' => $sede->codigo,
                        ]);
                        $stats['subjects_not_found']++;
                        continue;
                    }

                    if ($matches->count() > 1) {
                        Log::warning('Plan N hour sync found duplicate local matches for the same course.', [
                            'course_code' => $courseCode,
                            'career_code' => $careerCode,
                            'career_id' => $localCareer->id,
                            'sede_id' => $sede->id,
                            'match_ids' => $matches->pluck('id')->all(),
                        ]);
                        $stats['duplicate_matches']++;
                    }

                    foreach ($matches as $asignatura) {
                        $existingApplied = $appliedHoursByAsignatura[$asignatura->id] ?? null;

                        if ($existingApplied && $this->hasHoursConflict($existingApplied, $hourPayload)) {
                            Log::warning('Plan N hour sync detected conflicting hour data for a shared subject. Keeping the first applied values.', [
                                'asignatura_id' => $asignatura->id,
                                'course_code' => $courseCode,
                                'existing' => $existingApplied,
                                'incoming' => array_merge($hourPayload, [
                                    'career_code' => $careerCode,
                                    'career_id' => $localCareer->id,
                                    'sede_id' => $sede->id,
                                    'sede_codigo' => $sede->codigo,
                                ]),
                            ]);
                            $stats['conflicts_detected']++;
                            continue;
                        }

                        if (!$existingApplied) {
                            $appliedHoursByAsignatura[$asignatura->id] = array_merge($hourPayload, [
                                'career_code' => $careerCode,
                                'career_id' => $localCareer->id,
                                'sede_id' => $sede->id,
                                'sede_codigo' => $sede->codigo,
                                'course_code' => $courseCode,
                            ]);
                        }

                        $asignatura->fill($hourPayload);
                        if ($asignatura->isDirty([
                            'creditos',
                            'horas_teoricas',
                            'horas_practicas',
                            'carga_horaria_total',
                        ])) {
                            $asignatura->save();
                        }

                        $stats['subjects_updated']++;
                    }
                }
            }
        }

        return $stats;
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

    private function buildPlanNHourPayload(array $courseData): array
    {
        $credits = (int) ($courseData['credits'] ?? 0);
        $theoryHours = (int) ($courseData['theoryHours'] ?? 0);
        $practiceHours = (int) ($courseData['practiceHours'] ?? 0);

        return [
            'creditos' => $credits,
            'horas_teoricas' => $theoryHours,
            'horas_practicas' => $practiceHours,
            'carga_horaria_total' => ($theoryHours + $practiceHours) * 20,
        ];
    }

    private function findPlanNSubjects(string $courseCode, Carrera $career, Sede $sede)
    {
        return Asignatura::query()
            ->where('codigo', $courseCode)
            ->where('plan_estudios', 'N')
            ->whereHas('carreras', function ($query) use ($career, $sede) {
                $query
                    ->where('carreras.id', $career->id)
                    ->where('asignatura_carrera.sede_id', $sede->id);
            })
            ->get();
    }

    private function hasHoursConflict(array $existingApplied, array $incomingPayload): bool
    {
        return $existingApplied['creditos'] !== $incomingPayload['creditos']
            || $existingApplied['horas_teoricas'] !== $incomingPayload['horas_teoricas']
            || $existingApplied['horas_practicas'] !== $incomingPayload['horas_practicas']
            || $existingApplied['carga_horaria_total'] !== $incomingPayload['carga_horaria_total'];
    }
}
