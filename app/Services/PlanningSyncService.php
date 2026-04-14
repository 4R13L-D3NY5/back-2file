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

        $processedGroups = [];
        $horariosByGroup = []; // Track valid schedule IDs per group

        return DB::transaction(function () use ($items, &$stats, $docenteRoleId, &$processedGroups, &$horariosByGroup) {
            foreach ($items as $rawItem) {
                try {
                    $dto = AcademicDataDTO::fromArray($rawItem);

                    // Requerimiento: omitir asignaturas donde plan_estudios sea null o vacío
                    if (empty($dto->planEst)) {
                        continue;
                    }

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
                    // BÚSQUEDA POR CÓDIGO + PLAN
                    // ENF-111 Plan N y ENF-111 Plan A son asignaturas distintas con el mismo código.
                    // Se distinguen por el campo plan_estudios, no por el código.
                    $planBuscar = $dto->planEst ?: 'N';

                    $asignatura = Asignatura::withTrashed()
                        ->where('codigo', $dto->siglaP)
                        ->where('plan_estudios', $planBuscar)
                        ->first();

                    if ($asignatura) {
                        // Actualizar nombre solo si es muy similar (corrección ortográfica)
                        // para no sobreescribir con otro nombre completamente diferente
                        similar_text(strtoupper($asignatura->nombre), strtoupper($dto->materia), $namePct);
                        if ($namePct > 70) {
                            $asignatura->nombre = $dto->materia ?: $asignatura->nombre;
                        }
                        $asignatura->save();
                        $asignatura->restore();
                    } else {
                        // Crear registro nuevo para este código+plan si no existe
                        $asignatura = Asignatura::create([
                            'codigo'        => $dto->siglaP,
                            'plan_estudios' => $planBuscar,
                            'nombre'        => $dto->materia ?: 'Asignatura ' . $dto->siglaP,
                        ]);
                    }
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
                        // Update timestamp but NOT semester (Malla is the source of truth)
                        DB::table('asignatura_carrera')
                            ->where('asignatura_id', $asignatura->id)
                            ->where('carrera_id', $carrera->id)
                            ->where('sede_id', $sede->id)
                            ->update(['updated_at' => now()]);
                    }

                    // 6. Docente & User
                    // Matches DTO: ci (ID), docente (Name)

                    // BLACKLIST: Skip specifically requested careers (e.g. Psychology)
                    $blockedCareers = ['CARPSI', 'CARPSI-SEM'];
                    if (in_array($dto->carrera, $blockedCareers)) {
                        // Log::info("Skipping blocked career: {$dto->carrera}");
                        continue;
                    }

                    // DATA QUALITY FILTER: Handle 'Sin Asignar' or empty docente name
                    $isValidDocente = !empty($dto->docente) && trim($dto->docente) !== '' && stripos($dto->docente, 'Sin Asignar') === false;
                    $docenteId = null;

                    if ($isValidDocente) {
                        $docenteNombre = $dto->docente ?: 'Docente ' . $dto->ci;

                        // BUSQUEDA ROBUSTA DE DOCENTE
                        // 1. Intentar por CI directo
                        $docente = Docente::withTrashed()->where('ci', $dto->ci)->first();

                        if (!$docente && $dto->ci) {
                            // 2. Intentar encontrar un usuario que tenga este CI como username
                            $userMatches = User::where('username', $dto->ci)->first();
                            if ($userMatches) {
                                // Si el usuario existe, buscamos el docente vinculado a él
                                $docente = Docente::withTrashed()->where('user_id', $userMatches->id)->first();
                                if ($docente) {
                                    // Asegurar que tenga el CI puesto
                                    $docente->ci = $dto->ci;
                                    $docente->save();
                                }
                            }
                        }

                        if (!$docente) {
                            $docente = new Docente(['ci' => $dto->ci]);
                        }

                        $docente->nombre_completo = $docenteNombre;
                        // Only set Sede if it's new or has no sede assignment
                        if (!$docente->exists || !$docente->sede_id) {
                            $docente->sede_id = $sede->id;
                        }
                        $docente->save();

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
                        $docenteId = $docente->id;
                    } else {
                        // Log::warning("Group has no assigned docente: {$dto->materia} ({$dto->grupo})");
                    }

                    // 7. Grupo
                    // Calculate turno based on hour
                    $hora = (int) substr($dto->horaInicio, 0, 2);
                    $turno = ($hora < 12) ? 'MAÑANA' : (($hora < 18) ? 'TARDE' : 'NOCHE');
                    
                    // NORMALIZACIÓN: Mapear 'REGULAR' a 'TEORICO' para evitar duplicados enviados por la API
                    $tipoCrudo = isset($dto->tipoClase) ? strtoupper(trim($dto->tipoClase)) : 'TEORICO';
                    $tipo = ($tipoCrudo === 'REGULAR') ? 'TEORICO' : $tipoCrudo;

                    // 7. GRUPO: Identificación puramente LOGICA

                    // MIGRATION FIX: Check for legacy group (null carrera_id) matching other criteria
                    // This prevents "Duplicate Entry" errors if a unique index exists on (gestion, asignatura, nombre...)
                    $legacyGrupo = Grupo::where([
                        'gestion' => $dto->gestion,
                        'asignatura_id' => $asignatura->id,
                        'carrera_id' => null, // Legacy has no career
                        'nombre' => $dto->grupo,
                        'tipo' => $tipo,
                        'sede_id' => $sede->id
                    ])->first();

                    if ($legacyGrupo) {
                        // "Claim" this group for the current career
                        $legacyGrupo->update(['carrera_id' => $carrera->id]);
                    }

                    // Un grupo es el mismo si tiene la misma gestión, asignatura, carrera, nombre, tipo y sede
                    $grupo = Grupo::updateOrCreate(
                        [
                            'gestion' => $dto->gestion,
                            'asignatura_id' => $asignatura->id,
                            'carrera_id' => $carrera->id,
                            'nombre' => $dto->grupo,
                            'tipo' => $tipo,
                            'sede_id' => $sede->id
                        ],
                        [
                            'docente_id'    => $docenteId,
                            'estado'        => 'ACTIVO'
                        ]
                    );

                    $stats['grupos']++;

                    // CLEANUP: Si es la primera vez que vemos este grupo en este lote,
                    // borramos horarios que NO tengan id_horario_api (legacy)
                    // o preparamos para refrescar sesiones.
                    if (!in_array($grupo->id, $processedGroups)) {
                        // Opcional: Podríamos marcar para borrar los que no vengan en este lote
                        // Por ahora, para asegurar limpieza tras el cambio estructural:
                        $grupo->horarios()->whereNull('id_horario_api')->delete();
                        $processedGroups[] = $grupo->id;
                    }

                    // 8. HORARIO (SESIÓN): Identificación por ID único de API
                    // Esto permite que el Grupo "A" tenga N sesiones sin duplicar el grupo.
                    if ($dto->idHorario) {
                        $horario = Horario::updateOrCreate(
                            [
                                'id_horario_api' => $dto->idHorario,
                            ],
                            [
                                'grupo_id' => $grupo->id,
                                'aula_id' => $aula->id,
                                'dia' => strtoupper($dto->dia),
                                'hora_inicio' => $dto->horaInicio,
                                'hora_fin' => $dto->horaFin,
                            ]
                        );
                        $horariosByGroup[$grupo->id][] = $horario->id;
                    } else {
                        // Fallback para APIs sin ID (vínculo por contenido)
                        $horario = Horario::updateOrCreate(
                            [
                                'grupo_id' => $grupo->id,
                                'dia' => strtoupper($dto->dia),
                                'hora_inicio' => $dto->horaInicio,
                            ],
                            [
                                'aula_id' => $aula->id,
                                'hora_fin' => $dto->horaFin,
                            ]
                        );
                        $horariosByGroup[$grupo->id][] = $horario->id;
                    }
                    $stats['horarios']++;
                    $stats['horarios']++;
                } catch (\Exception $e) {
                    Log::error("Planning Sync Error: " . $e->getMessage());
                    $stats['errors']++;
                }
            }

            // CLEANUP PHASE: Remove outdated schedules for processed groups
            // Only affects groups that we actually touched in this batch.
            // If a schedule was NOT in the DTOs for a group, it means it was removed in the API/Source.
            foreach ($processedGroups as $groupId) {
                if (isset($horariosByGroup[$groupId])) {
                    $validIds = $horariosByGroup[$groupId];
                    // Delete schedules for this group that are NOT in the valid list
                    // AND track how many were deleted for stats/logs if needed
                    Horario::where('grupo_id', $groupId)
                        ->whereNotIn('id', $validIds)
                        ->delete();
                } else {
                     // If for some reason we processed the group but tracked no schedules (e.g. empty list in API),
                     // we should probably clear all schedules for it?
                     // Verify if $horariosByGroup would be set if loop ran.
                     // The loop sets it if $dto->idHorario exists or fallback created one.
                     // If we are here, it means we touched the group.
                     // Let's assume safe to clear if we tracked explicitly.
                     // But to be safe vs legacy/fallback interaction, checking empty usually implies delete all.
                     Horario::where('grupo_id', $groupId)->delete();
                }
            }
            
            return $stats;
        });
    }
}
