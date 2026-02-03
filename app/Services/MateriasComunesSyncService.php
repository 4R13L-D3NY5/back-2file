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

        // Obtener docente_ids de la asignatura origen (via grupos)
        $sourceDocenteIds = Grupo::where('asignatura_id', $source->id)
            ->whereNotNull('docente_id')
            ->pluck('docente_id')
            ->unique()
            ->toArray();

        if (empty($sourceDocenteIds)) {
            return collect();
        }

        // Buscar asignaturas hermanas (mismo comun_token, diferente id)
        $hermanas = Asignatura::where('comun_token', $source->comun_token)
            ->where('id', '!=', $source->id)
            ->get();

        // Filtrar solo las que tengan al menos un docente en común
        return $hermanas->filter(function ($hermana) use ($sourceDocenteIds) {
            $hermanaDocenteIds = Grupo::where('asignatura_id', $hermana->id)
                ->whereNotNull('docente_id')
                ->pluck('docente_id')
                ->unique()
                ->toArray();

            // Verificar si hay intersección de docentes
            return !empty(array_intersect($sourceDocenteIds, $hermanaDocenteIds));
        });
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

        // Eliminar unidades huérfanas en destino (que ya no existen en origen)
        $sourceNumeros = $sourceUnidades->pluck('numero')->toArray();
        $target->unidades()->whereNotIn('numero', $sourceNumeros)->delete();
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

        // Eliminar temas huérfanos
        $sourceOrdenes = $sourceTemas->pluck('orden')->toArray();
        $targetUnidad->temas()->whereNotIn('orden', $sourceOrdenes)->delete();
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
}
