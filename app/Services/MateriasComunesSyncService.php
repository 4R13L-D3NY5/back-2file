<?php

namespace App\Services;

use App\Models\Asignatura;
use App\Models\Unidad;
use App\Models\Tema;
use App\Models\Grupo;
use App\Models\LogroEsperado;
use App\Models\Indicador;
use App\Models\PlanificacionPersonal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de Sincronización para Materias Comunes
 *
 * Maneja la replicación automática de datos de documentación
 * entre asignaturas vinculadas (comun_token) cuando el mismo
 * docente dicta ambas materias.
 */
class MateriasComunesSyncService
{
    /**
     * Flag para evitar loops infinitos de sincronización
     */
    private static bool $isSyncing = false;

    /**
     * Obtiene asignaturas vinculadas que comparten el mismo docente.
     *
     * @param Asignatura $source Asignatura origen
     * @return Collection Colección de Asignaturas hermanas con mismo docente
     */
    public function getLinkedSubjectsWithSameTeacher(Asignatura $source): Collection
    {
        // Si no tiene comun_token, no hay vinculadas
        if (!$source->comun_token) {
            return collect();
        }

        // Buscar TODAS las asignaturas hermanas (mismo comun_token, diferente id)
        // La documentación base (Unidades, Temas) debe ser la misma para todas 
        // las materias comunes sin importar el docente asignado.
        return Asignatura::where('comun_token', $source->comun_token)
            ->where('id', '!=', $source->id)
            ->get();
    }

