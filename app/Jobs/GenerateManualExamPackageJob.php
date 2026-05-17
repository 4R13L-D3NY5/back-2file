<?php

namespace App\Jobs;

use App\Models\GeneracionManual;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class GenerateManualExamPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1200;

    public function __construct(
        public int $generacionManualId,
        public ?int $requestedBy = null
    ) {
        $this->onQueue('examenes');
    }

    public function handle(): void
    {
        $registro = GeneracionManual::findOrFail($this->generacionManualId);
        $config = $registro->configuracion_json ?? [];
        $timestamps = $config['timestamps'] ?? [];
        $timestamps['generacion_iniciada'] = now()->toISOString();

        $config['job_status'] = 'processing';
        $config['job_error'] = null;
        $config['timestamps'] = $timestamps;

        $registro->update(['configuracion_json' => $config]);

        try {
            $questions = $config['questions'] ?? [];
            if (empty($questions)) {
                throw new \RuntimeException('No se encontraron preguntas para la generacion manual.');
            }

            $this->assertQuestionsMatchManualContext($questions, [
                'parcial' => $registro->parcial,
            ]);

            $backendBase = base_path();
            $workspaceBase = dirname($backendBase);
            $payloadPath = storage_path('app/tmp/manual-exam-generation-' . $registro->id . '.json');

            if (!is_dir(dirname($payloadPath))) {
                mkdir(dirname($payloadPath), 0777, true);
            }

            $payload = [
                'exam' => [
                    'codigo' => $config['materia_codigo'] ?? 'MANUAL',
                    'materia' => $registro->asignatura_nombre,
                    'docente' => $registro->docente_nombre,
                    'grupo' => $registro->grupo,
                    'sede' => $registro->sede_nombre,
                    'carrera' => $registro->carrera_nombre,
                    'parcial' => $registro->parcial,
                    'fecha_examen' => optional($registro->fecha_examen)?->format('Y-m-d'),
                    'semestre' => $config['semestre'] ?? '',
                    'hora' => $registro->hora,
                    'gestion' => $registro->gestion,
                ],
                'config' => [
                    'cantVariantes' => (int) ($registro->cant_variantes ?: ($config['cantVariantes'] ?? 1)),
                    'facil' => (int) ($config['facil'] ?? 7),
                    'medio' => (int) ($config['medio'] ?? 16),
                    'dificil' => (int) ($config['dificil'] ?? 7),
                    'formatoHoja' => $config['formatoHoja'] ?? 'Oficio (8.5" x 13")',
                    'fontFamily' => $config['fontFamily'] ?? 'helvetica',
                    'fontSize' => (float) ($config['fontSize'] ?? 11),
                    'lineSpacing' => (float) ($config['lineSpacing'] ?? 0.85),
                    'aleatorizarSecciones' => (bool) ($config['aleatorizarSecciones'] ?? true),
                ],
                'questions' => array_values($questions),
                'logoPath' => public_path('descargas/unitepc-logo.png'),
                'output' => [
                    'examenesDir' => storage_path('app/public/examenes_queue'),
                    'patronesDir' => storage_path('app/public/patrones_queue'),
                ],
            ];

            file_put_contents($payloadPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $scriptPath = $workspaceBase . DIRECTORY_SEPARATOR . 'Academico' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'generate-exam-package.mjs';
            $nodeBinary = env('NODE_BINARY', 'node');
            $process = new Process([$nodeBinary, $scriptPath, $payloadPath], $workspaceBase . DIRECTORY_SEPARATOR . 'Academico');
            $process->setTimeout(900);
            $process->mustRun();

            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            $timestamps['generacion_completada'] = now()->toISOString();
            $config['job_status'] = 'completed';
            $config['job_error'] = null;
            $config['timestamps'] = $timestamps;
            $config['archivos_generados'] = [
                'examen_pdf' => $result['examFilename'] ?? null,
                'patron_pdf' => $result['patronPdfFilename'] ?? null,
                'patron_xlsx' => $result['patronXlsxFilename'] ?? null,
            ];

            $registro->update([
                'estado' => 'GENERADO',
                'archivo_examen' => $result['examFilename'] ?? null,
                'archivo_patron_pdf' => $result['patronPdfFilename'] ?? null,
                'archivos_patron_xlsx' => array_values(array_filter([$result['patronXlsxFilename'] ?? null])),
                'patron_respuestas_json' => $result['audit'] ?? $registro->patron_respuestas_json,
                'configuracion_json' => $config,
            ]);

            @unlink($payloadPath);
        } catch (\Throwable $e) {
            Log::error('GenerateManualExamPackageJob failed', [
                'generacion_manual_id' => $this->generacionManualId,
                'message' => $e->getMessage(),
            ]);

            $timestamps['generacion_error'] = now()->toISOString();
            $config['job_status'] = 'failed';
            $config['job_error'] = $e->getMessage();
            $config['timestamps'] = $timestamps;

            $registro->update(['configuracion_json' => $config]);

            throw $e;
        }
    }

    private function assertQuestionsMatchManualContext(array $questions, array $context): void
    {
        $expectedPartial = $this->normalizePartial($context['parcial'] ?? null);

        $invalid = collect($questions)->filter(function ($question) use ($expectedPartial) {
            $questionPartial = $this->normalizePartial($question['parcial'] ?? null);

            return $expectedPartial && $questionPartial && $questionPartial !== $expectedPartial;
        })->values();

        if ($invalid->isEmpty()) {
            return;
        }

        $sample = $invalid->take(5)->map(function ($question) {
            return sprintf(
                '#%s[g:%s p:%s]',
                $question['id'] ?? $question['idx'] ?? '?',
                $question['grupoTeorico'] ?? $question['grupo'] ?? '',
                $question['parcial'] ?? ''
            );
        })->implode(', ');

        throw new \RuntimeException(
            'Se detectaron preguntas fuera del parcial de la generacion manual: ' . $sample
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
            '1Â° parcial' => '1er Parcial',
            '1p' => '1er Parcial',
            '2do parcial' => '2do Parcial',
            'segundo parcial' => '2do Parcial',
            '2 parcial' => '2do Parcial',
            '2° parcial' => '2do Parcial',
            '2Â° parcial' => '2do Parcial',
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
}
