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

                    // 4. Carrera (Dynamic Lookup)
                    // First try to find existing career (synced from University API)
                    $carrera = Carrera::where('sigla', $dto->carrera)->first();

                    if (!$carrera) {
                        // Fallback: Create dynamic career using code if not found
                        $carrera = Carrera::create([
                            'sigla' => $dto->carrera,
                            'nombre' => $dto->carrera, // Temporary name until full sync
                            'sede_id' => $sede->id
                        ]);
                    }

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

                    // BLACKLIST: Skip specifically requested careers (e.g. Psychology)
                    $blockedCareers = ['CARPSI', 'CARPSI-SEM'];
                    if (in_array($dto->carrera, $blockedCareers)) {
                        // Log::info("Skipping blocked career: {$dto->carrera}");
                        continue;
                    }

                    // DATA QUALITY FILTER: Skip if docente name is invalid
                    if (empty($dto->docente) || trim($dto->docente) === '' || stripos($dto->docente, 'Sin Asignar') !== false) {
                        $stats['errors']++; // Track skipped items
                        Log::warning("Skipping Group Sync: Invalid Docente Name '{$dto->docente}' for CI {$dto->ci}");
                        continue; // Skip this item entirely (don't create group either)
                    }

                    $docenteNombre = $dto->docente ?: 'Docente ' . $dto->ci;

                    $docente = Docente::withTrashed()->updateOrCreate(
                        ['ci' => $dto->ci],
                        [
                            'nombre_completo' => $docenteNombre,
                            'sede_id' => $sede->id, // Asignación explícita de Sede
                        ]
                    );

                    if ($docente->trashed()) {
                        $docente->restore();
                    }
                    $stats['docentes']++;

                    // Create User for Docente if not exists or if checking users
                    if ($dto->ci && !$docente->user_id) {
                        try {
                            // Ensure Unique Username (CI)
                            $user = User::where('username', $dto->ci)->first();

                            if (!$user) {
                                // Split Name into Nombre/Apellido
                                $parts = explode(' ', $docenteNombre, 2);
                                $nombre = $parts[0] ?? $docenteNombre;
                                $apellido = $parts[1] ?? 'Doe';

                                // Create new User with EXTENDED fields
                                $user = User::create([
                                    // 'name' column does not exist in DB, using nombre/apellido below
                                    'email' => strtolower($dto->ci) . '@unitepc.edu.bo', // Dummy email based on CI
                                    'username' => $dto->ci,
                                    'password' => Hash::make($dto->ci), // Def pw: CI
                                    'rol_id' => $docenteRoleId,
                                    'estado' => 1, // 1 = ACTIVO
                                    'password_change_required' => false,
                                    // Required Extra Fields
                                    'nombre' => $nombre,
                                    'apellido' => $apellido,
                                    'ci' => $dto->ci,
                                    'carrera' => $dto->carrera,
                                    'telefono' => $dto->celular ?? ''
                                ]);
                                $stats['users_created']++;
                            }

                            // Link Docente -> User
                            $docente->user_id = $user->id;
                            $docente->save();
                        } catch (\Exception $e) {
                            Log::error("Failed to create/link user for Docente CI {$dto->ci}: " . $e->getMessage());
                            $stats['last_error'] = $e->getMessage();
                        }
                    }

                    // 7. Grupo
                    // Calculate turno based on hour
                    $hora = (int) substr($dto->horaInicio, 0, 2);
                    $turno = ($hora < 12) ? 'MAÑANA' : (($hora < 18) ? 'TARDE' : 'NOCHE');
                    $tipo = isset($dto->tipoClase) ? strtoupper($dto->tipoClase) : 'TEORICO';

                    // FIX: Prevent overwriting docente_id if group exists
                    // We identify the group strictly by its logical keys
                    $existingGrupo = Grupo::where([
                        'gestion' => $dto->gestion,
                        'asignatura_id' => $asignatura->id,
                        'nombre' => $dto->grupo,
                        'tipo' => $tipo
                    ])->first();

                    if ($existingGrupo) {
                        // UPDATE PATH: Conservative
                        // We do NOT update 'docente_id' to prevent API inconsistencies overwriting valid data
                        $grupo = $existingGrupo;
                        $grupo->update([
                            'sede_id' => $sede->id,
                            'turno' => $turno,
                            'estado' => 'ACTIVO'
                        ]);
                    } else {
                        // CREATE PATH: Full trust on first sync
                        $grupo = Grupo::create([
                            'gestion' => $dto->gestion,
                            'asignatura_id' => $asignatura->id,
                            'nombre' => $dto->grupo,
                            'tipo' => $tipo,
                            'docente_id' => $docente->id, // Assign only on creation
                            'sede_id' => $sede->id,
                            'turno' => $turno,
                            'estado' => 'ACTIVO'
                        ]);
                    }
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