    /**
     * Obtiene los user_ids de los docentes compartidos entre dos asignaturas
     */
    public function getSharedTeacherUserIds(Asignatura $source, Asignatura $target): array
    {
        $sourceDocenteIds = Grupo::where('asignatura_id', $source->id)
            ->whereNotNull('docente_id')
            ->pluck('docente_id')
            ->unique()
            ->toArray();

        $targetDocenteIds = Grupo::where('asignatura_id', $target->id)
            ->whereNotNull('docente_id')
            ->pluck('docente_id')
            ->unique()
            ->toArray();

        $sharedDocenteIds = array_intersect($sourceDocenteIds, $targetDocenteIds);

        if (empty($sharedDocenteIds)) {
            return [];
        }

        // Obtener user_ids de estos docentes
        return \App\Models\Docente::whereIn('id', $sharedDocenteIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->toArray();
    }

    /**
     * Verifica si dos asignaturas comparten el mismo docente
     */
    public function sharesSameTeacher(Asignatura $a, Asignatura $b): bool
    {
        $docentesA = Grupo::where('asignatura_id', $a->id)
            ->whereNotNull('docente_id')
            ->pluck('docente_id')
            ->toArray();

        $docentesB = Grupo::where('asignatura_id', $b->id)
            ->whereNotNull('docente_id')
            ->pluck('docente_id')
            ->toArray();

        return !empty(array_intersect($docentesA, $docentesB));
    }

    /**
     * Sincroniza TODA la documentación de una asignatura a sus hermanas vinculadas
     *
     * @param Asignatura $source Asignatura origen (la que tiene los datos más completos)
     * @return int Número de asignaturas sincronizadas
     */
    public function syncAllDocumentationToLinked(Asignatura $source): int
    {
        if (self::$isSyncing) {
            return 0; // Evitar recursión
        }

        $linked = $this->getLinkedSubjectsWithSameTeacher($source);

        if ($linked->isEmpty()) {
            return 0;
        }

        self::$isSyncing = true;
        $synced = 0;

        try {
            DB::transaction(function () use ($source, $linked, &$synced) {
                foreach ($linked as $target) {
                    $this->syncDocumentationBetween($source, $target);
                    $synced++;
                }
            });
        } finally {
            self::$isSyncing = false;
        }

        Log::info("MateriasComunesSync: Sincronizadas {$synced} asignaturas desde {$source->codigo}");
        return $synced;
    }

    /**
     * Sincroniza la documentación de una asignatura origen a una destino
     */
    private function syncDocumentationBetween(Asignatura $source, Asignatura $target): void
    {
        // Sincronizar campos de nivel asignatura
        $target->update([
            'descripcion' => $source->descripcion,
            'justificacion' => $source->justificacion,
            'proposito_general' => $source->proposito_general,
            'metodologia_general' => $source->metodologia_general,
            'sistema_evaluacion' => $source->sistema_evaluacion,
            'contenido_minimo' => $source->contenido_minimo,
            'requisitos' => $source->requisitos,
            'competencia_global_especifica' => $source->competencia_global_especifica,
            'competencia_asignatura' => $source->competencia_asignatura,
            'elementos_competencia' => $source->elementos_competencia,
            'reglamento_normativa' => $source->reglamento_normativa,
            'organizacion_calendario' => $source->organizacion_calendario,
        ]);

        // Obtener user_ids de docentes compartidos para sincronizar datos personales
        $sharedUserIds = $this->getSharedTeacherUserIds($source, $target);

        // Sincronizar estructura de Unidades y Temas (incluyendo datos personales)
        $this->syncUnidadesStructure($source, $target, $sharedUserIds);
    }

    /**
     * Sincroniza la estructura de Unidades desde origen a destino
     * @param array $sharedUserIds User IDs de docentes compartidos para sincronizar datos personales
     */
    private function syncUnidadesStructure(Asignatura $source, Asignatura $target, array $sharedUserIds = []): void
    {
        // Mapeo de unidades origen -> destino por número
        $sourceUnidades = $source->unidades()->with('temas.logros.indicadores', 'temas.planificacionPersonal')->get();
        $targetUnidades = $target->unidades()->with('temas')->get()->keyBy('numero');

        foreach ($sourceUnidades as $sourceUnidad) {
            // Buscar o crear unidad en destino
            $targetUnidad = $targetUnidades->get($sourceUnidad->numero);

            if (!$targetUnidad) {
                // Crear nueva unidad
                $targetUnidad = $target->unidades()->create([
                    'numero' => $sourceUnidad->numero,
                    'titulo' => $sourceUnidad->titulo,
                    'objetivo' => $sourceUnidad->objetivo,
                    'contenido_minimo' => $sourceUnidad->contenido_minimo,
                    'elemento_competencia' => $sourceUnidad->elemento_competencia,
                    'tipo' => $sourceUnidad->tipo,
                ]);
            } else {
                // Actualizar unidad existente
                $targetUnidad->update([
                    'titulo' => $sourceUnidad->titulo,
                    'objetivo' => $sourceUnidad->objetivo,
                    'contenido_minimo' => $sourceUnidad->contenido_minimo,
                    'elemento_competencia' => $sourceUnidad->elemento_competencia,
                    'tipo' => $sourceUnidad->tipo,
                ]);
            }

            // Sincronizar temas de esta unidad (incluyendo datos personales)
            $this->syncTemasStructure($sourceUnidad, $targetUnidad, $sharedUserIds);
        }

        // Eliminar unidades huérfanas en destino (que ya no existen en origen) - DESHABILITADO POR RIESGO DE PÉRDIDA DE DATOS
        $sourceNumeros = $sourceUnidades->pluck('numero')->toArray();
        // $target->unidades()->whereNotIn('numero', $sourceNumeros)->delete();
    }

    /**
     * Sincroniza la estructura de Temas desde unidad origen a destino
     * @param array $sharedUserIds User IDs de docentes compartidos para sincronizar datos personales
     */
    private function syncTemasStructure(Unidad $sourceUnidad, Unidad $targetUnidad, array $sharedUserIds = []): void
    {
        $sourceTemas = $sourceUnidad->temas()->with('logros.indicadores', 'planificacionPersonal')->orderBy('orden')->get();
        $targetTemas = $targetUnidad->temas()->get()->keyBy('orden');

        foreach ($sourceTemas as $sourceTema) {
            $targetTema = $targetTemas->get($sourceTema->orden);

            $temaData = [
                'titulo' => $sourceTema->titulo,
                // 'descripcion' => $sourceTema->descripcion, // Removed from DB
                'orden' => $sourceTema->orden,
                'resultado_aprendizaje' => $sourceTema->resultado_aprendizaje,
                'horas_practicas' => $sourceTema->horas_practicas,
                'horas_teoricas' => $sourceTema->horas_teoricas,
                'tipo' => $sourceTema->tipo,
                'contenido_items' => $sourceTema->contenido_items,
                'contenido_conceptual' => $sourceTema->contenido_conceptual,
                'contenido_procedimental' => $sourceTema->contenido_procedimental,
                'contenido_actitudinal' => $sourceTema->contenido_actitudinal,
                'estrategias_metodologicas' => $sourceTema->estrategias_metodologicas,
                'estrategias_aprendizaje' => $sourceTema->estrategias_aprendizaje,
                'estrategias_recursos' => $sourceTema->estrategias_recursos,
                'evaluacion_formativa' => $sourceTema->evaluacion_formativa,
                'evaluacion_sumativa' => $sourceTema->evaluacion_sumativa,
            ];

            if (!$targetTema) {
                $targetTema = $targetUnidad->temas()->create($temaData);
            } else {
                $targetTema->update($temaData);
            }

            // Sincronizar logros e indicadores
            $this->syncLogrosStructure($sourceTema, $targetTema);

            // SINCRONIZAR DATOS PERSONALES para docentes compartidos
            if (!empty($sharedUserIds)) {
                $this->syncPlanificacionPersonal($sourceTema, $targetTema, $sharedUserIds);
            }
        }

        // Eliminar temas huérfanos - DESHABILITADO POR RIESGO DE PÉRDIDA DE DATOS
        $sourceOrdenes = $sourceTemas->pluck('orden')->toArray();
        // $targetUnidad->temas()->whereNotIn('orden', $sourceOrdenes)->delete();
    }

    /**
     * Sincroniza PlanificacionPersonal de docentes compartidos entre temas
     */
    private function syncPlanificacionPersonal(Tema $sourceTema, Tema $targetTema, array $userIds): void
    {
        foreach ($userIds as $userId) {
            // Buscar planificación personal del usuario en el tema origen
            $sourcePersonal = PlanificacionPersonal::where('tema_id', $sourceTema->id)
                ->where('user_id', $userId)
                ->first();

            if ($sourcePersonal) {
                // Copiar o actualizar en el tema destino
                PlanificacionPersonal::updateOrCreate(
                    [
                        'tema_id' => $targetTema->id,
                        'user_id' => $userId
                    ],
                    [
                        'estrategias_metodologicas' => $sourcePersonal->estrategias_metodologicas,
                        'estrategias_aprendizaje' => $sourcePersonal->estrategias_aprendizaje,
                        'estrategias_recursos' => $sourcePersonal->estrategias_recursos,
                        'evaluacion_formativa' => $sourcePersonal->evaluacion_formativa,
                        'evaluacion_sumativa' => $sourcePersonal->evaluacion_sumativa,
                        'secuencia_didactica' => $sourcePersonal->secuencia_didactica,
                    ]
                );
            }
        }
    }

    /**
     * Sincroniza PlanificacionPersonal de un usuario específico a materias vinculadas
     * (Llamado desde PlanificacionController después de guardar datos personales)
     *
     * @param Tema $tema El tema origen
     * @param int $userId El user_id del usuario que guardó los datos
     * @return int Número de materias sincronizadas
     */
    public function syncPersonalDataForUser(Tema $tema, int $userId): int
    {
        if (self::$isSyncing) {
            return 0;
        }

        $unidad = $tema->unidad;
        /** @var \App\Models\Asignatura $asignatura */
        $asignatura = $unidad->asignatura;
        $linked = $this->getLinkedSubjectsWithSameTeacher($asignatura);

        if ($linked->isEmpty()) {
            return 0;
        }

        self::$isSyncing = true;
        $synced = 0;

        try {
            DB::transaction(function () use ($tema, $unidad, $asignatura, $userId, $linked, &$synced) {
                foreach ($linked as $target) {
                    // Verificar que el usuario es docente compartido
                    $sharedUserIds = $this->getSharedTeacherUserIds($asignatura, $target);

                    if (!in_array($userId, $sharedUserIds)) {
                        continue; // Este usuario no es docente de la materia destino
                    }

                    // Buscar tema correspondiente en la materia vinculada
                    $targetUnidad = $target->unidades()
                        ->where('numero', $unidad->numero)
                        ->first();

                    if (!$targetUnidad) {
                        continue; // No existe la unidad correspondiente
                    }

                    $targetTema = $targetUnidad->temas()
                        ->where('orden', $tema->orden)
                        ->first();

                    if (!$targetTema) {
                        continue; // No existe el tema correspondiente
                    }

                    // Sincronizar datos personales de este usuario
                    $this->syncPlanificacionPersonal($tema, $targetTema, [$userId]);
                    $synced++;
                }
            });
        } finally {
            self::$isSyncing = false;
        }

        return $synced;
    }

    /**
     * Sincroniza Logros Esperados e Indicadores
     */
    private function syncLogrosStructure(Tema $sourceTema, Tema $targetTema): void
    {
        $sourceLogros = $sourceTema->logros()->with('indicadores')->get();

        // Eliminar logros existentes en el destino y recrear
        $targetTema->logros()->delete();

        foreach ($sourceLogros as $sourceLogro) {
            $newLogro = $targetTema->logros()->create([
                'descripcion' => $sourceLogro->descripcion,
                'tipo_logro' => $sourceLogro->tipo_logro,
                'periodo' => $sourceLogro->periodo,
            ]);

            // Sincronizar indicadores
            foreach ($sourceLogro->indicadores as $indicador) {
                $newLogro->indicadores()->create([
                    'descripcion' => $indicador->descripcion,
                ]);
            }
        }
    }

    /**
     * Sincroniza una Unidad específica a materias vinculadas
     * (Llamado desde PlanificacionController después de store/update)
     */
    public function syncUnidad(Unidad $unidad): int
    {
        if (self::$isSyncing) {
            return 0;
        }

        $asignatura = $unidad->asignatura;
        $linked = $this->getLinkedSubjectsWithSameTeacher($asignatura);

        if ($linked->isEmpty()) {
            return 0;
        }

        self::$isSyncing = true;
        $synced = 0;

        try {
            DB::transaction(function () use ($unidad, $linked, &$synced) {
                foreach ($linked as $target) {
                    // Buscar o crear unidad correspondiente en el target
                    $targetUnidad = $target->unidades()
                        ->where('numero', $unidad->numero)
                        ->first();

                    if (!$targetUnidad) {
                        $targetUnidad = $target->unidades()->create([
                            'numero' => $unidad->numero,
                            'titulo' => $unidad->titulo,
                            'objetivo' => $unidad->objetivo,
                            'contenido_minimo' => $unidad->contenido_minimo,
                            'elemento_competencia' => $unidad->elemento_competencia,
                            'tipo' => $unidad->tipo,
                        ]);
                    } else {
                        $targetUnidad->update([
                            'titulo' => $unidad->titulo,
                            'objetivo' => $unidad->objetivo,
                            'contenido_minimo' => $unidad->contenido_minimo,
                            'elemento_competencia' => $unidad->elemento_competencia,
                            'tipo' => $unidad->tipo,
                        ]);
                    }

                    $synced++;
                }
            });
        } finally {
            self::$isSyncing = false;
        }

        return $synced;
    }

    /**
     * Sincroniza un Tema específico a materias vinculadas
     * (Llamado desde PlanificacionController después de storeTema/updateTema)
     */
    public function syncTema(Tema $tema): int
    {
        if (self::$isSyncing) {
            return 0;
        }

        $unidad = $tema->unidad;
        /** @var \App\Models\Asignatura $asignatura */
        $asignatura = $unidad->asignatura;
        $linked = $this->getLinkedSubjectsWithSameTeacher($asignatura);

        if ($linked->isEmpty()) {
            return 0;
        }

        self::$isSyncing = true;
        $synced = 0;

        try {
            DB::transaction(function () use ($tema, $unidad, $asignatura, $linked, &$synced) {
                foreach ($linked as $target) {
                    // Obtener user_ids compartidos para sincronizar datos personales
                    $sharedUserIds = $this->getSharedTeacherUserIds($asignatura, $target);

                    // Buscar unidad correspondiente
                    $targetUnidad = $target->unidades()
                        ->where('numero', $unidad->numero)
                        ->first();

                    if (!$targetUnidad) {
                        // Crear la unidad primero si no existe
                        $targetUnidad = $target->unidades()->create([
                            'numero' => $unidad->numero,
                            'titulo' => $unidad->titulo,
                            'objetivo' => $unidad->objetivo,
                            'contenido_minimo' => $unidad->contenido_minimo,
                            'elemento_competencia' => $unidad->elemento_competencia,
                            'tipo' => $unidad->tipo,
                        ]);
                    }

                    // Buscar o crear tema correspondiente
                    $targetTema = $targetUnidad->temas()
                        ->where('orden', $tema->orden)
                        ->first();

                    $temaData = [
                        'titulo' => $tema->titulo,
                        // 'descripcion' => $tema->descripcion, // Removed from DB
                        'orden' => $tema->orden,
                        'resultado_aprendizaje' => $tema->resultado_aprendizaje,
                        'horas_practicas' => $tema->horas_practicas,
                        'horas_teoricas' => $tema->horas_teoricas,
                        'tipo' => $tema->tipo,
                        'contenido_items' => $tema->contenido_items,
                        'contenido_conceptual' => $tema->contenido_conceptual,
                        'contenido_procedimental' => $tema->contenido_procedimental,
                        'contenido_actitudinal' => $tema->contenido_actitudinal,
                        'estrategias_metodologicas' => $tema->estrategias_metodologicas,
                        'estrategias_aprendizaje' => $tema->estrategias_aprendizaje,
                        'estrategias_recursos' => $tema->estrategias_recursos,
                        'evaluacion_formativa' => $tema->evaluacion_formativa,
                        'evaluacion_sumativa' => $tema->evaluacion_sumativa,
                    ];

                    if (!$targetTema) {
                        $targetTema = $targetUnidad->temas()->create($temaData);
                    } else {
                        $targetTema->update($temaData);
                    }

                    // Sincronizar logros
                    $tema->load('logros.indicadores');
                    $this->syncLogrosStructure($tema, $targetTema);

                    // SINCRONIZAR DATOS PERSONALES para docentes compartidos
                    if (!empty($sharedUserIds)) {
                        $this->syncPlanificacionPersonal($tema, $targetTema, $sharedUserIds);
                    }

                    $synced++;
                }
            });
        } finally {
            self::$isSyncing = false;
        }

        return $synced;
    }

    /**
     * Elimina una Unidad en materias vinculadas
     */
    public function deleteUnidadFromLinked(int $numero, Asignatura $source): int
    {
        if (self::$isSyncing) {
            return 0;
        }

        $linked = $this->getLinkedSubjectsWithSameTeacher($source);

        if ($linked->isEmpty()) {
            return 0;
        }

        self::$isSyncing = true;
        $deleted = 0;

        try {
            DB::transaction(function () use ($numero, $linked, &$deleted) {
                foreach ($linked as $target) {
                    $targetUnidad = $target->unidades()
                        ->where('numero', $numero)
                        ->first();

                    if ($targetUnidad) {
                        $targetUnidad->temas()->delete();
                        $targetUnidad->delete();
                        $deleted++;
                    }
                }
            });
        } finally {
            self::$isSyncing = false;
        }

        return $deleted;
    }

    /**
     * Elimina un Tema en materias vinculadas
     */
    public function deleteTemaFromLinked(int $orden, Unidad $sourceUnidad): int
    {
        if (self::$isSyncing) {
            return 0;
        }

        $asignatura = $sourceUnidad->asignatura;
        $linked = $this->getLinkedSubjectsWithSameTeacher($asignatura);

        if ($linked->isEmpty()) {
            return 0;
        }

        self::$isSyncing = true;
        $deleted = 0;

        try {
            DB::transaction(function () use ($orden, $sourceUnidad, $linked, &$deleted) {
                foreach ($linked as $target) {
                    $targetUnidad = $target->unidades()
                        ->where('numero', $sourceUnidad->numero)
                        ->first();

                    if ($targetUnidad) {
                        $targetTema = $targetUnidad->temas()
                            ->where('orden', $orden)
                            ->first();

                        if ($targetTema) {
                            $targetTema->logros()->delete();
                            $targetTema->delete();
                            $deleted++;
                        }
                    }
                }
            });
        } finally {
            self::$isSyncing = false;
        }

        return $deleted;
    }

    // =========================================================================
    // MERGE INTELIGENTE AL VINCULAR
    // =========================================================================

    /**
     * Merge inteligente al declarar materias comunes.
     *
     * En vez de "el ganador lo toma todo" basado en % progreso, hace:
     *  - Campos PAC (justificación, propósito, etc.): toma el mejor valor por campo
     *  - Estructura unidades/temas: usa la carpeta con más contenido documentado
     *  - PlanificacionPersonal: recolecta de TODAS y redistribuye a TODAS (bidireccional)
     *  - Bibliografía: usa la que tiene más entradas
     *
     * Esto resuelve el caso donde un docente llenó la documentación en una carpeta
     * y la planificación personal en otra (porque solo una tenía horario).
     */
    public function mergeAndSyncOnLink(string $comunToken): int
    {
        if (self::$isSyncing) return 0;

        $asignaturas = Asignatura::where('comun_token', $comunToken)
            ->with([
                'unidades.temas.logros.indicadores',
                'bibliografias',
            ])
            ->get();

        if ($asignaturas->count() < 2) return 0;

        self::$isSyncing = true;
        $synced = 0;

        try {
            DB::transaction(function () use ($asignaturas, &$synced) {
                // PASO 1: Recolectar TODAS las planificaciones personales ANTES de cualquier cambio
                // (indexadas por user_id → unidad.numero → tema.orden)
                $planificaciones = $this->collectAllPlanificaciones($asignaturas);

                // PASO 2: Determinar master de documentación (PAC + programa analítico más completo)
                $docMaster = $this->findDocumentationMaster($asignaturas);

                // PASO 3: Campos de nivel asignatura: mejor valor de cada campo entre todas
                $mergedFields = $this->buildMergedAsignaturaFields($asignaturas, $docMaster);

                // PASO 4: Master de bibliografía (la que tiene más entradas)
                $bibMaster = $asignaturas->sortByDesc(fn($a) => $a->bibliografias->count())->first();

                // PASO 5: Aplicar a todas
                foreach ($asignaturas as $asignatura) {
                    // 5a. Sincronizar estructura unidades/temas desde el docMaster
                    if ($asignatura->id !== $docMaster->id) {
                        $sharedUserIds = $this->getSharedTeacherUserIds($docMaster, $asignatura);
                        $this->syncUnidadesStructure($docMaster, $asignatura, $sharedUserIds);
                    }

                    // 5b. Aplicar campos mergeados (complementa lo que syncUnidadesStructure no toca)
                    $asignatura->update($mergedFields);

                    // 5c. Bibliografía desde el master si la propia está vacía o es menor
                    if ($asignatura->id !== $bibMaster->id) {
                        $asignatura->bibliografias()->delete();
                        foreach ($bibMaster->bibliografias as $bib) {
                            $asignatura->bibliografias()->create($bib->only([
                                'titulo', 'autor', 'editorial', 'edicion', 'anio',
                                'tipo', 'isbn', 'paginas', 'descripcion',
                            ]));
                        }
                    }

                    $synced++;
                }

                // PASO 6: Redistribuir planificaciones a TODAS las asignaturas
                // Se hace DESPUÉS del sync de estructura para que los tema_ids sean frescos
                foreach ($asignaturas as $asignatura) {
                    $this->applyCollectedPlanificaciones($asignatura, $planificaciones);
                }
            });
        } finally {
            self::$isSyncing = false;
        }

        Log::info("MateriasComunesSync: Merge inteligente completado para token {$comunToken}, {$synced} asignaturas procesadas.");
        return $synced;
    }

    /**
     * Puntúa una asignatura por la completitud de su documentación
     * (PAC + programa analítico), sin contar planificación personal.
     */
    private function scoreDocumentation(Asignatura $asig): int
    {
        $score = 0;

        // Campos PAC - PESO ALTO PARA PRIORIZAR MATERIAS CON DOCUMENTACIÓN PAC COMPLETA
        if (!$this->isFieldEmpty($asig->justificacion))          $score += 90;
        if (!$this->isFieldEmpty($asig->proposito_general))      $score += 90;
        if (!$this->isFieldEmpty($asig->competencia_global_especifica)
            || !$this->isFieldEmpty($asig->competencia_asignatura)) $score += 60;
        if (!$this->isFieldEmpty($asig->metodologia_general))    $score += 30;
        if (!$this->isFieldEmpty($asig->sistema_evaluacion))     $score += 30;
        if (!$this->isFieldEmpty($asig->contenido_minimo))       $score += 30;

        // Estructura analítica
        foreach ($asig->unidades as $unidad) {
            $score += 1;
            foreach ($unidad->temas as $tema) {
                if (!$this->isFieldEmpty($tema->resultado_aprendizaje))   $score += 2;
                if (!$this->isFieldEmpty($tema->contenido_conceptual)
                    || !$this->isFieldEmpty($tema->contenido_procedimental)) $score += 2;
                if ($tema->logros->count() > 0) $score += 1;
            }
        }

        // Bibliografía
        $score += $asig->bibliografias->count() * 2;

        return $score;
    }

    /**
     * Devuelve la asignatura con mayor puntaje documental.
     */
    private function findDocumentationMaster(Collection $asignaturas): Asignatura
    {
        $bestScore = -1;
        $best = $asignaturas->first();

        foreach ($asignaturas as $asig) {
            $score = $this->scoreDocumentation($asig);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $asig;
            }
        }

        return $best;
    }

