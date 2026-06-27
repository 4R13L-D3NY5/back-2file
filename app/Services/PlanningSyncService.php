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
use App\Services\ContentMigrationService;
use Illuminate\Support\Facades\DB;
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

                    // FIX CRITICO: Consolidar asignaturas duplicadas del mismo código
                    // antes de buscar/crear, para evitar grupos huérfanos en duplicados
                    $this->consolidarAsignaturasDuplicadas($dto->siglaP, $planBuscar);

                    $asignatura = Asignatura::withTrashed()
                        ->where('codigo', $dto->siglaP)
                        ->where('plan_estudios', $planBuscar)
                        ->first();

                    if ($asignatura) {
                        // Actualizar nombre solo si es muy similar (corrección ortográfica)
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
                                        'password' => $dto->ci, // cast 'hashed' del modelo lo hashea automaticamente
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
                    // withTrashed: necesitamos encontrar cualquier grupo (activo, inactivo o soft-deleted)
                    $legacyGrupo = Grupo::withoutGlobalScope('activo')->withTrashed()->where([
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
                    // withTrashed: buscar también grupos ELIMINADOS (soft-deletes) para restaurarlos
                    $grupo = Grupo::withoutGlobalScope('activo')->withTrashed()->firstOrNew(
                        [
                            'gestion' => $dto->gestion,
                            'asignatura_id' => $asignatura->id,
                            'carrera_id' => $carrera->id,
                            'nombre' => $dto->grupo,
                            'tipo' => $tipo,
                            'sede_id' => $sede->id
                        ]
                    );

                    // Restaurar si estaba soft-deleted
                    if ($grupo->trashed()) {
                        $grupo->restore();
                    }

                    $grupo->fill([
                        'docente_id' => $docenteId,
                        'estado'     => 'ACTIVO',
                    ]);
                    $grupo->save();

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
                        $horario = Horario::withTrashed()->firstOrNew(
                            ['id_horario_api' => $dto->idHorario]
                        );
                        if ($horario->trashed()) {
                            $horario->restore();
                        }
                        $horario->fill([
                            'grupo_id' => $grupo->id,
                            'aula_id' => $aula->id,
                            'dia' => strtoupper($dto->dia),
                            'hora_inicio' => $dto->horaInicio,
                            'hora_fin' => $dto->horaFin,
                        ]);
                        $horario->save();
                        $horariosByGroup[$grupo->id][] = $horario->id;
                    } else {
                        // Fallback para APIs sin ID (vínculo por contenido)
                        $horario = Horario::withTrashed()->firstOrNew([
                            'grupo_id' => $grupo->id,
                            'dia' => strtoupper($dto->dia),
                            'hora_inicio' => $dto->horaInicio,
                        ]);
                        if ($horario->trashed()) {
                            $horario->restore();
                        }
                        $horario->fill([
                            'aula_id' => $aula->id,
                            'hora_fin' => $dto->horaFin,
                        ]);
                        $horario->save();
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

    // =========================================================================
    // RECONCILIACIÓN POST-SYNC
    // =========================================================================
    // Este método SOLO es llamado desde SyncController (sync manual del admin).
    // NO se llama desde el scheduler, jobs ni comandos artisan.
    // Garantiza que syncBatch() permanece intacto y sin efectos secundarios.
    // =========================================================================

    /**
     * Fase de reconciliación post-sync por carrera/sede/gestión.
     *
     * Acciones:
     *  A) Inactivar grupos que ya no existen en la API para esta carrera/sede/gestión
     *  B) Detectar y fusionar asignaturas duplicadas (migrando banco de preguntas)
     *  C) Desvincular asignaturas huérfanas del pivot carrera/sede
     *  D) Registrar conflictos en grupos modificados localmente
     *
     * @param int    $carreraId  ID de la carrera local
     * @param int    $sedeId     ID de la sede local
     * @param string $gestion    Gestión académica (ej: "1-2026")
     * @param array  $apiItems   Items crudos devueltos por la API Planning
     * @return array Resultado detallado para el diff
     */
    public function reconcile(int $carreraId, int $sedeId, string $gestion, array $apiItems): array
    {
        $resultado = [
            'grupos_inactivados'       => [],
            'asignaturas_desvinculadas'=> [],
            'duplicados_fusionados'    => [],
            'conflictos_locales'       => [],
        ];

        return DB::transaction(function () use ($carreraId, $sedeId, $gestion, $apiItems, &$resultado) {

            // ── A) Construir el conjunto de grupos que la API dice que existen ──────
            // Extraemos las identidades (asignatura_codigo+plan, nombre, tipo) de los items de la API
            $gruposEnApi = collect($apiItems)->map(function ($item) {
                $dto  = AcademicDataDTO::fromArray($item);
                $plan = $dto->planEst ?: 'N';
                $tipo = isset($dto->tipoClase)
                    ? ((strtoupper(trim($dto->tipoClase)) === 'REGULAR') ? 'TEORICO' : strtoupper(trim($dto->tipoClase)))
                    : 'TEORICO';
                return [
                    'sigla'   => $dto->siglaP,
                    'plan'    => $plan,
                    'nombre'  => $dto->grupo,
                    'tipo'    => $tipo,
                    'gestion' => $dto->gestion,
                ];
            })->unique(fn($g) => "{$g['sigla']}|{$g['plan']}|{$g['nombre']}|{$g['tipo']}");

            // Obtener asignaturas procesadas (por código+plan) para esta carrera/sede
            $asignaturasProcesadas = Asignatura::withoutGlobalScopes()
                ->whereIn(DB::raw("CONCAT(codigo, '|', COALESCE(plan_estudios,'N'))"),
                    $gruposEnApi->pluck('sigla')->map(fn($s) => $s . '|' . 'N')->toArray()
                )
                ->orWhere(function ($q) use ($gruposEnApi) {
                    foreach ($gruposEnApi->unique('sigla') as $g) {
                        $q->orWhere(function ($sub) use ($g) {
                            $sub->where('codigo', $g['sigla'])
                                ->where('plan_estudios', $g['plan']);
                        });
                    }
                })
                ->pluck('id')
                ->toArray();

            // ── B) Inactivar grupos obsoletos ────────────────────────────────────────
            // Un grupo es obsoleto si:
            //   - Está en BD para esta carrera/sede/gestión como ACTIVO
            //   - Su asignatura.codigo+plan + nombre + tipo NO aparece en los apiItems
            $gruposBD = Grupo::withoutGlobalScope('activo')
                ->with('asignatura')
                ->where('carrera_id', $carreraId)
                ->where('sede_id', $sedeId)
                ->where('gestion', $gestion)
                ->where('estado', 'ACTIVO')
                ->whereNull('deleted_at')
                ->get();

            foreach ($gruposBD as $grupo) {
                if (!$grupo->asignatura) continue;

                $estaEnApi = $gruposEnApi->first(fn($g) =>
                    $g['sigla']  === $grupo->asignatura->codigo &&
                    ($g['plan']  === ($grupo->asignatura->plan_estudios ?? 'N')) &&
                    $g['nombre'] === $grupo->nombre &&
                    $g['tipo']   === $grupo->tipo
                );

                if (!$estaEnApi) {
                    $grupo->estado = 'INACTIVO';
                    $grupo->save();

                    $resultado['grupos_inactivados'][] = [
                        'id'         => $grupo->id,
                        'grupo'      => $grupo->nombre . ' (' . $grupo->tipo . ')',
                        'asignatura' => $grupo->asignatura->nombre,
                        'codigo'     => $grupo->asignatura->codigo,
                    ];
                }
            }

            // ── C) Detectar y fusionar duplicados de asignaturas ─────────────────────
            // Un duplicado es una asignatura vinculada a esta carrera/sede que tiene el
            // MISMO código con diferente plan_estudios (ej: ENF-111 Plan A y ENF-111 Plan N).
            // NUNCA se fusionan códigos diferentes aunque tengan nombres parecidos.
            // La "correcta" es la que tiene plan_estudios = 'N' (Plan Nuevo, la de la API).
            $asignaturasEnCarrera = Asignatura::withoutGlobalScopes()
                ->whereHas('carreras', fn($q) =>
                    $q->where('carreras.id', $carreraId)
                      ->where('asignatura_carrera.sede_id', $sedeId)
                )
                ->whereNull('deleted_at')
                ->get();

            $migrationService = app(ContentMigrationService::class);
            $fusionadas = collect(); // IDs ya procesados para no procesar dos veces

            foreach ($asignaturasEnCarrera as $asignatura) {
                if ($fusionadas->contains($asignatura->id)) continue;

                // Buscar duplicados SOLO por mismo código con diferente plan
                $duplicados = $asignaturasEnCarrera->filter(fn($a) =>
                    $a->id !== $asignatura->id &&
                    !$fusionadas->contains($a->id) &&
                    $a->codigo === $asignatura->codigo &&
                    $a->plan_estudios !== $asignatura->plan_estudios
                );

                foreach ($duplicados as $duplicado) {
                    if ($fusionadas->contains($duplicado->id)) continue;

                    // La "correcta" es la que tiene plan N (de la API) o la que tiene mayor score
                    $correcta  = ($asignatura->plan_estudios === 'N') ? $asignatura : $duplicado;
                    $duplicadaA = ($correcta->id === $asignatura->id) ? $duplicado : $asignatura;

                    try {
                        // Migrar banco de preguntas y documentación
                        $detalles = $migrationService->fusionarDuplicados($correcta, $duplicadaA);

                        // Reasignar grupos de la duplicada a la correcta
                        Grupo::withoutGlobalScope('activo')
                            ->where('asignatura_id', $duplicadaA->id)
                            ->where('carrera_id', $carreraId)
                            ->where('sede_id', $sedeId)
                            ->update(['asignatura_id' => $correcta->id]);

                        // Desvincular la duplicada del pivot de esta carrera/sede
                        DB::table('asignatura_carrera')
                            ->where('asignatura_id', $duplicadaA->id)
                            ->where('carrera_id', $carreraId)
                            ->where('sede_id', $sedeId)
                            ->delete();

                        $resultado['duplicados_fusionados'][] = array_merge($detalles, [
                            'correcta_plan'  => $correcta->plan_estudios,
                            'duplicada_plan' => $duplicadaA->plan_estudios,
                        ]);

                        $fusionadas->push($duplicadaA->id);

                        Log::info("PlanningSyncService::reconcile - duplicado fusionado", [
                            'correcta_id'  => $correcta->id,
                            'duplicada_id' => $duplicadaA->id,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error("PlanningSyncService::reconcile - error fusionando duplicado", [
                            'correcta_id'  => $correcta->id,
                            'duplicada_id' => $duplicadaA->id,
                            'error'        => $e->getMessage(),
                        ]);
                    }
                }

                $fusionadas->push($asignatura->id);
            }

            // ── D) Desvincular asignaturas huérfanas ─────────────────────────────────
            // Una asignatura es huérfana en esta carrera/sede si no tiene ningún grupo
            // ACTIVO en esta gestión (y no es una asignatura que acabamos de procesar).
            $asignaturasConGrupoActivo = Grupo::withoutGlobalScope('activo')
                ->where('carrera_id', $carreraId)
                ->where('sede_id', $sedeId)
                ->where('gestion', $gestion)
                ->where('estado', 'ACTIVO')
                ->whereNull('deleted_at')
                ->pluck('asignatura_id')
                ->unique()
                ->toArray();

            $asignaturasEnPivot = DB::table('asignatura_carrera')
                ->where('carrera_id', $carreraId)
                ->where('sede_id', $sedeId)
                ->pluck('asignatura_id')
                ->toArray();

            foreach ($asignaturasEnPivot as $asigId) {
                // Si ya fue fusionada (el ID es de la duplicada), no procesar
                if ($fusionadas->contains($asigId)) continue;

                // Si tiene grupos activos en esta gestión, no tocar
                if (in_array($asigId, $asignaturasConGrupoActivo)) continue;

                // Verificar si tiene grupos activos en OTRAS gestiones para esta carrera/sede
                $tieneGruposOtrasGestiones = Grupo::withoutGlobalScope('activo')
                    ->where('asignatura_id', $asigId)
                    ->where('carrera_id', $carreraId)
                    ->where('sede_id', $sedeId)
                    ->where('gestion', '!=', $gestion)
                    ->where('estado', 'ACTIVO')
                    ->whereNull('deleted_at')
                    ->exists();

                if ($tieneGruposOtrasGestiones) continue;

                // No tiene grupos activos en ninguna gestión para esta carrera/sede → desvincular
                $asignatura = Asignatura::withoutGlobalScopes()->find($asigId);
                if (!$asignatura) continue;

                DB::table('asignatura_carrera')
                    ->where('asignatura_id', $asigId)
                    ->where('carrera_id', $carreraId)
                    ->where('sede_id', $sedeId)
                    ->delete();

                $resultado['asignaturas_desvinculadas'][] = [
                    'id'     => $asignatura->id,
                    'codigo' => $asignatura->codigo,
                    'nombre' => $asignatura->nombre,
                    'plan'   => $asignatura->plan_estudios,
                ];

                Log::info("PlanningSyncService::reconcile - asignatura desvinculada de carrera", [
                    'asignatura_id' => $asigId,
                    'carrera_id'    => $carreraId,
                    'sede_id'       => $sedeId,
                ]);
            }

            // ── E) Detectar conflictos locales ───────────────────────────────────────
            // Grupos modificados localmente donde la API trae un docente diferente
            $apiDocentes = collect($apiItems)->mapWithKeys(function ($item) {
                $dto  = AcademicDataDTO::fromArray($item);
                $plan = $dto->planEst ?: 'N';
                $tipo = isset($dto->tipoClase)
                    ? ((strtoupper(trim($dto->tipoClase)) === 'REGULAR') ? 'TEORICO' : strtoupper(trim($dto->tipoClase)))
                    : 'TEORICO';
                $key = "{$dto->siglaP}|{$plan}|{$dto->grupo}|{$tipo}";
                return [$key => ['ci' => $dto->ci, 'nombre' => $dto->docente]];
            });

            $gruposModificados = Grupo::withoutGlobalScope('activo')
                ->with(['asignatura', 'docente'])
                ->where('carrera_id', $carreraId)
                ->where('sede_id', $sedeId)
                ->where('gestion', $gestion)
                ->where('modificado_localmente', true)
                ->whereNull('deleted_at')
                ->get();

            foreach ($gruposModificados as $grupo) {
                if (!$grupo->asignatura) continue;

                $plan = $grupo->asignatura->plan_estudios ?? 'N';
                $key  = "{$grupo->asignatura->codigo}|{$plan}|{$grupo->nombre}|{$grupo->tipo}";

                if ($apiDocentes->has($key)) {
                    $docenteApi = $apiDocentes[$key];
                    $docenteLocal = $grupo->docente?->nombre_completo ?? 'Sin asignar';

                    // Solo reportar si el docente es diferente
                    $docenteLocalCi = $grupo->docente?->ci ?? '';
                    if ($docenteApi['ci'] && $docenteLocalCi !== $docenteApi['ci']) {
                        $resultado['conflictos_locales'][] = [
                            'grupo_id'        => $grupo->id,
                            'grupo'           => $grupo->nombre . ' (' . $grupo->tipo . ')',
                            'asignatura'      => $grupo->asignatura->nombre,
                            'campo'           => 'docente',
                            'valor_local'     => $docenteLocal,
                            'valor_api'       => $docenteApi['nombre'],
                            'docente_ci_api'  => $docenteApi['ci'],
                        ];
                    }
                }
            }

            return $resultado;
        });
    }

    /**
     * Consolidar asignaturas duplicadas del mismo código.
     * Busca todas las asignaturas con el mismo código, elige la "correcta"
     * (la que tiene plan_estudios = planPreferido, o la más completa),
     * y migra grupos/horarios/pivots de las duplicadas hacia ella.
     */
    private function consolidarAsignaturasDuplicadas(string $codigo, ?string $planPreferido = null): ?Asignatura
    {
        $asignaturas = Asignatura::withoutGlobalScopes()
            ->where('codigo', $codigo)
            ->where(function ($q) use ($planPreferido) {
                $q->where('plan_estudios', $planPreferido)
                  ->orWhereNull('plan_estudios');
            })
            ->get();

        if ($asignaturas->count() <= 1) {
            return $asignaturas->first();
        }

        Log::info('PlanningSyncService::consolidarAsignaturasDuplicadas - Encontradas duplicadas', [
            'codigo' => $codigo,
            'cantidad' => $asignaturas->count(),
            'ids' => $asignaturas->pluck('id')->toArray(),
        ]);

        // Elegir la asignatura "correcta":
        // 1. La que tenga plan_estudios = planPreferido
        // 2. La que tenga más grupos
        // 3. La más reciente
        $correcta = $asignaturas->first(function ($a) use ($planPreferido) {
            return $planPreferido && $a->plan_estudios === $planPreferido;
        });

        if (!$correcta) {
            $correcta = $asignaturas->sortByDesc(function ($a) {
                return $a->grupos()->count();
            })->first();
        }

        $duplicadas = $asignaturas->where('id', '!=', $correcta->id);

        foreach ($duplicadas as $dup) {
            Log::info('PlanningSyncService::consolidar - Fusionando duplicada', [
                'dup_id' => $dup->id,
                'plan' => $dup->plan_estudios,
                'into_id' => $correcta->id,
            ]);

            // Migrar grupos
            Grupo::withoutGlobalScope('activo')
                ->where('asignatura_id', $dup->id)
                ->update(['asignatura_id' => $correcta->id]);

            // Migrar pivots carrera
            $pivots = DB::table('asignatura_carrera')
                ->where('asignatura_id', $dup->id)
                ->get();
            foreach ($pivots as $p) {
                $exists = DB::table('asignatura_carrera')
                    ->where('asignatura_id', $correcta->id)
                    ->where('carrera_id', $p->carrera_id)
                    ->where('sede_id', $p->sede_id)
                    ->exists();
                if (!$exists) {
                    DB::table('asignatura_carrera')->insert([
                        'asignatura_id' => $correcta->id,
                        'carrera_id' => $p->carrera_id,
                        'sede_id' => $p->sede_id,
                        'semestre' => $p->semestre,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Eliminar pivots de la duplicada
            DB::table('asignatura_carrera')->where('asignatura_id', $dup->id)->delete();

            // Soft-delete la duplicada
            $dup->delete();
        }

        return $correcta->fresh();
    }
}
