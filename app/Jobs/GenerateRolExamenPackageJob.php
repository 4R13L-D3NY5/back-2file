<?php

namespace App\Jobs;

use App\Models\BancoPregunta;
use App\Models\RolExamen;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class GenerateRolExamenPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1200;

    public function __construct(
        public int $rolExamenId,
        public array $config,
        public ?int $requestedBy = null
    ) {
        $this->onQueue('examenes');
    }

    public function handle(): void
    {
        $examen = RolExamen::findOrFail($this->rolExamenId);

        $config = array_merge($examen->config_generacion ?? [], $this->config);
        $timestamps = $examen->timestamps_proceso ?? [];
        $timestamps['generacion_iniciada'] = now()->toISOString();

        $config['job_status'] = 'processing';
        $config['job_error'] = null;
        $examen->update([
            'config_generacion' => $config,
            'timestamps_proceso' => $timestamps,
        ]);

        try {
            $examContext = $this->resolveExamContext($examen);
            $questions = $this->loadQuestions($examen, $examContext);

            if ($questions->isEmpty()) {
                throw new \RuntimeException('No se encontraron preguntas del banco para este examen.');
            }

            $backendBase = base_path();
            $workspaceBase = dirname($backendBase);
            $payloadPath = storage_path('app/tmp/exam-generation-'.$examen->id.'.json');

            if (! is_dir(dirname($payloadPath))) {
                mkdir(dirname($payloadPath), 0777, true);
            }

            $payload = [
                'exam' => [
                    'codigo' => $examen->materia_codigo,
                    'materia' => $examen->materia_nombre,
                    'docente' => $examContext['docente_nombre'] ?? '',
                    'grupo' => $examen->grupo,
                    'sede' => $this->resolveSedeName($examen),
                    'carrera' => $this->resolveCarreraName($examen),
                    'parcial' => $examen->tipo_examen,
                    'fecha_examen' => optional($examen->fecha)?->format('Y-m-d'),
                    'semestre' => $examContext['semestre'] ?? '',
                    'hora' => trim(($examen->hora_inicio ?? '').' - '.($examen->hora_fin ?? '')),
                    'gestion' => $examen->gestion,
                ],
                'config' => [
                    'cantVariantes' => (int) ($config['cantVariantes'] ?? 1),
                    'facil' => (int) ($config['facil'] ?? 7),
                    'medio' => (int) ($config['medio'] ?? 16),
                    'dificil' => (int) ($config['dificil'] ?? 7),
                    'formatoHoja' => $config['formatoHoja'] ?? 'Oficio (8.5" x 13")',
                    'fontFamily' => $config['fontFamily'] ?? 'helvetica',
                    'fontSize' => (float) ($config['fontSize'] ?? 11),
                    'lineSpacing' => (float) ($config['lineSpacing'] ?? 0.85),
                    'aleatorizarSecciones' => (bool) ($config['aleatorizarSecciones'] ?? true),
                ],
                'questions' => $questions->values()->all(),
                'logoPath' => public_path('descargas/unitepc-logo.png'),
                'output' => [
                    'examenesDir' => storage_path('app/tmp/examenes'),
                    'patronesDir' => storage_path('app/tmp/patrones'),
                ],
            ];

            file_put_contents($payloadPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $scriptPath = $workspaceBase.DIRECTORY_SEPARATOR.'Academico'.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'generate-exam-package.mjs';
            $process = new Process(['node', $scriptPath, $payloadPath], $workspaceBase.DIRECTORY_SEPARATOR.'Academico');
            $process->setTimeout(900);
            $process->mustRun();

            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            $timestamps['generacion_completada'] = now()->toISOString();
            $config['job_status'] = 'completed';
            $config['job_error'] = null;
            $config['pattern_audit'] = $result['audit'] ?? [];
            $variantes = collect($result['variantes'] ?? [])->map(function ($item) {
                if (! is_array($item)) {
                    return $item;
                }

                $item['path'] = 'tmp/examenes/'.$item['archivo'];

                return $item;
            })->values()->all();
            $patrones = collect($result['patrones'] ?? [])->map(function ($item) {
                if (! is_array($item)) {
                    return $item;
                }

                if (! empty($item['pdf'])) {
                    $item['pdf_path'] = 'tmp/patrones/'.$item['pdf'];
                }

                if (! empty($item['xlsx'])) {
                    $item['xlsx_path'] = 'tmp/patrones/'.$item['xlsx'];
                }

                return $item;
            })->values()->all();

            $examen->update([
                'estado' => 'generados',
                'variantes' => $variantes,
                'patrones' => $patrones,
                'config_generacion' => $config,
                'timestamps_proceso' => array_merge($timestamps, ['generados' => now()->toISOString()]),
            ]);

            @unlink($payloadPath);
        } catch (\Throwable $e) {
            Log::error('GenerateRolExamenPackageJob failed', [
                'rol_examen_id' => $this->rolExamenId,
                'message' => $e->getMessage(),
            ]);

            $config['job_status'] = 'failed';
            $config['job_error'] = $e->getMessage();
            $timestamps['generacion_error'] = now()->toISOString();

            $examen->update([
                'config_generacion' => $config,
                'timestamps_proceso' => $timestamps,
            ]);

            throw $e;
        }
    }

    private function loadQuestions(RolExamen $examen, array $examContext)
    {
        $normalizedGroup = $this->normalizeGroup($examen->grupo);
        $partial = $this->normalizePartial($examen->tipo_examen);
        $asignaturaId = (int) $examContext['asignatura_id'];
        $docenteId = ! empty($examContext['docente_id']) ? (int) $examContext['docente_id'] : null;

        $questions = BancoPregunta::query()
            ->where('asignatura_id', $asignaturaId)
            ->where('sede_id', $examen->sede_id)
            ->where('parcial', $partial)
            ->when($docenteId, function ($query) use ($docenteId) {
                $query->where('docente_id', $docenteId);
            })
            ->where(function ($query) use ($examen, $normalizedGroup) {
                $query->where('grupoTeorico', $examen->grupo)
                    ->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupoTeorico), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                        [$normalizedGroup]
                    )
                    ->orWhere(function ($legacy) use ($examen, $normalizedGroup) {
                        $legacy->where(function ($emptyGrupoTeorico) {
                            $emptyGrupoTeorico->whereNull('grupoTeorico')
                                ->orWhere('grupoTeorico', '');
                        })->where(function ($legacyGrupo) use ($examen, $normalizedGroup) {
                            $legacyGrupo->where('grupo', $examen->grupo)
                                ->orWhereRaw(
                                    "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupo), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                                    [$normalizedGroup]
                                );
                        });
                    });
            })
            ->orderBy('id')
            ->get();

        $this->assertQuestionsMatchExamContext(
            $questions,
            $asignaturaId,
            (int) $examen->sede_id,
            $docenteId,
            $normalizedGroup,
            $partial
        );

        return $questions
            ->map(function (BancoPregunta $question) {
                $imagePath = $question->imagen
                    ? storage_path('app/public/preguntas/'.$question->imagen)
                    : null;

                return [
                    'id' => $question->id,
                    'asignatura_id' => $question->asignatura_id,
                    'sede_id' => $question->sede_id,
                    'docente_id' => $question->docente_id,
                    'enunciado' => $question->enunciado,
                    'tipo' => $question->tipo,
                    'grupo' => $question->grupo,
                    'grupoTeorico' => $question->grupoTeorico,
                    'opciones' => $question->opciones ?? [],
                    'respuesta_correcta' => $question->respuesta_correcta ?? [],
                    'dificultad' => $question->dificultad,
                    'parcial' => $question->parcial,
                    'imagen' => $question->imagen,
                    'imagePath' => $imagePath && file_exists($imagePath) ? $imagePath : null,
                ];
            });
    }

    private function assertQuestionsMatchExamContext(
        $questions,
        int $asignaturaId,
        int $sedeId,
        ?int $docenteId,
        string $normalizedGroup,
        string $partial
    ): void {
        $invalid = $questions->filter(function (BancoPregunta $question) use ($asignaturaId, $sedeId, $docenteId, $normalizedGroup, $partial) {
            $questionGroup = $this->normalizeGroup($question->grupoTeorico ?: $question->grupo);
            $questionPartial = $this->normalizePartial($question->parcial);

            return (int) $question->asignatura_id !== $asignaturaId
                || (int) $question->sede_id !== $sedeId
                || ($docenteId && (int) $question->docente_id !== $docenteId)
                || $questionGroup !== $normalizedGroup
                || $questionPartial !== $partial;
        })->values();

        if ($invalid->isEmpty()) {
            return;
        }

        $sample = $invalid->take(5)->map(function (BancoPregunta $question) {
            return sprintf(
                '#%s[a:%s s:%s d:%s g:%s p:%s]',
                $question->id,
                $question->asignatura_id,
                $question->sede_id,
                $question->docente_id,
                $question->grupoTeorico ?: $question->grupo,
                $question->parcial
            );
        })->implode(', ');

        throw new \RuntimeException(
            'Se detectaron preguntas fuera del contexto de asignatura, sede, docente, grupo o parcial del examen: '.$sample
        );
    }

    private function normalizeGroup(?string $value): string
    {
        $value = strtoupper(trim((string) $value));

        return str_replace(['G. ', 'GRUPO ', 'G-', 'G'], '', $value);
    }

    private function normalizePartial(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return [
            '1er parcial' => '1er Parcial',
            'primer parcial' => '1er Parcial',
            '1 parcial' => '1er Parcial',
            '1° parcial' => '1er Parcial',
            '1p' => '1er Parcial',
            '2do parcial' => '2do Parcial',
            'segundo parcial' => '2do Parcial',
            '2 parcial' => '2do Parcial',
            '2° parcial' => '2do Parcial',
            '2p' => '2do Parcial',
            'final' => 'Final',
            'ef' => 'Final',
            'examen final' => 'Final',
            '2da instancia' => '2da Instancia',
            'segunda instancia' => '2da Instancia',
            'segunda' => '2da Instancia',
            '2i' => '2da Instancia',
        ][$value] ?? (string) $value;
    }

    private function resolveExamContext(RolExamen $examen): array
    {
        $normalizedGroup = $this->normalizeGroup($examen->grupo);
        $partial = $this->normalizePartial($examen->tipo_examen);

        $groupRows = $this->queryGroupContext($examen, $normalizedGroup, true)->get();

        if ($groupRows->isEmpty()) {
            $groupRows = $this->queryGroupContext($examen, $normalizedGroup, false)->get();
        }

        $candidates = $groupRows
            ->map(function ($row) use ($examen, $normalizedGroup, $partial) {
                $row->preguntas_banco = $this->countBancoPreguntasForContext(
                    (int) $row->asignatura_id,
                    $row->docente_id ? (int) $row->docente_id : null,
                    (int) $examen->sede_id,
                    $normalizedGroup,
                    $partial
                );

                return $row;
            })
            ->sortByDesc('preguntas_banco')
            ->values();

        $withBank = $candidates->filter(fn ($row) => (int) $row->preguntas_banco > 0)->values();

        if ($withBank->count() === 1 || ($withBank->count() > 1 && (int) $withBank[0]->preguntas_banco > (int) $withBank[1]->preguntas_banco)) {
            return $this->contextPayload($withBank->first());
        }

        if ($candidates->count() === 1) {
            return $this->contextPayload($candidates->first());
        }

        $bankContext = $this->resolveContextFromBanco($examen, $normalizedGroup, $partial);
        if ($bankContext) {
            return $bankContext;
        }

        Log::warning('GenerateRolExamenPackageJob: unable to resolve unique exam context', [
            'rol_examen_id' => $examen->id,
            'materia_codigo' => $examen->materia_codigo,
            'grupo' => $examen->grupo,
            'carrera_id' => $examen->carrera_id,
            'sede_id' => $examen->sede_id,
            'gestion' => $examen->gestion,
            'candidates' => $candidates->map(fn ($row) => [
                'asignatura_id' => $row->asignatura_id,
                'docente_id' => $row->docente_id,
                'preguntas_banco' => $row->preguntas_banco,
            ])->all(),
        ]);

        throw new \RuntimeException(
            'No se pudo determinar una unica asignatura/docente del plan correcto para generar el examen.'
        );
    }

    private function queryGroupContext(RolExamen $examen, string $normalizedGroup, bool $filterGestion)
    {
        return DB::table('grupos')
            ->join('asignaturas', 'grupos.asignatura_id', '=', 'asignaturas.id')
            ->join('asignatura_carrera', function ($join) use ($examen) {
                $join->on('asignaturas.id', '=', 'asignatura_carrera.asignatura_id')
                    ->where('asignatura_carrera.carrera_id', $examen->carrera_id)
                    ->where('asignatura_carrera.sede_id', $examen->sede_id);
            })
            ->leftJoin('docentes', 'grupos.docente_id', '=', 'docentes.id')
            ->where('asignaturas.codigo', $examen->materia_codigo)
            ->where('asignaturas.estado', '!=', 'cancelado')
            ->where('grupos.carrera_id', $examen->carrera_id)
            ->where('grupos.sede_id', $examen->sede_id)
            ->where('grupos.estado', 'ACTIVO')
            ->whereNull('grupos.deleted_at')
            ->when($filterGestion && ! empty($examen->gestion), function ($query) use ($examen) {
                $query->where('grupos.gestion', $examen->gestion);
            })
            ->where(function ($query) use ($examen, $normalizedGroup) {
                $query->where('grupos.nombre', $examen->grupo)
                    ->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(UPPER(grupos.nombre), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                        [$normalizedGroup]
                    );
            })
            ->select(
                'grupos.asignatura_id',
                'grupos.docente_id',
                'docentes.nombre_completo as docente_nombre',
                'asignatura_carrera.semestre'
            );
    }

    private function countBancoPreguntasForContext(
        int $asignaturaId,
        ?int $docenteId,
        int $sedeId,
        string $normalizedGroup,
        string $partial
    ): int {
        return BancoPregunta::query()
            ->where('asignatura_id', $asignaturaId)
            ->where('sede_id', $sedeId)
            ->where('parcial', $partial)
            ->when($docenteId, function ($query) use ($docenteId) {
                $query->where('docente_id', $docenteId);
            })
            ->where(function ($query) use ($normalizedGroup) {
                $query->whereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupoTeorico), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                    [$normalizedGroup]
                )->orWhere(function ($legacy) use ($normalizedGroup) {
                    $legacy->where(function ($emptyGrupoTeorico) {
                        $emptyGrupoTeorico->whereNull('grupoTeorico')
                            ->orWhere('grupoTeorico', '');
                    })->whereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupo), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                        [$normalizedGroup]
                    );
                });
            })
            ->count();
    }

    private function resolveContextFromBanco(RolExamen $examen, string $normalizedGroup, string $partial): ?array
    {
        $scopedAsignaturaIds = DB::table('asignaturas')
            ->join('asignatura_carrera', 'asignaturas.id', '=', 'asignatura_carrera.asignatura_id')
            ->where('asignaturas.codigo', $examen->materia_codigo)
            ->where('asignatura_carrera.carrera_id', $examen->carrera_id)
            ->where('asignatura_carrera.sede_id', $examen->sede_id)
            ->where('asignaturas.estado', '!=', 'cancelado')
            ->pluck('asignaturas.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($scopedAsignaturaIds->isEmpty()) {
            return null;
        }

        $rows = BancoPregunta::query()
            ->select('asignatura_id', 'docente_id', DB::raw('COUNT(*) as total'))
            ->whereIn('asignatura_id', $scopedAsignaturaIds->all())
            ->where('sede_id', $examen->sede_id)
            ->where('parcial', $partial)
            ->where(function ($query) use ($normalizedGroup) {
                $query->whereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupoTeorico), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                    [$normalizedGroup]
                )->orWhere(function ($legacy) use ($normalizedGroup) {
                    $legacy->where(function ($emptyGrupoTeorico) {
                        $emptyGrupoTeorico->whereNull('grupoTeorico')
                            ->orWhere('grupoTeorico', '');
                    })->whereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupo), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                        [$normalizedGroup]
                    );
                });
            })
            ->groupBy('asignatura_id', 'docente_id')
            ->orderByDesc('total')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        if ($rows->count() > 1 && (int) $rows[0]->total === (int) $rows[1]->total) {
            return null;
        }

        $row = $rows->first();
        $docenteNombre = $row->docente_id
            ? DB::table('docentes')->where('id', $row->docente_id)->value('nombre_completo')
            : null;
        $semestre = DB::table('asignatura_carrera')
            ->where('asignatura_id', $row->asignatura_id)
            ->where('carrera_id', $examen->carrera_id)
            ->where('sede_id', $examen->sede_id)
            ->value('semestre');

        return [
            'asignatura_id' => (int) $row->asignatura_id,
            'docente_id' => $row->docente_id ? (int) $row->docente_id : null,
            'docente_nombre' => $docenteNombre ? (string) $docenteNombre : '',
            'semestre' => $semestre ? (string) $semestre : '',
        ];
    }

    private function contextPayload($row): array
    {
        return [
            'asignatura_id' => (int) $row->asignatura_id,
            'docente_id' => $row->docente_id ? (int) $row->docente_id : null,
            'docente_nombre' => $row->docente_nombre ? (string) $row->docente_nombre : '',
            'semestre' => $row->semestre ? (string) $row->semestre : '',
        ];
    }

    private function resolveAsignaturaId(RolExamen $examen): int
    {
        if (! empty($examen->asignatura_id)) {
            return (int) $examen->asignatura_id;
        }

        return (int) $this->resolveExamContext($examen)['asignatura_id'];
    }

    private function resolveAsignaturaIds(RolExamen $examen): array
    {
        return [$this->resolveAsignaturaId($examen)];
    }

    private function resolveDocenteName(RolExamen $examen): string
    {
        $asignaturaIds = $this->resolveAsignaturaIds($examen);
        $normalizedGroup = $this->normalizeGroup($examen->grupo);

        $docente = DB::table('docentes')
            ->join('grupos', 'docentes.id', '=', 'grupos.docente_id')
            ->whereIn('grupos.asignatura_id', $asignaturaIds)
            ->where('grupos.sede_id', $examen->sede_id)
            ->where('grupos.carrera_id', $examen->carrera_id)
            ->where('grupos.estado', 'ACTIVO')
            ->whereNull('grupos.deleted_at')
            ->where(function ($query) use ($examen, $normalizedGroup) {
                $query->where('grupos.nombre', $examen->grupo)
                    ->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(UPPER(grupos.nombre), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                        [$normalizedGroup]
                    );
            })
            ->orderByRaw('CASE WHEN grupos.nombre = ? THEN 0 ELSE 1 END', [$examen->grupo])
            ->value('docentes.nombre_completo');

        if ($docente) {
            return (string) $docente;
        }

        Log::warning('GenerateRolExamenPackageJob: docente not found in scoped group context', [
            'rol_examen_id' => $examen->id,
            'materia_codigo' => $examen->materia_codigo,
            'grupo' => $examen->grupo,
            'carrera_id' => $examen->carrera_id,
            'sede_id' => $examen->sede_id,
            'asignatura_ids' => $asignaturaIds,
        ]);

        return '';
    }

    private function resolveSedeName(RolExamen $examen): string
    {
        return (string) DB::table('sedes')->where('id', $examen->sede_id)->value('nombre') ?: '';
    }

    private function resolveCarreraName(RolExamen $examen): string
    {
        return (string) DB::table('carreras')->where('id', $examen->carrera_id)->value('nombre') ?: '';
    }
}