    /**
     * Construye los campos de nivel asignatura tomando el mejor valor de cada campo
     * (parte del docMaster; si está vacío, busca en las demás).
     */
    private function buildMergedAsignaturaFields(Collection $asignaturas, Asignatura $docMaster): array
    {
        $fields = [
            'descripcion', 'justificacion', 'proposito_general', 'contenido_minimo',
            'requisitos', 'competencia_global_especifica', 'competencia_asignatura',
            'reglamento_normativa', 'organizacion_calendario',
            'metodologia_general', 'sistema_evaluacion', 'elementos_competencia',
        ];

        $merged = [];

        foreach ($fields as $field) {
            $best = $docMaster->$field;

            if ($this->isFieldEmpty($best)) {
                foreach ($asignaturas as $asig) {
                    if ($asig->id !== $docMaster->id && !$this->isFieldEmpty($asig->$field)) {
                        $best = $asig->$field;
                        break;
                    }
                }
            }

            $merged[$field] = $best;
        }

        return $merged;
    }

    /**
     * Verifica si un campo está vacío: null, '', [], o solo HTML/espacios vacíos.
     */
    private function isFieldEmpty($value): bool
    {
        if (is_null($value)) return true;
        if (is_array($value)) return empty($value);
        if (is_string($value)) {
            $stripped = trim(strip_tags(str_replace(['&nbsp;', '\u00a0'], '', $value)));
            return $stripped === '';
        }
        return false;
    }

