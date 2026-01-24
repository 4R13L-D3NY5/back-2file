<?php

namespace App\Console\Commands;

use App\Services\PlanningSyncService;
use App\Services\UniversitySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncAcademicData extends Command
{
    protected $signature = 'academic:sync {gestion=1-2026} {--carrera=} {--sede=} {--all} {--queue : Run in background queue}';
    protected $description = 'Synchronize academic data from external API';

    private const SEDE_MAP = [
        1 => ['CARCCP', 'CARCPU', 'CARAYE', 'CARDER', 'CARSON', 'CARSIS', 'CARIBI', 'CARBYF', 'CARNYD', 'CARODO', 'CARFIS', 'CARFON', 'CARPRO', 'CARENL', 'CARMED'],
        5 => ['CARVET'],
        6 => ['CARSON', 'CARODO'],
        8 => ['CARICO', 'CARENL'],
        9 => ['CARSON', 'CARODO', 'CARENL', 'CARMED'],
        12 => ['CARMED']
    ];

    public function handle(PlanningSyncService $planningService, UniversitySyncService $universityService)
    {
        $gestion = $this->argument('gestion');
        $carreraArg = $this->option('carrera');
        $sedeArg = $this->option('sede');

        $this->info("Starting Academic Sync for Gestion: $gestion...");

        // OPTIMIZATION: Queue Mode
        if ($this->option('queue')) {
            \App\Jobs\ProcessAcademicSync::dispatch($gestion, $carreraArg, $sedeArg);
            $this->info("✓ Sync Job dispatched to background queue.");
            $this->info("  Check logs for progress or run 'php artisan queue:work'.");
            return;
        }

        $grandTotalStats = [
            'sedes' => 0,
            'bloques' => 0,
            'aulas' => 0,
            'carreras' => 0,
            'asignaturas' => 0,
            'docentes' => 0,
            'grupos' => 0,
            'horarios' => 0,
            'users_created' => 0,
            'errors' => 0
        ];

        // ---------------------------------------------------------
        // PHASE 1: UNIVERSITY API Sync (Carreras, Asignaturas, Pivot)
        // ---------------------------------------------------------
        if (!$carreraArg && !$sedeArg) { // Only run full University sync if not filtering
            $this->info("\n[PHASE 1] Syncing structure from University API...");
            try {
                $uniStats = $universityService->syncAll(function ($msg) {
                    $this->line("  → $msg");
                });

                $this->info("  ✓ University Sync: {$uniStats['careers_synced']} careers, {$uniStats['courses_synced']} courses enriched.");

                if ($uniStats['errors'] > 0) {
                    $this->warn("  ⚠️ University Sync had {$uniStats['errors']} errors.");
                }
            } catch (\Exception $e) {
                $this->error("  ❌ University Sync Failed: " . $e->getMessage());
            }
        } else {
            $this->info("\n[PHASE 1] Skipping University Sync (running in filtered mode).");
        }

        // ---------------------------------------------------------
        // PHASE 2: PLANNING API Sync (Docentes, Horarios, Grupos)
        // ---------------------------------------------------------
        $this->info("\n[PHASE 2] Syncing details from Planning API...");
        $url = 'http://181.188.185.211:9098/api/Grupos/listar/';
        $tasks = [];

        // Determine what to sync
        if ($carreraArg && $sedeArg) {
            $tasks[] = ['sede' => $sedeArg, 'carrera' => $carreraArg];
        } else {
            $this->info("Auto-detecting tasks from Known Configuration...");
            foreach (self::SEDE_MAP as $sedeId => $carreras) {
                if ($sedeArg && $sedeArg != $sedeId) continue;
                foreach ($carreras as $sigla) {
                    if ($carreraArg && $carreraArg != $sigla) continue;
                    $tasks[] = ['sede' => $sedeId, 'carrera' => $sigla];
                }
            }
        }

        $totalTasks = count($tasks);
        $this->info("Found $totalTasks tasks to process.");
        $bar = $this->output->createProgressBar($totalTasks);

        foreach ($tasks as $task) {
            $params = [
                'gestion' => $gestion,
                'sede' => $task['sede'],
                'carrera' => $task['carrera']
            ];

            try {
                $response = Http::timeout(120)->retry(3, 2000)->get($url, $params);
                if ($response->successful()) {
                    $data = $response->json();
                    if (is_array($data) && count($data) > 0) {
                        $stats = $planningService->syncBatch($data);
                        foreach ($stats as $key => $val) {
                            if (isset($grandTotalStats[$key])) {
                                $grandTotalStats[$key] += $val;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->error("\nError syncing Sede {$task['sede']} Carrera {$task['carrera']}: " . $e->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Entity', 'Total Synced (Planning API)'],
            [
                ['Sedes', $grandTotalStats['sedes']],
                ['Bloques', $grandTotalStats['bloques']],
                ['Aulas', $grandTotalStats['aulas']],
                ['Carreras', $grandTotalStats['carreras']],
                ['Asignaturas', $grandTotalStats['asignaturas']],
                ['Docentes', $grandTotalStats['docentes']],
                ['Users Created', $grandTotalStats['users_created']],
                ['Grupos', $grandTotalStats['grupos']],
                ['Horarios', $grandTotalStats['horarios']],
                ['Errors', $grandTotalStats['errors']],
            ]
        );

        $this->info("Full Academic Sync completed.");
    }
}
