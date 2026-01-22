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
                            {--sede=1 : ID de sede}
                            {--carrera= : Carrera específica (opcional, por defecto todas)}';

    protected $description = 'Sincroniza docentes desde la API externa de UNITEPC a la base de datos local';

    protected string $baseUrl = 'http://181.188.185.211:9098';
    
    protected array $carreras = [
        'carmed', 'carsis', 'carelec', 'carbio', 'carodon', 'carfis', 
        'carnut', 'carkin', 'carfarm', 'carlab', 'carpsico', 'carder', 
        'caradm', 'carcont', 'carcivil', 'carind'
    ];

    public function handle()
    {
        $gestion = $this->option('gestion');
        $sede = $this->option('sede');
        $carreraFiltro = $this->option('carrera');

        $this->info("🔄 Sincronizando docentes desde API externa...");
        $this->info("   Gestión: {$gestion} | Sede: {$sede}");

        // Si se especificó una carrera, solo usar esa
        $carrerasAConsultar = $carreraFiltro ? [$carreraFiltro] : $this->carreras;

        $docentesUnicos = [];
        $totalRegistros = 0;

        // Obtener docentes de todas las carreras
        foreach ($carrerasAConsultar as $carrera) {
            $this->line("   📚 Consultando carrera: {$carrera}...");
            
            try {
                $response = Http::timeout(60)->get("{$this->baseUrl}/api/Grupos/listar/", [
                    'gestion' => $gestion,
                    'carrera' => $carrera,
                    'sede' => $sede
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $totalRegistros += count($data);

                    foreach ($data as $item) {
                        $ci = trim($item['ci']);
                        
                        // Ignorar CIs inválidos
                        if (empty($ci) || $ci === '0') {
                            continue;
                        }

                        // Si es un nuevo docente, agregarlo
                        if (!isset($docentesUnicos[$ci])) {
                            $docentesUnicos[$ci] = [
                                'ci' => $ci,
                                'nombre' => $this->limpiarNombre($item['docente']),
                                'sede_id' => $item['idSede'],
                                'carreras' => [$carrera],
                                'materias' => []
                            ];
                        } else {
                            // Agregar carrera si no está
                            if (!in_array($carrera, $docentesUnicos[$ci]['carreras'])) {
                                $docentesUnicos[$ci]['carreras'][] = $carrera;
                            }
                        }

                        // Agregar materia
                        $materiaKey = $item['siglaP'];
                        if (!isset($docentesUnicos[$ci]['materias'][$materiaKey])) {
                            $docentesUnicos[$ci]['materias'][$materiaKey] = $item['materia'];
                        }
                    }
                } else {
                    $this->warn("   ⚠️ Error en carrera {$carrera}: HTTP {$response->status()}");
                }
            } catch (\Exception $e) {
                $this->error("   ❌ Error en carrera {$carrera}: {$e->getMessage()}");
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

        $bar = $this->output->createProgressBar(count($docentesUnicos));
        $bar->start();

        foreach ($docentesUnicos as $ci => $docenteData) {
            try {
                // Buscar usuario existente
                $user = User::where('ci', $ci)->orWhere('username', $ci)->first();

                if ($user) {
                    // Actualizar nombre si cambió
                    $nombreParts = $this->parsearNombre($docenteData['nombre']);
                    $user->update([
                        'nombre' => $nombreParts['nombre'],
                        'apellido' => $nombreParts['apellido'],
                    ]);
                    $actualizados++;
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
                    ]);

                    // Crear registro de docente
                    Docente::updateOrCreate(
                        ['user_id' => $user->id],
                        [
                            'nombre_completo' => $docenteData['nombre'],
                            'sede_id' => $docenteData['sede_id'] ?? 1,
                            'estado' => true,
                        ]
                    );

                    $creados++;
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
            'Lic.', 'Ing.', 'Dr.', 'Dra.', 'Msc.', 'PhD.', 'Arq.', 'Abg.',
            'LIC.', 'ING.', 'DR.', 'DRA.', 'MSC.', 'PHD.', 'ARQ.', 'ABG.',
            'Lic ', 'Ing ', 'Dr ', 'Dra ', 'Msc ', 'PhD ', 'Arq ', 'Abg '
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