    /**
     * Recolecta TODAS las planificaciones personales de todas las asignaturas.
     * Estructura: [user_id][unidad_numero][tema_orden] = array de datos del plan.
     */
    private function collectAllPlanificaciones(Collection $asignaturas): array
    {
        $collected = [];

        foreach ($asignaturas as $asignatura) {
            foreach ($asignatura->unidades as $unidad) {
                $temas = $unidad->temas()->get();
                foreach ($temas as $tema) {
                    $planes = PlanificacionPersonal::where('tema_id', $tema->id)->get();
                    foreach ($planes as $plan) {
                        if (!$this->planHasContent($plan)) continue;

                        $uid = $plan->user_id;
                        $num = $unidad->numero;
                        $ord = $tema->orden;

                        if (isset($collected[$uid][$num][$ord])) {
                            // Si hay colisión (mismo docente llenó en ambas carpetas),
                            // fusionar campo por campo, prefiriendo el no vacío
                            $collected[$uid][$num][$ord] = $this->mergePlanData(
                                $collected[$uid][$num][$ord],
                                $plan->toArray()
                            );
                        } else {
                            $collected[$uid][$num][$ord] = [
                                'user_id'                   => $uid,
                                'estrategias_metodologicas' => $plan->estrategias_metodologicas,
                                'estrategias_aprendizaje'   => $plan->estrategias_aprendizaje,
                                'estrategias_recursos'      => $plan->estrategias_recursos,
                                'evaluacion_formativa'      => $plan->evaluacion_formativa,
                                'evaluacion_sumativa'       => $plan->evaluacion_sumativa,
                                'secuencia_didactica'       => $plan->secuencia_didactica,
                            ];
                        }
                    }
                }
            }
        }

        return $collected;
    }

