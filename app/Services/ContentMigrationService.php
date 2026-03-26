<?php

namespace App\Services;

use App\Models\Asignatura;
use App\Models\BancoPregunta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para migrar banco de preguntas y documentación
 * entre asignaturas duplicadas durante la sincronización.
 *
 * GARANTÍA PRINCIPAL: el banco de preguntas NUNCA se pierde.
 * Se hace UPDATE (no delete+insert) del asignatura_id y se remapea
 * el logro_esperado_id por unidad.numero + tema.orden.
 */
class ContentMigrationService
{
    /**
     * Calcula un puntaje de contenido para una asignatura.
     * A mayor puntaje, más contenido tiene.
     */
    public function scoreAsignatura(Asignatura $a): int
    {
        $score = 0;

        // Banco de preguntas (lo más importante)
        $score += BancoPregunta::where('asignatura_id', $a->id)->count() * 5;

        // Carga relaciones si no están cargadas
        if (!$a->relationLoaded('unidades')) {
            $a->load(['unidades.temas.logros']);
        }
        if (!$a->relationLoaded('bibliografias')) {
            $a->load('bibliografias');
        }

        // Unidades y temas
        foreach ($a->unidades as $unidad) {
            $score += 3;
            foreach ($unidad->temas as $tema) {
                $score += 2;
                if (!empty($tema->resultado_aprendizaje)) $score++;
                if (!empty($tema->contenido_conceptual))  $score++;
            }
        }

        // Bibliografías
        $score += $a->bibliografias->count() * 2;

        // Campos PAC
        $pacFields = ['justificacion', 'proposito_general', 'competencia_asignatura',
                      'competencia_global_especifica', 'metodologia_general', 'sistema_evaluacion'];
        foreach ($pacFields as $field) {
            if (!empty($a->$field)) $score += 10;
        }

        return $score;
    }

    /**
     * Migra el banco de preguntas de $origen a $destino.
     * - Actualiza asignatura_id
     * - Remapea logro_esperado_id usando unidad.numero + tema.orden
     * - Si no hay logro equivalente, pone logro_esperado_id = NULL (no se pierde la pregunta)
     *
     * Retorna array con estadísticas de la migración.
     */
    public function migrarBancoPreguntas(Asignatura $origen, Asignatura $destino): array
    {
        $preguntas = BancoPregunta::where('asignatura_id', $origen->id)->get();

        if ($preguntas->isEmpty()) {
            return ['migradas' => 0, 'sin_logro' => 0];
        }

        // Construir mapa de logros del destino: "unidadNum_temaOrden_tipoLogro" => logro_id
        $mapaLogrosDestino = $this->construirMapaLogros($destino);

        // Construir mapa de logros del origen para saber qué hay en el origen
        $mapaLogrosOrigen = $this->construirMapaLogrosConId($origen);

        $migradas = 0;
        $sinLogro = 0;

        foreach ($preguntas as $pregunta) {
            $nuevoLogroId = null;

            if ($pregunta->logro_esperado_id) {
                // Buscar la clave del logro origen en el mapa origen
                $claveOrigen = $mapaLogrosOrigen[$pregunta->logro_esperado_id] ?? null;

                if ($claveOrigen && isset($mapaLogrosDestino[$claveOrigen])) {
                    $nuevoLogroId = $mapaLogrosDestino[$claveOrigen];
                } else {
                    // No hay logro equivalente → poner NULL (pregunta se mantiene, solo sin logro)
                    $nuevoLogroId = null;
                    $sinLogro++;
                }
            }

            $pregunta->asignatura_id      = $destino->id;
            $pregunta->logro_esperado_id  = $nuevoLogroId;
            $pregunta->save();
            $migradas++;
        }

        Log::info("ContentMigrationService: migradas {$migradas} preguntas de asignatura {$origen->id} a {$destino->id}, {$sinLogro} sin logro equivalente");

        return ['migradas' => $migradas, 'sin_logro' => $sinLogro];
    }

    /**
     * Migra evaluaciones de $origen a $destino.
     */
    public function migrarEvaluaciones(Asignatura $origen, Asignatura $destino): int
    {
        $count = DB::table('evaluaciones')
            ->where('asignatura_id', $origen->id)
            ->update(['asignatura_id' => $destino->id]);

        Log::info("ContentMigrationService: migradas {$count} evaluaciones de asignatura {$origen->id} a {$destino->id}");

        return $count;
    }

