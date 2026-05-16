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
            $questions = $this->loadQuestions($examen);

            if ($questions->isEmpty()) {
                throw new \RuntimeException('No se encontraron preguntas del banco para este examen.');
            }

            $backendBase = base_path();
            $workspaceBase = dirname($backendBase);
            $payloadPath = storage_path('app/tmp/exam-generation-' . $examen->id . '.json');

            if (!is_dir(dirname($payloadPath))) {
                mkdir(dirname($payloadPath), 0777, true);
            }

            $payload = [
                'exam' => [
                    'codigo' => $examen->materia_codigo,
                    'materia' => $examen->materia_nombre,
                    'docente' => $this->resolveDocenteName($examen),
                    'grupo' => $examen->grupo,
                    'sede' => $this->resolveSedeName($examen),
                    'carrera' => $this->resolveCarreraName($examen),
                    'parcial' => $examen->tipo_examen,
                    'fecha_examen' => optional($examen->fecha)?->format('Y-m-d'),
                    'semestre' => optional($examen->asignatura?->carreras?->firstWhere('id', $examen->carrera_id)?->pivot)->semestre ?? '',
                    'hora' => trim(($examen->hora_inicio ?? '') . ' - ' . ($examen->hora_fin ?? '')),
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

            $scriptPath = $workspaceBase . DIRECTORY_SEPARATOR . 'Academico' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'generate-exam-package.mjs';
            $process = new Process(['node', $scriptPath, $payloadPath], $workspaceBase . DIRECTORY_SEPARATOR . 'Academico');
            $process->setTimeout(900);
            $process->mustRun();

            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            $timestamps['generacion_completada'] = now()->toISOString();
            $config['job_status'] = 'completed';
            $config['job_error'] = null;
            $config['pattern_audit'] = $result['audit'] ?? [];
            $variantes = collect($result['variantes'] ?? [])->map(function ($item) {
                if (!is_array($item)) {
                    return $item;
                }

                $item['path'] = 'tmp/examenes/' . $item['archivo'];
                return $item;
            })->values()->all();
            $patrones = collect($result['patrones'] ?? [])->map(function ($item) {
                if (!is_array($item)) {
                    return $item;
                }

                if (!empty($item['pdf'])) {
                    $item['pdf_path'] = 'tmp/patrones/' . $item['pdf'];
                }

                if (!empty($item['xlsx'])) {
                    $item['xlsx_path'] = 'tmp/patrones/' . $item['xlsx'];
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

    private function loadQuestions(RolExamen $examen)
    {
        $normalizedGroup = $this->normalizeGroup($examen->grupo);
        $partial = $this->normalizePartial($examen->tipo_examen);
        $asignaturaIds = $this->resolveAsignaturaIds($examen);

        $questions = BancoPregunta::query()
            ->whereIn('asignatura_id', $asignaturaIds)
            ->where('parcial', $partial)
            ->where(function ($query) use ($examen, $normalizedGroup) {
                $query->where('grupoTeorico', $examen->grupo)
                    ->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupoTeorico), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                        [$normalizedGroup]
                    );
            })
            ->orderBy('id')
            ->get();

        $this->assertQuestionsMatchExamContext($questions, $asignaturaIds, $normalizedGroup, $partial);

        return $questions
            ->map(function (BancoPregunta $question) {
                $imagePath = $question->imagen
                    ? storage_path('app/public/preguntas/' . $question->imagen)
                    : null;

                return [
                    'id' => $question->id,
                    'asignatura_id' => $question->asignatura_id,
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

    private function assertQuestionsMatchExamContext($questions, array $asignaturaIds, string $normalizedGroup, string $partial): void
    {
        $invalid = $questions->filter(function (BancoPregunta $question) use ($asignaturaIds, $normalizedGroup, $partial) {
            $questionGroup = $this->normalizeGroup($question->grupoTeorico ?: $question->grupo);
            $questionPartial = $this->normalizePartial($question->parcial);

            return !in_array((int) $question->asignatura_id, $asignaturaIds, true)
                || $questionGroup !== $normalizedGroup
                || $questionPartial !== $partial;
        })->values();

        if ($invalid->isEmpty()) {
            return;
        }

        $sample = $invalid->take(5)->map(function (BancoPregunta $question) {
            return sprintf(
                '#%s[a:%s g:%s p:%s]',
                $question->id,
                $question->asignatura_id,
                $question->grupoTeorico ?: $question->grupo,
                $question->parcial
            );
        })->implode(', ');

        throw new \RuntimeException(
            'Se detectaron preguntas fuera del contexto de asignatura, grupo o parcial del examen: ' . $sample
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

    private function resolveAsignaturaIds(RolExamen $examen): array
    {
        $ids = collect();

        if (!empty($examen->asignatura_id)) {
            $ids->push((int) $examen->asignatura_id);
        }

        if (!empty($examen->materia_codigo)) {
            $scopedIds = DB::table('asignaturas')
                ->join('asignatura_carrera', 'asignaturas.id', '=', 'asignatura_carrera.asignatura_id')
                ->where('asignaturas.codigo', $examen->materia_codigo)
                ->where('asignatura_carrera.carrera_id', $examen->carrera_id)
                ->where('asignatura_carrera.sede_id', $examen->sede_id)
                ->where('asignaturas.estado', '!=', 'cancelado')
                ->when((int) $examen->sede_id === 1, function ($query) {
                    $query->where('asignaturas.plan_estudios', 'N');
                })
                ->pluck('asignaturas.id')
                ->map(fn ($id) => (int) $id);

            $ids = $ids->merge($scopedIds);

            if ($scopedIds->isEmpty()) {
                Log::warning('GenerateRolExamenPackageJob: no scoped asignatura ids found, using codigo fallback', [
                    'rol_examen_id' => $examen->id,
                    'materia_codigo' => $examen->materia_codigo,
                    'carrera_id' => $examen->carrera_id,
                    'sede_id' => $examen->sede_id,
                ]);
            }

            $ids = $ids->merge(
                DB::table('asignaturas')
                    ->where('codigo', $examen->materia_codigo)
                    ->when($scopedIds->isNotEmpty(), function ($query) use ($scopedIds) {
                        $query->whereIn('id', $scopedIds->all());
                    })
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
            );
        }

        return $ids->filter()->unique()->values()->all();
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
