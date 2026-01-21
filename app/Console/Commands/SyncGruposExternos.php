<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Sede;
use App\Models\Carrera;
use App\Models\Asignatura;
use App\Models\Docente;
use App\Models\User;
use App\Services\GruposExternoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SyncGruposExternos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:grupos-externos {gestion=1-2026}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza grupos, horarios y docentes desde la API Externa para todas las sedes y carreras';

    protected $gruposService;

    public function __construct(GruposExternoService $gruposService)
    {
        parent::__construct();
        $this->gruposService = $gruposService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $gestion = $this->argument('gestion');
        $this->info("Iniciando sincronización masiva para gestión: $gestion");

        // 1. Obtener Sedes Activas y con ID API válido
        $sedes = Sede::where('activo', true)
            ->whereNotNull('id_api')
            ->get();

        $this->info("Se encontraron {$sedes->count()} sedes con configuración API válida.");

        foreach ($sedes as $sede) {
            $this->info("--------------------------------------------------");
            $this->info("Procesando Sede: {$sede->nombre} (ID API: {$sede->id_api})");

            $carreras = $sede->carreras()->where('activo', true)->get();

            if ($carreras->isEmpty()) {
                $this->warn(" -> No hay carreras activas en la BD para esta sede.");
                continue;
            }

            foreach ($carreras as $carrera) {
                // $this->line(" -> Carrera: {$carrera->nombre} ({$carrera->codigo})");

                try {
                    // Consumir servicio
                    $materias = $this->gruposService->listarGrupos($gestion, $carrera->codigo, $sede->id_api);

                    if (empty($materias)) {
                        // $this->line("    (Sin grupos externos)");
                        continue;
                    }

                    $totalGrupos = 0;
                    foreach ($materias as $materiaData) {
                        $totalGrupos += count($materiaData['grupos']);
                        $this->procesarMateria($materiaData, $carrera, $sede);
                    }

                    $this->info(" -> Carrera: {$carrera->codigo} | Materias: " . count($materias) . " | Grupos: $totalGrupos");
                } catch (\Exception $e) {
                    $this->error("Error procesando {$carrera->codigo}: " . $e->getMessage());
                    dump($e->getMessage()); // VER EL ERROR COMPLETO
                }
            }
        }

        $this->info("--------------------------------------------------");
        $this->info("Sincronización Finalizada Exitosamente.");
    }

    private function procesarMateria($materiaData, $carrera, $sede)
    {
        // 1. Buscar o Crear Asignatura
        $asignatura = Asignatura::firstOrCreate(
            [
                'codigo' => trim($materiaData['codigo']),
                'carrera_id' => $carrera->id
            ],
            [
                'nombre' => trim($materiaData['nombre']),
                'semestre' => $materiaData['semestre'],
                // Valores por defecto
                'creditos' => 0,
                'area_desempenio' => 'General',
                'tipo_curso' => 'Teórico',
                'modalidad' => 'Presencial',
                'carga_horaria_total' => 0,
                'horas_teoricas' => 0,
                'horas_practicas' => 0
            ]
        );

        // 2. Procesar Grupos
        // Estrategia: "Upsert" en tabla pivote 'asignatura_docente'
        // PERO 'asignatura_docente' no tiene ID único fácil.
        // Lo mejor es borrar los grupos de esta materia antes de insertar los nuevos para evitar duplicados,
        // O intentar buscar si ya existe el grupo.

        // Vamos a iterar y hacer firstOrCreate de la relación si es posible,
        // o usar DB query directo para mayor control sobre campos extra pivot.

        foreach ($materiaData['grupos'] as $grupoData) {
            $docente = $this->procesarDocente($grupoData, $sede);

            // Verificar si ya existe este grupo (mismo docente, materia y numero de grupo)
            // La tabla pivote es: asignatura_id, docente_id, grupo, aula, horario...

            $exists = DB::table('asignatura_docente')
                ->where('asignatura_id', $asignatura->id)
                ->where('docente_id', $docente->id)
                ->where('grupo', $grupoData['grupo'])
                ->exists();

            if ($exists) {
                // Actualizar
                DB::table('asignatura_docente')
                    ->where('asignatura_id', $asignatura->id)
                    ->where('docente_id', $docente->id)
                    ->where('grupo', $grupoData['grupo'])
                    ->update([
                        'aula' => $grupoData['aula'],
                        // Combinar dia/horas en texto legible
                        'horario' => "{$grupoData['dia']} {$grupoData['hora_inicio']}-{$grupoData['hora_fin']}",
                        'cupo' => $grupoData['capacidad'],
                        'estudiantes_inscritos' => 0, // No tenemos info de inscritos real
                        'updated_at' => now()
                    ]);
            } else {
                // Insertar
                DB::table('asignatura_docente')->insert([
                    'asignatura_id' => $asignatura->id,
                    'docente_id' => $docente->id,
                    'grupo' => $grupoData['grupo'],
                    'aula' => $grupoData['aula'],
                    'horario' => "{$grupoData['dia']} {$grupoData['hora_inicio']}-{$grupoData['hora_fin']}",
                    'cupo' => $grupoData['capacidad'],
                    'estudiantes_inscritos' => 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
        }
    }

    private function procesarDocente($grupoData, $sede)
    {
        // El servicio devuelve 'docente' (Nombre) y 'docente_ci'.
        $ci = trim($grupoData['docente_ci']);
        $nombreCompleto = $this->limpiarNombre(trim($grupoData['docente']));

        // Si no hay CI, generar uno ficticio basado en nombre (caso raro)
        if (empty($ci)) {
            $ci = 'GEN-' . Str::slug($nombreCompleto);
        }

        // 1. Buscar Usuario por CI
        $user = User::where('ci', $ci)->first();

        if (!$user) {
            // Crear Usuario
            // Asumimos rol_id = 2 (Docente) - Ajustar según tu tabla roles
            $nombres = $nombreCompleto;
            $apellidos = '';

            // Intentar separar nombre/apellido simple
            $parts = explode(' ', $nombreCompleto);
            if (count($parts) > 2) {
                $apellidos = array_pop($parts) . ' ' . array_pop($parts);
                $nombres = implode(' ', $parts);
            }

            $user = User::create([
                'username' => $ci,
                'email' => $ci . '@unitepc.edu.bo', // Email dummy
                'password' => Hash::make($ci), // Password es su CI
                'estado' => true,
                'rol_id' => 6, // DOCENTE
                'nombre' => $nombres,
                'apellido' => $apellidos,
                'ci' => $ci,
                'telefono' => '',
                'carrera' => '',
                'password_change_required' => true,
            ]);
        }

        // 2. Buscar o Crear Docente vinculado
        $docente = Docente::where('user_id', $user->id)->first();

        if (!$docente) {
            $docente = Docente::create([
                'nombre_completo' => $nombreCompleto,
                'user_id' => $user->id,
                'email' => $user->email,
                'sede_id' => $sede->id,
                'estado' => true,
                // Campos opcionales dummy
                'celular' => '',
                'especialidad' => 'Docente Pregrado',
                'grado_academico' => '', // Dejar vacío como solicita el usuario
                'tipo_dedicacion' => 'Tiempo Parcial'
            ]);
        }

        return $docente;
    }

    private function limpiarNombre($nombre)
    {
        // Lista de prefijos a eliminar (con y sin punto, mayus/minus)
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

        $nombreLimpio = $nombre;
        foreach ($prefijos as $prefijo) {
            if (str_starts_with($nombreLimpio, $prefijo)) {
                $nombreLimpio = trim(substr($nombreLimpio, strlen($prefijo)));
            }
        }

        return $nombreLimpio;
    }
}