    /**
     * Migra documentación (unidades, temas, logros, bibliografías, campos PAC)
     * desde $origen a $destino SOLO si el destino está vacío en ese aspecto.
     * Si ambos tienen contenido, se respeta el del destino.
     */
    public function migrarDocumentacion(Asignatura $origen, Asignatura $destino): array
    {
        $resultado = ['campos_pac' => 0, 'unidades' => 0, 'bibliografias' => 0];

        // ── Campos PAC ────────────────────────────────────────────────────────
        $pacFields = [
            'justificacion', 'proposito_general', 'competencia_asignatura',
            'competencia_global_especifica', 'elementos_competencia',
            'metodologia_general', 'sistema_evaluacion', 'contenido_minimo',
            'descripcion', 'requisitos', 'reglamento_normativa',
            'organizacion_calendario', 'docente_formacion',
            'docente_telefono', 'docente_email',
        ];

        $cambiosPac = [];
        foreach ($pacFields as $field) {
            if (empty($destino->$field) && !empty($origen->$field)) {
                $cambiosPac[$field] = $origen->$field;
                $resultado['campos_pac']++;
            }
        }
        if (!empty($cambiosPac)) {
            $destino->fill($cambiosPac);
            $destino->save();
        }

        // ── Bibliografías ──────────────────────────────────────────────────────
        $origen->load('bibliografias');
        $destino->load('bibliografias');

        if ($origen->bibliografias->isNotEmpty() && $destino->bibliografias->isEmpty()) {
            foreach ($origen->bibliografias as $bib) {
                DB::table('bibliografias')->insert([
                    'titulo'        => $bib->titulo,
                    'autor'         => $bib->autor,
                    'editorial'     => $bib->editorial,
                    'edicion'       => $bib->edicion,
                    'anio'          => $bib->anio,
                    'tipo'          => $bib->tipo,
                    'isbn'          => $bib->isbn,
                    'paginas'       => $bib->paginas,
                    'descripcion'   => $bib->descripcion,
                    'asignatura_id' => $destino->id,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
                $resultado['bibliografias']++;
            }
        }

        // ── Estructura analítica (unidades → temas → logros) ──────────────────
        $origen->load(['unidades.temas.logros.indicadores']);
        $destino->load(['unidades']);

        if ($origen->unidades->isNotEmpty() && $destino->unidades->isEmpty()) {
            foreach ($origen->unidades as $unidad) {
                $nuevaUnidad = DB::table('unidades')->insertGetId([
                    'numero'              => $unidad->numero,
                    'titulo'              => $unidad->titulo,
                    'objetivo'            => $unidad->objetivo,
                    'contenido_minimo'    => $unidad->contenido_minimo,
                    'elemento_competencia'=> $unidad->elemento_competencia,
                    'tipo'                => $unidad->tipo,
                    'asignatura_id'       => $destino->id,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);

                foreach ($unidad->temas as $tema) {
                    $nuevoTema = DB::table('temas')->insertGetId([
                        'titulo'                      => $tema->titulo,
                        'orden'                       => $tema->orden,
                        'unidad_id'                   => $nuevaUnidad,
                        'tipo'                        => $tema->tipo,
                        'resultado_aprendizaje'       => $tema->resultado_aprendizaje,
                        'horas_practicas'             => $tema->horas_practicas,
                        'horas_teoricas'              => $tema->horas_teoricas,
                        'contenido_items'             => is_array($tema->contenido_items) ? json_encode($tema->contenido_items) : $tema->contenido_items,
                        'contenido_conceptual'        => is_array($tema->contenido_conceptual) ? json_encode($tema->contenido_conceptual) : $tema->contenido_conceptual,
                        'contenido_procedimental'     => is_array($tema->contenido_procedimental) ? json_encode($tema->contenido_procedimental) : $tema->contenido_procedimental,
                        'contenido_actitudinal'       => is_array($tema->contenido_actitudinal) ? json_encode($tema->contenido_actitudinal) : $tema->contenido_actitudinal,
                        'estrategias_metodologicas'   => $tema->estrategias_metodologicas,
                        'estrategias_aprendizaje'     => $tema->estrategias_aprendizaje,
                        'estrategias_recursos'        => is_array($tema->estrategias_recursos) ? json_encode($tema->estrategias_recursos) : $tema->estrategias_recursos,
                        'evaluacion_formativa'        => is_array($tema->evaluacion_formativa) ? json_encode($tema->evaluacion_formativa) : $tema->evaluacion_formativa,
                        'evaluacion_sumativa'         => is_array($tema->evaluacion_sumativa) ? json_encode($tema->evaluacion_sumativa) : $tema->evaluacion_sumativa,
                        'created_at'                  => now(),
                        'updated_at'                  => now(),
                    ]);

                    foreach ($tema->logros as $logro) {
                        $nuevoLogro = DB::table('logros_esperados')->insertGetId([
                            'descripcion'      => $logro->descripcion,
                            'tipo_logro'       => $logro->tipo_logro,
                            'periodo'          => $logro->periodo,
                            'tema_id'          => $nuevoTema,
                            'created_at'       => now(),
                            'updated_at'       => now(),
                        ]);

                        foreach ($logro->indicadores as $indicador) {
                            DB::table('indicadores')->insert([
                                'descripcion'       => $indicador->descripcion,
                                'logro_esperado_id' => $nuevoLogro,
                                'created_at'        => now(),
                                'updated_at'        => now(),
                            ]);
                        }
                    }
                }
                $resultado['unidades']++;
            }
        }

        return $resultado;
    }

    /**
     * Proceso completo de fusión de duplicados.
     * Determina cuál asignatura tiene más contenido y migra
     * banco de preguntas y documentación al destino (asignatura correcta de la API).
     *
     * @param Asignatura $correcta  La asignatura correcta (viene de la API)
     * @param Asignatura $duplicada La asignatura duplicada/incorrecta
     * @return array Detalle de qué se migró
     */
    public function fusionarDuplicados(Asignatura $correcta, Asignatura $duplicada): array
    {
        $scoreCorrecta  = $this->scoreAsignatura($correcta);
        $scoreDuplicada = $this->scoreAsignatura($duplicada);

        Log::info("ContentMigrationService::fusionarDuplicados", [
            'correcta'        => ['id' => $correcta->id, 'codigo' => $correcta->codigo, 'score' => $scoreCorrecta],
            'duplicada'       => ['id' => $duplicada->id, 'codigo' => $duplicada->codigo, 'score' => $scoreDuplicada],
        ]);

        $resultado = [
            'correcta_id'    => $correcta->id,
            'correcta_nombre'=> $correcta->nombre,
            'duplicada_id'   => $duplicada->id,
            'duplicada_nombre'=> $duplicada->nombre,
            'score_correcta' => $scoreCorrecta,
            'score_duplicada'=> $scoreDuplicada,
            'preguntas'      => [],
            'evaluaciones'   => 0,
            'documentacion'  => [],
        ];

        // Siempre migrar banco de preguntas de la duplicada a la correcta
        $resultado['preguntas'] = $this->migrarBancoPreguntas($duplicada, $correcta);

        // Siempre migrar evaluaciones de la duplicada a la correcta
        $resultado['evaluaciones'] = $this->migrarEvaluaciones($duplicada, $correcta);

        // Documentación: migrar de la que tiene menos contenido a la que tiene más
        // Si la duplicada tiene más documentación → migrarla a la correcta
        if ($scoreDuplicada > $scoreCorrecta) {
            $resultado['documentacion'] = $this->migrarDocumentacion($duplicada, $correcta);
        } else {
            // La correcta ya tiene mejor contenido; migrar lo que le falte de la duplicada
            $resultado['documentacion'] = $this->migrarDocumentacion($duplicada, $correcta);
        }

        return $resultado;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVADOS: construcción de mapas de logros para remapeo de FK
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construye un mapa "logro_id → clave_posicional" para la asignatura origen.
     * Clave posicional = "unidadNum|temaOrden|logroTipo|logroIndex"
     */
    private function construirMapaLogrosConId(Asignatura $asignatura): array
    {
        $mapa = [];
        $asignatura->load(['unidades.temas.logros']);

        foreach ($asignatura->unidades as $unidad) {
            foreach ($unidad->temas as $tema) {
                $logroIndex = 0;
                foreach ($tema->logros as $logro) {
                    $clave = "{$unidad->numero}|{$tema->orden}|{$logro->tipo_logro}|{$logroIndex}";
                    $mapa[$logro->id] = $clave;
                    $logroIndex++;
                }
            }
        }

        return $mapa;
    }

    /**
     * Construye un mapa "clave_posicional → logro_id" para la asignatura destino.
     * Clave posicional = "unidadNum|temaOrden|logroTipo|logroIndex"
     */
    private function construirMapaLogros(Asignatura $asignatura): array
    {
        $mapa = [];
        $asignatura->load(['unidades.temas.logros']);

        foreach ($asignatura->unidades as $unidad) {
            foreach ($unidad->temas as $tema) {
                $logroIndex = 0;
                foreach ($tema->logros as $logro) {
                    $clave = "{$unidad->numero}|{$tema->orden}|{$logro->tipo_logro}|{$logroIndex}";
                    $mapa[$clave] = $logro->id;
                    $logroIndex++;
                }
            }
        }

        return $mapa;
    }
}
