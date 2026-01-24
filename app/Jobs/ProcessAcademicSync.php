<?php

namespace App\Jobs;

use App\Services\PlanningSyncService;
use App\Services\UniversitySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessAcademicSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $gestion;
    protected $carrera;
    protected $sede;

    // Timeout: 20 minutes (External API is slow)
    public $timeout = 1200;

    /**
     * Create a new job instance.
     */
    public function __construct($gestion = '1-2026', $carrera = null, $sede = null)
    {
        $this->gestion = $gestion;
        $this->carrera = $carrera;
        $this->sede = $sede;
    }

    /**
     * Execute the job.
     */
    public function handle(PlanningSyncService $planningService, UniversitySyncService $universityService): void
    {
        Log::info("Starting Background Academic Sync: {$this->gestion}");

        // 1. Sync University Structure (Carreras/Asignaturas)
        // Only if full sync (no filters)
        if (!$this->carrera && !$this->sede) {
            try {
                Log::info("Phase 1: Syncing University Structure...");
                $uniStats = $universityService->syncAll();
                Log::info("  ✓ University Sync Complete: " . json_encode($uniStats));
            } catch (\Exception $e) {
                Log::error("  X University Sync Failed: " . $e->getMessage());
                // Continue? Yes, Planning API might still work for existing structure.
            }
        }

        // 2. Sync Planning Details (Grupos/Horarios)
        Log::info("Phase 2: Syncing Planning Details...");

        $url = 'http://181.188.185.211:9098/api/Grupos/listar/';

        // Define Tasks
        $tasks = [];
        $sedeMap = [
            1 => ['CARCCP', 'CARCPU', 'CARAYE', 'CARDER', 'CARSON', 'CARSIS', 'CARIBI', 'CARBYF', 'CARNYD', 'CARODO', 'CARFIS', 'CARFON', 'CARPRO', 'CARENL', 'CARMED'],
            5 => ['CARVET'],
            6 => ['CARSON', 'CARODO'],
            8 => ['CARICO', 'CARENL'],
            9 => ['CARSON', 'CARODO', 'CARENL', 'CARMED'],
            12 => ['CARMED']
        ];

        if ($this->carrera && $this->sede) {
            $tasks[] = ['sede' => $this->sede, 'carrera' => $this->carrera];
        } else {
            foreach ($sedeMap as $s => $carreras) {
                if ($this->sede && $this->sede != $s) continue;
                foreach ($carreras as $sigla) {
                    if ($this->carrera && $this->carrera != $sigla) continue;
                    $tasks[] = ['sede' => $s, 'carrera' => $sigla];
                }
            }
        }

        foreach ($tasks as $task) {
            $params = [
                'gestion' => $this->gestion,
                'sede' => $task['sede'],
                'carrera' => $task['carrera']
            ];

            try {
                // Retry Logic inside Job too
                $response = Http::timeout(120)->retry(3, 2000)->get($url, $params);

                if ($response->successful()) {
                    $data = $response->json();
                    if (is_array($data) && count($data) > 0) {
                        $stats = $planningService->syncBatch($data);
                        Log::info("    Synced Sede {$task['sede']} Carrera {$task['carrera']}: " . $stats['grupos'] . " groups.");
                    } else {
                        Log::info("    No data for Sede {$task['sede']} Carrera {$task['carrera']}");
                    }
                }
            } catch (\Exception $e) {
                Log::error("    Error syncing Sede {$task['sede']} Carrera {$task['carrera']}: " . $e->getMessage());
            }
        }

        Log::info("Background Academic Sync Completed.");
    }
}