    /**
     * Aplica las planificaciones recolectadas a una asignatura,
     * buscando cada tema por unidad.numero + tema.orden.
     */
    private function applyCollectedPlanificaciones(Asignatura $asignatura, array $planificaciones): void
    {
        if (empty($planificaciones)) return;

        $unidades = $asignatura->unidades()->with('temas')->get();

        foreach ($unidades as $unidad) {
            foreach ($unidad->temas as $tema) {
                $num = $unidad->numero;
                $ord = $tema->orden;

                foreach ($planificaciones as $userId => $unidadMap) {
                    if (!isset($unidadMap[$num][$ord])) continue;

                    $planData = $unidadMap[$num][$ord];

                    PlanificacionPersonal::updateOrCreate(
                        ['tema_id' => $tema->id, 'user_id' => $userId],
                        [
                            'estrategias_metodologicas' => $planData['estrategias_metodologicas'],
                            'estrategias_aprendizaje'   => $planData['estrategias_aprendizaje'],
                            'estrategias_recursos'      => $planData['estrategias_recursos'],
                            'evaluacion_formativa'      => $planData['evaluacion_formativa'],
                            'evaluacion_sumativa'       => $planData['evaluacion_sumativa'],
                            'secuencia_didactica'       => $planData['secuencia_didactica'],
                        ]
                    );
                }
            }
        }
    }

