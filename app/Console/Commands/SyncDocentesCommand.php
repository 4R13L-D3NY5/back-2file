<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Docente;
use App\Models\Rol;
use App\Models\Horario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class SyncDocentesCommand extends Command
{
    protected $signature = 'sync:docentes
                            {--gestion=1-2026 : Gestión académica}
                            {--sede= : ID de sede (Opcional, si se omite procesa TODAS)}
                            {--carrera= : Carrera específica (opcional, por defecto todas)}';

    protected $description = 'Sincroniza asignaciones de docentes usando ID EXACTO de API (Requiere sync previo de horarios)';

    protected string $baseUrl = 'http://181.188.185.211:9098';

    protected function getCarrerasToSync(?string $filter = null): array
    {
        if ($filter) {
            return [$filter];
        }

        // Fetch dynamic list from Database (using 'sigla' column)
        return \App\Models\Carrera::whereNotNull('sigla')
            ->where('sigla', '!=', '')
            ->pluck('sigla')
            ->map(fn($sigla) => strtolower(trim($sigla)))
            ->unique()
            ->values()
            ->toArray();
    }

    public function handle()
    {
        $gestion = $this->option('gestion');
        $sedeOption = $this->option('sede');
        $carreraFiltro = $this->option('carrera');

        $this->info("🚀 Iniciando Sincronización Estricta por ID (Gestion: $gestion)");
        $this->info("⚠️  Este comando asume que la estructura (Grupos/Horarios) ya fue sincronizada por el servicio 'University/Planning'.");

        // Determine Sedes to process
        $sedesToProcess = [];

        if ($sedeOption) {
            $sedeObj = \App\Models\Sede::find($sedeOption);
            if ($sedeObj) {
                $sedesToProcess = [$sedeObj];
            } else {
                $this->error("❌ Sede ID {$sedeOption} no encontrada.");
                return 1;
            }
        } else {
            $sedesToProcess = \App\Models\Sede::all();
        }

        $carrerasAConsultar = $this->getCarrerasToSync($carreraFiltro);

        $docentesUnicos = []; // Key: CI
        $idHorarioMap = [];   // Key: ID_HORARIO => CI_DOCENTE (Direct Link)
        $totalRegistros = 0;

        // --- FASE 1: RECOLECCIÓN DE DATOS (API) ---

        foreach ($sedesToProcess as $sedeObj) {
            $this->info("🏢 Procesando Sede: {$sedeObj->nombre}");
            $sedeId = $sedeObj->id;

            foreach ($carrerasAConsultar as $carrera) {
                try {
                    $response = Http::timeout(60)->get("{$this->baseUrl}/api/Grupos/listar/", [
                        'gestion' => $gestion,
                        'carrera' => $carrera,
                        'sede' => $sedeId
                    ]);

                    if ($response->successful()) {
                        $data = $response->json();
                        $count = count($data);
                        $totalRegistros += $count;

                        // if ($count > 0) $this->line("   ✅ {$carrera}: {$count} registros.");

                        foreach ($data as $item) {
                            $ci = trim($item['ci']);
                            $idHorario = $item['idHorario'] ?? null;

                            // Skip invalid data
                            if (empty($ci) || $ci === '0' || empty($item['docente'])) continue;
                            if (!$idHorario) continue; // Cannot link without ID

                            // 1. Prepare Docente Data
                            if (!isset($docentesUnicos[$ci])) {
                                $docentesUnicos[$ci] = [
                                    'ci' => $ci,
                                    'nombre' => $this->limpiarNombre($item['docente']),
                                    'sede_id' => $sedeId,
                                ];
                            }

                            // 2. Map ID -> Docente
                            // One ID = One Docente Assignment for that session
                            $idHorarioMap[$idHorario] = $ci;
                        }
                    }
                } catch (\Exception $e) {
                    $this->error("   ❌ Error en {$carrera}: {$e->getMessage()}");
                }
            }
        }

        $this->newLine();
        $this->info("📊 Total Registros API: {$totalRegistros}");
        $this->info("🔗 Asignaciones (ID Horario) encontradas: " . count($idHorarioMap));
        $this->info("👨‍🏫 Docentes únicos: " . count($docentesUnicos));

        if (empty($idHorarioMap)) {
            $this->warn("⚠️ No hay asignaciones para procesar.");
            return 0;
        }

        // --- FASE 2: PERSISTENCIA (DOCENTES) ---
        $this->info("💾 Sincronizando Docentes...");

        $rolDocente = Rol::where('nombre', 'DOCENTE')->firstOrFail();
        $docenteModels = []; // Cache: CI => DocenteModel

        $barDocentes = $this->output->createProgressBar(count($docentesUnicos));
        $barDocentes->start();

        foreach ($docentesUnicos as $ci => $data) {
            // Find/Create User
            $user = User::firstOrCreate(
                ['username' => $ci],
                [
                    'nombre' => $this->parsearNombre($data['nombre'])['nombre'],
                    'apellido' => $this->parsearNombre($data['nombre'])['apellido'],
                    'ci' => $ci,
                    'email' => $this->generarEmail($data['nombre']),
                    'password' => $ci,
                    'rol_id' => $rolDocente->id,
                    'estado' => true
                ]
            );

            // Update Name if needed
            if ($user->wasRecentlyCreated) {
                // Already set
            } else {
                $names = $this->parsearNombre($data['nombre']);
                $user->update([
                    'nombre' => $names['nombre'],
                    'apellido' => $names['apellido']
                ]);
            }

            // Find/Create Docente
            $docente = Docente::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'nombre_completo' => $data['nombre'],
                    'sede_id' => $data['sede_id'],
                    'estado' => true
                ]
            );

            $docenteModels[$ci] = $docente;
            $barDocentes->advance();
        }
        $barDocentes->finish();
        $this->newLine(2);

        // --- FASE 3: VINCULACIÓN (HORARIOS -> GRUPOS -> DOCENTE) ---
        $this->info("🔗 Vinculando Docentes a Grupos (Por ID Horario)...");

        $asignadosCount = 0;
        $missingIds = 0;

        // Optimize Query: fetch all relevant horarios at once?
        // Or chunking. Given ~10k records, chunking or simple loop is fine.
        // To be safe and show progress: Loop.

        $barLinks = $this->output->createProgressBar(count($idHorarioMap));
        $barLinks->start();

        foreach ($idHorarioMap as $idHorarioAPI => $ciDocente) {
            $docente = $docenteModels[$ciDocente] ?? null;

            if ($docente) {
                // Here is the CORE CHANGE: Match strict ID
                $horario = Horario::where('id_horario_api', $idHorarioAPI)->first();

                if ($horario) {
                    $grupo = $horario->grupo;
                    if ($grupo) {
                        // UPDATE THE GROUP TEACHER
                        $grupo->docente_id = $docente->id;
                        $grupo->save(); // This implicitly updates "assignment"
                        $asignadosCount++;
                    }
                } else {
                    $missingIds++;
                    // This means "University Sync" missed this session or hasn't run.
                    // We DO NOT CREATE. We respect the structure.
                }
            }
            $barLinks->advance();
        }
        $barLinks->finish();
        $this->newLine(2);

        $this->info("✅ FINALIZADO:");
        $this->line("   - Asignaciones Exitosas: {$asignadosCount}");
        if ($missingIds > 0) {
            $this->warn("   - IDs de Horario no encontrados en DB (Sync University pendiente?): {$missingIds}");
        }

        return 0;
    }

    // --- HELPERS (Same as before) ---
    protected function limpiarNombre(string $nombre): string
    {
        $prefijos = ['Lic.', 'Ing.', 'Dr.', 'Dra.', 'Msc.', 'PhD.', 'Arq.', 'Abg.', 'Lic ', 'Ing ', 'Dr ', 'Dra ', 'Msc ', 'PhD ', 'Arq ', 'Abg '];
        $nombreLimpio = trim($nombre);
        foreach ($prefijos as $prefijo) {
            if (stripos($nombreLimpio, $prefijo) === 0) {
                $nombreLimpio = trim(substr($nombreLimpio, strlen($prefijo)));
            }
        }
        return $nombreLimpio;
    }

    protected function parsearNombre(string $nombreCompleto): array
    {
        $parts = explode(' ', trim($nombreCompleto));
        if (count($parts) >= 3) {
            $nombre = $parts[0];
            $apellido = implode(' ', array_slice($parts, 1));
        } elseif (count($parts) == 2) {
            $nombre = $parts[0];
            $apellido = $parts[1];
        } else {
            $nombre = $nombreCompleto;
            $apellido = 'Unknown';
        }
        return ['nombre' => $nombre, 'apellido' => $apellido];
    }

    protected function generarEmail(string $nombre): string
    {
        $base = strtolower(trim($nombre));
        $base = str_replace(' ', '.', $base);
        $base = preg_replace('/[^a-z0-9.]/', '', $base);
        $email = "{$base}@unitepc.edu.bo";

        // Simple check to avoid query in loop if possible,
        // but for safety in this command we keep it simple.
        if (User::where('email', $email)->exists()) {
            $email = "{$base}" . rand(1, 99) . "@unitepc.edu.bo";
        }
        return $email;
    }
}
