<?php

namespace App\Console\Commands;

use App\Models\Asignatura;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RestauracionBancosCommand extends Command
{
    protected $signature = 'restauracion:bancos
                            {--dry-run : Solo previsualizar, no modificar nada}
                            {--parcial=2do Parcial : Filtrar por parcial (default: 2do Parcial)}
                            {--limit= : Limitar cantidad de preguntas a procesar}';

    protected $description = 'Restaurar bancos de preguntas huérfanos o mal asignados (backup más antiguo válido)';

    const BACKUP_DBS = [
        'academicolunes',
        'academicomartes',
        'academicomiercoles',
        'academicojueves',
        'academicoviernes',
    ];

    const CURRENT_DB = 'academico';

    protected array $log = [];
    protected int $restauradas = 0;
    protected int $sinConsenso = 0;
    protected int $yaCorrectas = 0;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $parcial = $this->option('parcial');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('=== RESTAURACIÓN DE BANCOS DE PREGUNTAS ===');
        $this->info('Modo: ' . ($dryRun ? 'DRY-RUN (sin modificar)' : 'EJECUCIÓN REAL'));
        $this->info("Parcial: {$parcial}");
        $this->info('Estrategia: backup más antiguo válido (lunes → viernes)');
        $this->newLine();

        $huerfanas = $this->findOrphanedQuestions($parcial, $limit);
        $this->info("Paso 1 - Huérfanas: {$huerfanas->count()}");

        $malAsignadas = $this->findMisassignedQuestions($parcial, $limit);
        $this->info("Paso 2 - Mal asignadas: {$malAsignadas->count()}");

        $preguntasIds = $huerfanas->pluck('id')
            ->merge($malAsignadas->pluck('id'))
            ->unique()
            ->values();

        $total = $preguntasIds->count();
        $this->info("Total a procesar: {$total}");
        $this->newLine();

        if ($total === 0) {
            $this->info('No hay preguntas para restaurar.');
            return 0;
        }

        $this->info('Paso 3: Restaurando...');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $cambios = collect();

        foreach ($preguntasIds as $preguntaId) {
            $resultado = $this->votarYResolver($preguntaId, $parcial, $dryRun);
            if ($resultado) {
                $cambios->push($resultado);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('=== RESUMEN ===');
        $this->info("Total procesadas:     {$total}");
        $this->info("Ya estaban correctas: {$this->yaCorrectas}");
        $this->info("Restauradas:          {$this->restauradas}");
        $this->info("Sin consenso:         {$this->sinConsenso}");

        if ($dryRun && $cambios->isNotEmpty()) {
            $this->newLine();
            $this->warn('MODO DRY-RUN: Los cambios NO se aplicaron.');
            $this->warn('Para ejecutar: php artisan restauracion:bancos');
        }

        if ($cambios->isNotEmpty()) {
            $this->newLine();
            $this->info('Detalle de cambios:');
            $this->table(
                ['Pregunta ID', 'Parcial', 'Asig. Anterior', 'Asig. Nueva', 'Origen'],
                $cambios->map(fn($c) => [
                    $c['pregunta_id'],
                    $c['parcial'],
                    $c['old_asignatura'] ?? 'N/A',
                    $c['new_asignatura'],
                    $c['source'] ?? 'N/A',
                ])
            );
        }

        return 0;
    }

    private function findOrphanedQuestions(string $parcial, ?int $limit = null)
    {
        $query = DB::table(self::CURRENT_DB . '.banco_preguntas AS bp')
            ->join(self::CURRENT_DB . '.asignaturas AS a', 'a.id', '=', 'bp.asignatura_id')
            ->whereNotNull('a.deleted_at')
            ->where('bp.parcial', $parcial)
            ->select('bp.id');

        if ($limit) $query->limit($limit);

        return $query->get();
    }

    private function findMisassignedQuestions(string $parcial, ?int $limit = null)
    {
        $ids = collect();

        foreach (self::BACKUP_DBS as $backupDb) {
            $candidatas = $this->findCodigoDifferencesWithBackup($parcial, $backupDb, $limit);
            foreach ($candidatas as $c) {
                if (!$ids->contains($c->id)) {
                    $ids->push($c->id);
                }
            }
        }

        return $ids->isNotEmpty()
            ? DB::table(self::CURRENT_DB . '.banco_preguntas')->whereIn('id', $ids)->select('id')->get()
            : collect();
    }

    private function findCodigoDifferencesWithBackup(string $parcial, string $backupDb, ?int $limit = null)
    {
        $query = DB::table(self::CURRENT_DB . '.banco_preguntas AS bp')
            ->join(self::CURRENT_DB . '.asignaturas AS a', 'a.id', '=', 'bp.asignatura_id')
            ->join("{$backupDb}.banco_preguntas AS bl", 'bl.id', '=', 'bp.id')
            ->join("{$backupDb}.asignaturas AS al", 'al.id', '=', 'bl.asignatura_id')
            ->where('bp.parcial', $parcial)
            ->whereNull('a.deleted_at')
            ->whereNull('al.deleted_at')
            ->whereColumn('a.codigo', '!=', 'al.codigo')
            ->select('bp.id', 'a.codigo as codigo_actual', 'al.codigo as codigo_backup');

        if ($limit) $query->limit($limit);

        return $query->get();
    }

    private function getOldestValidBackup(int $preguntaId): array
    {
        foreach (self::BACKUP_DBS as $backupDb) {
            try {
                $row = DB::table("{$backupDb}.banco_preguntas AS bp")
                    ->join("{$backupDb}.asignaturas AS a", 'a.id', '=', 'bp.asignatura_id')
                    ->where('bp.id', $preguntaId)
                    ->whereNull('a.deleted_at')
                    ->select('bp.asignatura_id', 'a.codigo', 'a.plan_estudios')
                    ->first();

                if ($row && $row->codigo) {
                    return [
                        'encontrado' => true,
                        'codigo' => $row->codigo,
                        'plan' => $row->plan_estudios,
                        'asignatura_id' => $row->asignatura_id,
                        'backup' => $backupDb,
                    ];
                }
            } catch (\Throwable $e) {
                // backup no disponible
            }
        }

        return ['encontrado' => false];
    }

    private function resolverAsignaturaCorrecta(int $preguntaId, $currentAsig, int $sedeId): ?Asignatura
    {
        $backup = $this->getOldestValidBackup($preguntaId);

        $codigo = null;
        $plan = null;

        if ($backup['encontrado']) {
            $codigo = $backup['codigo'];
            $plan = $backup['plan'] ?? 'N';
        } elseif ($currentAsig && $currentAsig->codigo) {
            $codigo = $currentAsig->codigo;
            $plan = $currentAsig->plan_estudios ?: 'N';
        } else {
            return null;
        }

        $asignatura = Asignatura::where('codigo', $codigo)
            ->where('plan_estudios', $plan)
            ->whereNull('deleted_at')
            ->first();

        if (!$asignatura) {
            $restaurada = Asignatura::withTrashed()
                ->where('codigo', $codigo)
                ->where('plan_estudios', $plan)
                ->whereNotNull('deleted_at')
                ->first();

            if ($restaurada) {
                $restaurada->deleted_at = null;
                $restaurada->save();
                Log::info("RestauracionBancos: asignatura restaurada", [
                    'id' => $restaurada->id, 'codigo' => $restaurada->codigo,
                    'plan' => $restaurada->plan_estudios, 'backup' => $backup['backup'] ?? 'ninguno',
                ]);
                $asignatura = $restaurada;
            }
        }

        if (!$asignatura) {
            $asignatura = Asignatura::where('codigo', $codigo)->whereNull('deleted_at')->first();
        }

        if (!$asignatura) return null;

        $this->asegurarPivoteSede($asignatura, $sedeId, $codigo, $plan);

        return $asignatura;
    }

    private function asegurarPivoteSede(Asignatura $asignatura, int $sedeId, string $codigo, ?string $plan): void
    {
        $tiene = DB::table('asignatura_carrera')
            ->where('asignatura_id', $asignatura->id)
            ->where('sede_id', $sedeId)
            ->exists();

        if ($tiene) return;

        $pivoteHermano = DB::table('asignatura_carrera AS ac')
            ->join('asignaturas AS a', 'a.id', '=', 'ac.asignatura_id')
            ->where('a.codigo', $codigo)
            ->where('a.id', '!=', $asignatura->id)
            ->whereNull('a.deleted_at')
            ->where('ac.sede_id', $sedeId)
            ->select('ac.carrera_id', 'ac.semestre')
            ->first();

        if ($pivoteHermano) {
            DB::table('asignatura_carrera')->insert([
                'asignatura_id' => $asignatura->id,
                'carrera_id' => $pivoteHermano->carrera_id,
                'sede_id' => $sedeId,
                'semestre' => $pivoteHermano->semestre,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Log::info("RestauracionBancos: pivote creado", [
                'asignatura_id' => $asignatura->id, 'codigo' => $codigo, 'sede_id' => $sedeId,
            ]);
        }
    }

    private function votarYResolver(int $preguntaId, string $parcial, bool $dryRun): ?array
    {
        $pregunta = DB::table(self::CURRENT_DB . '.banco_preguntas')
            ->where('id', $preguntaId)
            ->select('id', 'asignatura_id', 'parcial', 'docente_id', 'sede_id', 'grupoTeorico')
            ->first();

        if (!$pregunta) return null;

        $currentAsig = DB::table(self::CURRENT_DB . '.asignaturas')
            ->where('id', $pregunta->asignatura_id)
            ->select('id', 'codigo', 'plan_estudios', 'nombre', 'deleted_at')
            ->first();

        $asignaturaCorrecta = $this->resolverAsignaturaCorrecta($preguntaId, $currentAsig, $pregunta->sede_id);

        if (!$asignaturaCorrecta) {
            $this->sinConsenso++;
            Log::warning("RestauracionBancos: sin asignatura para pregunta {$preguntaId}");
            return null;
        }

        if ($pregunta->asignatura_id == $asignaturaCorrecta->id) {
            $this->yaCorrectas++;
            return null;
        }

        $oldAsigInfo = $currentAsig
            ? "{$currentAsig->codigo} (id:{$currentAsig->id})"
            : "id:{$pregunta->asignatura_id}";

        $newAsigInfo = "{$asignaturaCorrecta->codigo} (id:{$asignaturaCorrecta->id})";
        $source = $this->getOldestValidBackup($preguntaId);
        $sourceName = $source['backup'] ?? ($currentAsig->deleted_at ? 'restaurada' : 'actual');

        if (!$dryRun) {
            DB::table(self::CURRENT_DB . '.banco_preguntas')
                ->where('id', $preguntaId)
                ->update(['asignatura_id' => $asignaturaCorrecta->id]);

            $this->migrarConfiguracionesSeguro($pregunta->asignatura_id, $asignaturaCorrecta->id, $parcial);

            Log::info("RestauracionBancos: pregunta {$preguntaId} restaurada", [
                'old' => $pregunta->asignatura_id, 'new' => $asignaturaCorrecta->id,
                'codigo' => $asignaturaCorrecta->codigo, 'source' => $sourceName,
            ]);
        }

        $this->restauradas++;

        return [
            'pregunta_id' => $preguntaId,
            'parcial' => $pregunta->parcial,
            'old_asignatura' => $oldAsigInfo,
            'new_asignatura' => $newAsigInfo,
            'source' => $sourceName,
        ];
    }

    private function migrarConfiguracionesSeguro(int $oldAsigId, int $newAsigId, string $parcial): void
    {
        $configs = DB::table(self::CURRENT_DB . '.banco_preguntas_configuraciones')
            ->where('asignatura_id', $oldAsigId)
            ->where('parcial', $parcial)
            ->get();

        foreach ($configs as $config) {
            $yaExiste = DB::table(self::CURRENT_DB . '.banco_preguntas_configuraciones')
                ->where('asignatura_id', $newAsigId)
                ->where('grupo_teorico', $config->grupo_teorico)
                ->where('parcial', $parcial)
                ->exists();

            if ($yaExiste) {
                Log::info("RestauracionBancos: config duplicada, omitiendo", [
                    'id' => $config->id,
                    'grupo_teorico' => $config->grupo_teorico,
                    'old_asig' => $oldAsigId,
                    'new_asig' => $newAsigId,
                ]);
                continue;
            }

            DB::table(self::CURRENT_DB . '.banco_preguntas_configuraciones')
                ->where('id', $config->id)
                ->update(['asignatura_id' => $newAsigId]);
        }
    }
}