    /**
     * Verifica si una PlanificacionPersonal tiene al menos un campo con contenido.
     */
    private function planHasContent(PlanificacionPersonal $plan): bool
    {
        return !$this->isFieldEmpty($plan->estrategias_metodologicas)
            || !$this->isFieldEmpty($plan->estrategias_aprendizaje)
            || !$this->isFieldEmpty($plan->evaluacion_formativa)
            || !$this->isFieldEmpty($plan->evaluacion_sumativa)
            || !$this->isFieldEmpty($plan->secuencia_didactica);
    }

    /**
     * Fusiona dos arrays de datos de planificación campo por campo,
     * prefiriendo el valor no vacío. El existente tiene prioridad en caso de empate.
     */
    private function mergePlanData(array $existing, array $incoming): array
    {
        $fields = [
            'estrategias_metodologicas', 'estrategias_aprendizaje', 'estrategias_recursos',
            'evaluacion_formativa', 'evaluacion_sumativa', 'secuencia_didactica',
        ];

        $merged = $existing;
        foreach ($fields as $field) {
            if ($this->isFieldEmpty($existing[$field] ?? null) && !$this->isFieldEmpty($incoming[$field] ?? null)) {
                $merged[$field] = $incoming[$field];
            }
        }

        return $merged;
    }

