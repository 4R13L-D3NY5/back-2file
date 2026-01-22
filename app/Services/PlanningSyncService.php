<?php

namespace App\Services;

use App\DTOs\AcademicDataDTO;
use App\Models\Asignatura;
use App\Models\Aula;
use App\Models\Bloque;
use App\Models\Carrera;
use App\Models\Docente;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Sede;
use App\Models\User;
use App\Models\Rol;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class PlanningSyncService
{
    private const CAREER_NAMES = [
        "CARCCP" => "COMPLEMENTARIA CONTADURÍA PÚBLICA",
        "CARCAD" => "COMPLEMENTARIA EN ADMINISTRACIÓN DE EMPRESAS",
        "CARCPU" => "LICENCIATURA EN CONTADURIA PUBLICA",
        "CARECO" => "LICENCIATURA EN ECONOMÍA",
        "CARICO" => "LICENCIATURA EN INGENIERIA COMERCIAL",
        "CARCIC" => "COMPLEMENTARIA INGENIERÍA COMERCIAL",
        "CARADM" => "LICENCIATURA EN ADMINISTRACIÓN DE EMPRESAS",
        "CARCSO" => "LICENCIATURA EN COMUNICACIÓN SOCIAL",
        "CARAYE" => "LICENCIATURA EN ARTE Y ESCULTURA",
        "CARCNE" => "LICENCIATURA EN CINEMATOGRAFÍA",
        "CARDER" => "LICENCIATURA EN DERECHO",
        "CARELE" => "LICENCIATURA EN INGENIERÍA ELECTRÓNICA",
        "CARSON" => "LICENCIATURA EN INGENIERÍA DE SONIDO",
        "CARSIS" => "LICENCIATURA EN INGENIERÍA DE SISTEMAS",
        "CARIBI" => "LICENCIATURA EN INGENIERÍA BIOMÉDICA",
        "CARVET" => "LICENCIATURA EN MEDICINA VETERINARIA Y ZOOTECNIA",
        "CARBYF" => "LICENCIATURA EN BIOQUIMICA Y FARMACIA",
        "CARNYD" => "LICENCIATURA EN NUTRICION Y DIETETICA",
        "CARODO" => "LICENCIATURA EN ODONTOLOGIA",
        "CARFIS" => "LICENCIATURA EN FISIOTERAPIA Y KINESIOLOGIA",
        "CARFON" => "LICENCIATURA EN FONOAUDIOLOGIA",
        "CARPRO" => "PROTESIS DENTAL",
        "CARENL" => "LICENCIATURA EN ENFERMERIA",
        "CARMED" => "LICENCIATURA EN MEDICINA"
    ];

    public function syncBatch(array $items): array
    {
        $stats = [
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

        // Cache Role ID to avoid query in loop
        $docenteRoleId = Rol::where('codigo', 'DOCENTE')->value('id') ?? 6;

        return DB::transaction(function () use ($items, &$stats, $docenteRoleId) {
            foreach ($items as $rawItem) {
                try {
                    $dto = AcademicDataDTO::fromArray($rawItem);

                    // 1. Sede
                    $sede = Sede::firstOrCreate(
                        ['id' => $dto->idSede],
                        ['nombre' => $dto->nombreSede, 'codigo' => 'SD-' . $dto->idSede]
                    );
                    $stats['sedes']++;

                    // 2. Bloque
                    $bloque = Bloque::firstOrCreate(
                        ['sede_id' => $sede->id, 'nombre' => $dto->nomBloque]
                    );
                    $stats['bloques']++;

                    // 3. Aula
                    $aula = Aula::updateOrCreate(
                        ['bloque_id' => $bloque->id, 'nombre' => $dto->nomAulaLab],
                        ['capacidad' => $dto->capacidadAula, 'pupitres' => $dto->nroPupitres]
                    );
                    $stats['aulas']++;

                    // 4. Carrera (Use Name Map)
                    $carreraNombre = self::CAREER_NAMES[$dto->carrera] ?? $dto->carrera;
                    $carrera = Carrera::firstOrCreate(
                        ['sigla' => $dto->carrera],
                        ['nombre' => $carreraNombre, 'sede_id' => $sede->id]
                    );

                    // Sync Pivot: Attach Sede if not attached
                    if (!$carrera->sedes()->where('sede_id', $sede->id)->exists()) {
                        $carrera->sedes()->attach($sede->id);
                    }
                    $stats['carreras']++;

                    // 5. Asignatura
                    // Matches DTO: siglaP (Code), materia (Name)
                    $asignatura = Asignatura::firstOrCreate(
                        ['codigo' => $dto->siglaP],
                        ['nombre' => $dto->materia]
                    );
                    $stats['asignaturas']++;

                    // SYNC PIVOT ASIGNATURA-CARRERA (Crucial for filters)
                    // Check if already attached in this specific Sede
                    $pivotExists = DB::table('asignatura_carrera')
                        ->where('asignatura_id', $asignatura->id)
                        ->where('carrera_id', $carrera->id)
                        ->where('sede_id', $sede->id)
                        ->exists();

                    if (!$pivotExists) {
                        $asignatura->carreras()->attach($carrera->id, [
                            'sede_id' => $sede->id,
                            'semestre' => $dto->semestre
                        ]);
                    } else {
                        // Update semestre if changed (optional but good for consistency)
                        DB::table('asignatura_carrera')
                            ->where('asignatura_id', $asignatura->id)
                            ->where('carrera_id', $carrera->id)
                            ->where('sede_id', $sede->id)
                            ->update(['semestre' => $dto->semestre, 'updated_at' => now()]);
                    }

                    // 6. Docente & User
                    // Matches DTO: ci (ID), docente (Name)
                    // We don't have codDocente in DTO, using CI as unique identifier
                    $docente = Docente::withTrashed()->updateOrCreate(
                        ['ci' => $dto->ci],
                        [
                            'nombre_completo' => $dto->docente,
                        ]
                    );
                    if ($docente->trashed()) {
                        $docente->restore();
                    }
                    $stats['docentes']++;

                    // Create User for Docente if not exists
                    if (!$docente->user_id && $dto->ci) {
                        $email = strtolower($dto->ci) . '@unitepc.edu.bo';
                        $user = User::firstOrCreate(
                            ['email' => $email],
                            [
                                'name' => $dto->docente,
                                'password' => Hash::make($dto->ci),
                                'username' => $dto->ci,
                                'rol_id' => $docenteRoleId
                            ]
                        );
                        $docente->user_id = $user->id;
                        $docente->save();
                        $stats['users_created']++;
                    }

                    // 7. Grupo
                    // Calculate turno based on hour
                    $hora = (int) substr($dto->horaInicio, 0, 2);
                    $turno = ($hora < 12) ? 'MAÑANA' : (($hora < 18) ? 'TARDE' : 'NOCHE');
                    $tipo = isset($dto->tipoClase) ? strtoupper($dto->tipoClase) : 'TEORICO';

                    // Use firstOrCreate-like logic but with specific keys to prevent duplicates
                    // Constraint: unique(['asignatura_id', 'nombre', 'tipo', 'gestion'])
                    // We move 'docente_id' and 'turno' to attributes to update, not matching criteria.
                    $grupo = Grupo::updateOrCreate(
                        [
                            'gestion' => $dto->gestion,
                            'asignatura_id' => $asignatura->id,
                            'nombre' => $dto->grupo,
                            'tipo' => $tipo
                        ],
                        [
                            'docente_id' => $docente->id,
                            'turno' => $turno,
                            'estado' => 'ACTIVO'
                        ]
                    );
                    $stats['grupos']++;

                    // 8. Horario
                    Horario::updateOrCreate(
                        [
                            'grupo_id' => $grupo->id,
                            'dia' => $dto->dia,
                            'hora_inicio' => $dto->horaInicio,
                            'hora_fin' => $dto->horaFin,
                        ],
                        [
                            'aula_id' => $aula->id
                        ]
                    );
                    $stats['horarios']++;
                } catch (\Exception $e) {
                    Log::error("Planning Sync Error: " . $e->getMessage());
                    $stats['errors']++;
                }
            }

            return $stats;
        });
    }
}
