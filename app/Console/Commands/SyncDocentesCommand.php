<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Docente;
use App\Models\Rol;
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

    protected $description = 'Sincroniza docentes desde la API externa de UNITEPC a la base de datos local (Multi-Sede)';

    protected string $baseUrl = 'http://181.188.185.211:9098';

    protected function getCarrerasToSync(?string $filter = null): array
    {
        if ($filter) {
            return [$filter];
        }

        // Fetch dynamic list from Database (using 'sigla' column)
        // Ensure we normalize to lowercase as expected by the API
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

        // Determine Sedes to process
        // API requires ID (Confirmed by test_sede_api.php: ID 1=807 results, Code CBA=0 results)
        $sedesToProcess = [];

        if ($sedeOption) {
            $sedeObj = \App\Models\Sede::find($sedeOption);
            if ($sedeObj) {
                $sedesToProcess = [$sedeObj];
                $this->info("📍 Procesando Sede: {$sedeObj->nombre} (ID: {$sedeObj->id})");
            } else {
                $this->error("❌ Sede ID {$sedeOption} no encontrada.");
                return 1;
            }
        } else {
            // Fetch all sedes from DB
            $sedesToProcess = \App\Models\Sede::all();
            $this->info("🌍 Procesando TODAS las sedes globalmente (" . count($sedesToProcess) . " sedes).");
        }

        // Obtener lista dinámica de carreras
        $carrerasAConsultar = $this->getCarrerasToSync($carreraFiltro);

        // $this->info("📋 Carreras a procesar: " . count($carrerasAConsultar));

        $docentesUnicos = [];
        $totalRegistros = 0;

        // Loop through Sedes
        foreach ($sedesToProcess as $sedeObj) {
            $this->info("------------------------------------------------");
            $this->info("🏢 Sede: {$sedeObj->nombre} (ID: {$sedeObj->id})");

            $sedeId = $sedeObj->id;

            // Loop through Careers
            foreach ($carrerasAConsultar as $carrera) {
                // $this->line("   📚 Consultando: {$carrera}...");

                try {
                    // USE ID for external API request
                    $response = Http::timeout(60)->get("{$this->baseUrl}/api/Grupos/listar/", [
                        'gestion' => $gestion,
                        'carrera' => $carrera,
                        'sede' => $sedeId
                    ]);

                    if ($response->successful()) {
                        $data = $response->json();
                        $count = count($data);
                        $totalRegistros += $count;

                        if ($count > 0) {
                            $this->line("   ✅ {$carrera}: {$count} registros.");
                        }

                        foreach ($data as $item) {
                            $ci = trim($item['ci']);

                            if (empty($ci) || $ci === '0') continue;

                            if (!isset($docentesUnicos[$ci])) {
                                $docentesUnicos[$ci] = [
                                    'ci' => $ci,
                                    'nombre' => $this->limpiarNombre($item['docente']),
                                    'sede_id' => $sedeId, // Map to correct local Sede ID
                                    'carreras' => [$carrera],
                                    'asignaciones' => []
                                ];
                            } else {
                                if (!in_array($carrera, $docentesUnicos[$ci]['carreras'])) {
                                    $docentesUnicos[$ci]['carreras'][] = $carrera;
                                }
                            }

                            // Capture Assignment Details
                            // We need to link this teacher to: Sede + Asignatura (Sigla) + Grupo (Nombre)
                            $asignacion = [
                                'sede_id' => $sedeId,
                                'sigla' => trim($item['siglaP']), // e.g. SON-115
                                'grupo' => trim($item['grupo']),   // e.g. 1
                                'gestion' => $gestion
                            ];

                            // Avoid duplicates in memory
                            if (!in_array($asignacion, $docentesUnicos[$ci]['asignaciones'])) {
                                $docentesUnicos[$ci]['asignaciones'][] = $asignacion;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->error("   ❌ Error: {$e->getMessage()}");
                }
            }
        }

        $this->newLine();
        $this->info("📊 Registros procesados: {$totalRegistros}");
        $this->info("👨‍🏫 Docentes únicos encontrados: " . count($docentesUnicos));
        $this->newLine();

        if (empty($docentesUnicos)) {
            $this->warn("⚠️ No se encontraron docentes para sincronizar.");
            return 0;
        }

        // Obtener rol DOCENTE
        $rolDocente = Rol::where('nombre', 'DOCENTE')->first();
        if (!$rolDocente) {
            $this->error("❌ No se encontró el rol DOCENTE en la base de datos.");
            return 1;
        }

        // Crear o actualizar docentes
        $creados = 0;
        $actualizados = 0;
        $errores = 0;
        $asignacionesRealizadas = 0;

        $bar = $this->output->createProgressBar(count($docentesUnicos));
        $bar->start();

        foreach ($docentesUnicos as $ci => $docenteData) {
            try {
                // 1. Sync User / Docente
                $user = User::where('ci', $ci)->orWhere('username', $ci)->first();
                $docente = null;

                if ($user) {
                    // Actualizar nombre si cambió
                    $nombreParts = $this->parsearNombre($docenteData['nombre']);
                    $user->update([
                        'nombre' => $nombreParts['nombre'],
                        'apellido' => $nombreParts['apellido'],
                    ]);
                    $actualizados++;
                    $docente = $user->docente; // Retrieve existing docente relation
                } else {
                    // Crear nuevo usuario
                    $nombreParts = $this->parsearNombre($docenteData['nombre']);

                    $user = User::create([
                        'nombre' => $nombreParts['nombre'],
                        'apellido' => $nombreParts['apellido'],
                        'username' => $ci,
                        'ci' => $ci,
                        'email' => $this->generarEmail($docenteData['nombre']),
                        'password' => $ci, // El modelo aplica hash automáticamente
                        'rol_id' => $rolDocente->id,
                        'estado' => true,
                        'password_change_required' => false,
                    ]);

                    $creados++;
                }

                // Ensure Docente property allows access to ID
                // If user exists but has no docente record, create it
                if ($user) {
                    $docente = Docente::updateOrCreate(
                        ['user_id' => $user->id],
                        [
                            'nombre_completo' => $docenteData['nombre'],
                            'sede_id' => $docenteData['sede_id'] ?? 1,
                            'estado' => true,
                        ]
                    );
                }

                // 2. Sync Assignments (Assign Groups)
                if ($docente) {
                    foreach ($docenteData['asignaciones'] as $asignacion) {
                        try {
                            $sigla = $asignacion['sigla'];
                            $grupoNombre = $asignacion['grupo'];
                            $sedeId = $asignacion['sede_id'];
                            $gestion = $asignacion['gestion'];

                            // Find Asignatura by Kode
                            $asignatura = \App\Models\Asignatura::where('codigo', $sigla)->first();

                            if ($asignatura) {
                                // Find Grupo
                                // Match: Sede + Asignatura + Nombre (Group Number) + Gestion
                                $grupo = \App\Models\Grupo::where('sede_id', $sedeId)
                                    ->where('asignatura_id', $asignatura->id)
                                    ->where('nombre', $grupoNombre)
                                    ->where('gestion', $gestion)
                                    ->first();

                                if ($grupo) {
                                    // Assign Docente if not already assigned or different
                                    if ($grupo->docente_id !== $docente->id) {
                                        $grupo->docente_id = $docente->id;
                                        $grupo->save();
                                        $asignacionesRealizadas++;
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            // Log silent error for individual assignment to avoid stopping process
                            // Log::warning("Could not assign group {$asignacion['sigla']}-{$asignacion['grupo']} to {$ci}");
                        }
                    }
                }
            } catch (\Exception $e) {
                $errores++;
                Log::error("Error sincronizando docente CI {$ci}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Resumen
        $this->info("✅ Sincronización completada:");
        $this->line("   - Docentes creados: {$creados}");
        $this->line("   - Docentes actualizados: {$actualizados}");
        $this->line("   - Asignaciones de Grupo (Materia): {$asignacionesRealizadas}");

        if ($errores > 0) {
            $this->warn("   - Errores: {$errores} (ver logs para detalles)");
        }

        return 0;
    }

    /**
     * Limpiar nombre de títulos académicos
     */
    protected function limpiarNombre(string $nombre): string
    {
        $prefijos = [
            'Lic.',
            'Ing.',
            'Dr.',
            'Dra.',
            'Msc.',
            'PhD.',
            'Arq.',
            'Abg.',
            'LIC.',
            'ING.',
            'DR.',
            'DRA.',
            'MSC.',
            'PHD.',
            'ARQ.',
            'ABG.',
            'Lic ',
            'Ing ',
            'Dr ',
            'Dra ',
            'Msc ',
            'PhD ',
            'Arq ',
            'Abg '
        ];

        $nombreLimpio = trim($nombre);
        foreach ($prefijos as $prefijo) {
            if (str_starts_with($nombreLimpio, $prefijo)) {
                $nombreLimpio = trim(substr($nombreLimpio, strlen($prefijo)));
            }
        }

        return $nombreLimpio;
    }

    /**
     * Parsear nombre completo en nombre y apellido
     */
    protected function parsearNombre(string $nombreCompleto): array
    {
        $parts = explode(' ', trim($nombreCompleto));

        if (count($parts) >= 3) {
            // Asumimos: NOMBRE APELLIDO1 APELLIDO2 o NOMBRE1 NOMBRE2 APELLIDO
            $nombre = $parts[0];
            $apellido = implode(' ', array_slice($parts, 1));
        } elseif (count($parts) == 2) {
            $nombre = $parts[0];
            $apellido = $parts[1];
        } else {
            $nombre = $nombreCompleto;
            $apellido = '';
        }

        return [
            'nombre' => $nombre,
            'apellido' => $apellido
        ];
    }

    /**
     * Generar email único basado en nombre
     */
    protected function generarEmail(string $nombre): string
    {
        $base = strtolower(trim($nombre));
        $base = str_replace(' ', '.', $base);
        $base = preg_replace('/[^a-z0-9.]/', '', $base);

        // Verificar si ya existe
        $email = "{$base}@unitepc.edu.bo";
        $contador = 1;

        while (User::where('email', $email)->exists()) {
            $email = "{$base}{$contador}@unitepc.edu.bo";
            $contador++;
        }

        return $email;
    }
}