    // =========================================================================

    /**
     * Calcula el porcentaje de progreso de documentación de una asignatura
     */
    public function calculateProgress(Asignatura $asignatura): int
    {
        $total = 0;
        $completed = 0;

        // Criterios de progreso
        $criteria = [
            'descripcion' => !empty($asignatura->descripcion),
            'justificacion' => !empty($asignatura->justificacion),
            'proposito_general' => !empty($asignatura->proposito_general),
            'metodologia_general' => !empty($asignatura->metodologia_general),
            'sistema_evaluacion' => !empty($asignatura->sistema_evaluacion),
        ];

        foreach ($criteria as $completed_item) {
            $total++;
            if ($completed_item) $completed++;
        }

        // Agregar progreso de unidades/temas
        $asignatura->load('unidades.temas');
        foreach ($asignatura->unidades as $unidad) {
            foreach ($unidad->temas as $tema) {
                $total++;
                if (!empty($tema->contenido_conceptual) || !empty($tema->contenido_procedimental)) {
                    $completed++;
                }
            }
        }

        return $total > 0 ? round(($completed / $total) * 100) : 0;
    }

    /**
     * Sincroniza la Bibliografía a todas las materias vinculadas
     * (Llamado desde BibliografiaController y AsignaturaController)
     */
    public function syncBibliografias(Asignatura $source): int
    {
        if (self::$isSyncing) return 0;
        
        $linked = $this->getLinkedSubjectsWithSameTeacher($source);
        if ($linked->isEmpty()) return 0;

        self::$isSyncing = true;
        $synced = 0;
        
        try {
            DB::transaction(function () use ($source, $linked, &$synced) {
                $sourceBibliografias = $source->bibliografias;
                
                foreach ($linked as $target) {
                    $target->bibliografias()->delete();
                    
                    foreach ($sourceBibliografias as $bib) {
                        $target->bibliografias()->create($bib->only([
                            'titulo', 'autor', 'editorial', 'edicion', 'anio', 
                            'tipo', 'isbn', 'paginas', 'descripcion'
                        ]));
                    }
                    $synced++;
                }
            });
        } finally {
            self::$isSyncing = false;
        }
        
        return $synced;
    }

    /**
     * Sincroniza el Cronograma maestro SOLO a las materias vinculadas
     * que son del tipo 'fusionada' (mismo horario y docente).
     * (Llamado desde PlanificacionSemestralController)
     */
    public function syncCronogramasFusionada(Asignatura $source): int
    {
        if (self::$isSyncing) return 0;
        if ($source->comun_tipo !== 'fusionada') return 0;
        
        $linked = $this->getLinkedSubjectsWithSameTeacher($source);
        if ($linked->isEmpty()) return 0;

        self::$isSyncing = true;
        $synced = 0;
        
        try {
            DB::transaction(function () use ($source, $linked, &$synced) {
                // Obtenemos el cronograma MASTER de la materia origen, indexado por numero_sesion
                $masterCronogramas = \App\Models\Cronograma::where('asignatura_id', $source->id)
                    ->whereNull('grupo_id')
                    ->orderBy('numero_sesion')
                    ->get()
                    ->keyBy('numero_sesion');
                    
                foreach ($linked as $target) {
                    // Obtenemos cronogramas existentes en el target, indexados por numero_sesion
                    $existingCronogramas = \App\Models\Cronograma::where('asignatura_id', $target->id)
                        ->whereNull('grupo_id')
                        ->get()
                        ->keyBy('numero_sesion');
                    
                    // Para cada sesión del master, actualizar o crear en el target
                    foreach ($masterCronogramas as $numeroSesion => $mc) {
                        /** @var \App\Models\Cronograma $mc */
                        $targetCrono = $existingCronogramas->get($numeroSesion);
                        
                        $cronoData = [
                            'asignatura_id' => $target->id,
                            'numero_sesion' => $mc->numero_sesion,
                            'semana_academica' => $mc->semana_academica,
                            'tipo_clase' => $mc->tipo_clase,
                            'contenido_conceptual' => $mc->contenido_conceptual,
                            'contenido_procedimental' => $mc->contenido_procedimental,
                            'contenido_actitudinal' => $mc->contenido_actitudinal,
                            'criterios_desempeno' => $mc->criterios_desempeno,
                            'instrumentos_evaluacion' => $mc->instrumentos_evaluacion,
                            'observaciones' => $mc->observaciones,
                            'grupo_id' => null,
                        ];
                        
                        // Mapear tema_id si corresponde
                        if ($mc->tema_id) {
                            $sourceTema = \App\Models\Tema::with('unidad')->find($mc->tema_id);
                            if ($sourceTema && $sourceTema->unidad) {
                                $targetUnidad = $target->unidades()->where('numero', $sourceTema->unidad->numero)->first();
                                if ($targetUnidad) {
                                    $targetTema = $targetUnidad->temas()->where('orden', $sourceTema->orden)->first();
                                    if ($targetTema) {
                                        $cronoData['tema_id'] = $targetTema->id;
                                    } else {
                                        $cronoData['tema_id'] = null;
                                    }
                                } else {
                                    $cronoData['tema_id'] = null;
                                }
                            } else {
                                $cronoData['tema_id'] = null;
                            }
                        } else {
                            $cronoData['tema_id'] = null;
                        }
                        
                        if ($targetCrono) {
                            // Actualizar cronograma existente, preservando cualquier campo extra que no sea replicado
                            $targetCrono->update($cronoData);
                            $updatedCrono = $targetCrono;
                        } else {
                            // Crear nuevo cronograma
                            $updatedCrono = \App\Models\Cronograma::create($cronoData);
                        }
                        
                        // Sincronizar relación ManyToMany temas (cronograma_tema) si existe
                        $mcTemas = $mc->temas;
                        if ($mcTemas->isNotEmpty()) {
                            $targetTemaIds = [];
                            foreach ($mcTemas as $st) {
                                $st->loadMissing('unidad');
                                if ($st->unidad) {
                                    $targetUnidad = $target->unidades()->where('numero', $st->unidad->numero)->first();
                                    if ($targetUnidad) {
                                        $targetTema = $targetUnidad->temas()->where('orden', $st->orden)->first();
                                        if ($targetTema) {
                                            $targetTemaIds[] = $targetTema->id;
                                        }
                                    }
                                }
                            }
                            if (!empty($targetTemaIds)) {
                                $updatedCrono->temas()->sync($targetTemaIds);
                            }
                        }
                    }
                    
                    // NOTA: NO eliminamos cronogramas en target que no estén en source
                    // para evitar pérdida de datos. En una fusión verdadera, el cronograma
                    // debería ser único, pero es más seguro conservar sesiones extras.
                    
                    $synced++;
                }
            });
        } finally {
             self::$isSyncing = false;
        }
        return $synced;
    }

    /**
     * Determina la asignatura con el mejor cronograma entre las vinculadas por comun_token.
     * 
     * @param string $comunToken
     * @return \App\Models\Asignatura|null
     */
    public function getBestCronogramaForComunToken(string $comunToken): ?\App\Models\Asignatura
    {
        $asignaturas = \App\Models\Asignatura::where('comun_token', $comunToken)
            ->with(['cronogramas' => function ($query) {
                $query->whereNull('grupo_id');
            }])
            ->get();

        if ($asignaturas->isEmpty()) {
            return null;
        }

        $bestScore = -1;
        $bestAsignatura = null;

        foreach ($asignaturas as $asignatura) {
            $score = $this->scoreCronogramaCompleteness($asignatura);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAsignatura = $asignatura;
            }
        }

        // Si ninguna tiene cronogramas con contenido, fallback a la mejor documentación
        if ($bestScore <= 0) {
            $bestAsignatura = $this->findDocumentationMaster($asignaturas);
        }

        return $bestAsignatura;
    }

    /**
     * Puntúa la completitud del cronograma de una asignatura.
     * 
     * @param \App\Models\Asignatura $asignatura
     * @return int
     */
    private function scoreCronogramaCompleteness(\App\Models\Asignatura $asignatura): int
    {
        $score = 0;
        $cronogramas = $asignatura->cronogramas;

        if ($cronogramas->isEmpty()) {
            return 0;
        }

        foreach ($cronogramas as $crono) {
            // Cada sesión con contenido aporta puntos
            $sessionScore = 0;
            if (!$this->isFieldEmpty($crono->contenido_conceptual)) $sessionScore += 2;
            if (!$this->isFieldEmpty($crono->contenido_procedimental)) $sessionScore += 2;
            if (!$this->isFieldEmpty($crono->contenido_actitudinal)) $sessionScore += 2;
            if (!$this->isFieldEmpty($crono->criterios_desempeno)) $sessionScore += 1;
            if (!$this->isFieldEmpty($crono->instrumentos_evaluacion)) $sessionScore += 1;
            if ($crono->tema_id) $sessionScore += 3; // Vinculación a tema es valioso
            
            $score += $sessionScore;
        }

        // Bonus por cantidad de sesiones (estructura completa)
        $score += $cronogramas->count() * 1;

        return $score;
    }
}
