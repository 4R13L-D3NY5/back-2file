<?php

namespace App\Console\Commands;

use App\Models\Asignatura;
use App\Models\Grupo;
use App\Services\MateriasComunesSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando para sincronizar documentación de materias comunes existentes.
 * 
 * Detecta materias vinculadas (comun_token) con el mismo docente
 * y sincroniza la documentación tomando como "Master" la que tiene
 * mayor porcentaje de avance.
 */
class SyncMateriasComunes extends Command
{
    protected $signature = 'sync:materias-comunes 
                            {--dry-run : Mostrar qué se sincronizaría sin ejecutar cambios}
                            {--force : Forzar sincronización incluso si ya están sincronizadas}';

    protected $description = 'Sincroniza la documentación entre materias comunes que comparten el mismo docente';

    protected MateriasComunesSyncService $syncService;

    public function __construct(MateriasComunesSyncService $syncService)
    {
        parent::__construct();
        $this->syncService = $syncService;
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('🔄 Iniciando sincronización de Materias Comunes...');
        $this->newLine();

        if ($dryRun) {
            $this->warn('⚠️  MODO DRY-RUN: No se ejecutarán cambios reales.');
            $this->newLine();
        }

        // Obtener todas las asignaturas con comun_token
        $asignaturasConVinculacion = Asignatura::whereNotNull('comun_token')
            ->orderBy('comun_token')
            ->get();

        if ($asignaturasConVinculacion->isEmpty()) {
            $this->info('No se encontraron materias vinculadas (comun_token).');
            return 0;
        }

        // Agrupar por comun_token
        $grupos = $asignaturasConVinculacion->groupBy('comun_token');

        $this->info("📚 Encontrados {$grupos->count()} grupos de materias vinculadas.");
        $this->newLine();

        $totalSincronizadas = 0;
        $gruposConMismoDocente = 0;

        foreach ($grupos as $token => $asignaturas) {
            if ($asignaturas->count() < 2) {
                continue; // Necesita al menos 2 para sincronizar
            }

            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("📎 Grupo: {$token}");

            // Mostrar las asignaturas del grupo
            foreach ($asignaturas as $a) {
                $progress = $this->syncService->calculateProgress($a);
                $docenteNombre = $this->getDocenteNombre($a);
                $this->line("   - [{$a->codigo}] {$a->nombre} ({$progress}%) - Docente: {$docenteNombre}");
            }

            // Verificar si comparten el mismo docente
            $docentesEnComun = $this->findDocentesEnComun($asignaturas);

            if (empty($docentesEnComun)) {
                $this->comment("   ⏭️  Sin docentes en común, se omite sincronización.");
                $this->newLine();
                continue;
            }

            $gruposConMismoDocente++;
            $this->info("   ✅ Docentes en común: " . implode(', ', $docentesEnComun));

            // Determinar la asignatura "Master" (mayor progreso)
            $master = $asignaturas->sortByDesc(function ($a) {
                return $this->syncService->calculateProgress($a);
            })->first();

            $masterProgress = $this->syncService->calculateProgress($master);
            $this->info("   👑 Master: [{$master->codigo}] {$master->nombre} ({$masterProgress}%)");

            // Las demás son "target"
            $targets = $asignaturas->filter(fn($a) => $a->id !== $master->id);

            foreach ($targets as $target) {
                $targetProgress = $this->syncService->calculateProgress($target);
                
                if ($targetProgress >= $masterProgress && !$force) {
                    $this->comment("   ⏭️  [{$target->codigo}] ya tiene {$targetProgress}% (igual o mayor), omitido.");
                    continue;
                }

                if ($dryRun) {
                    $this->warn("   🔄 [DRY-RUN] Se sincronizaría [{$target->codigo}] desde [{$master->codigo}]");
                } else {
                    $this->info("   🔄 Sincronizando [{$target->codigo}] desde [{$master->codigo}]...");
                    
                    try {
                        DB::transaction(function () use ($master, $target) {
                            $this->syncDocumentation($master, $target);
                        });
                        $this->info("   ✅ [{$target->codigo}] sincronizado correctamente.");
                        $totalSincronizadas++;
                    } catch (\Exception $e) {
                        $this->error("   ❌ Error al sincronizar [{$target->codigo}]: " . $e->getMessage());
                    }
                }
            }

            $this->newLine();
        }

        $this->newLine();
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("📊 RESUMEN:");
        $this->line("   - Grupos analizados: {$grupos->count()}");
        $this->line("   - Grupos con mismo docente: {$gruposConMismoDocente}");
        
        if ($dryRun) {
            $this->warn("   - Asignaturas a sincronizar: (modo dry-run, sin cambios)");
        } else {
            $this->info("   - Asignaturas sincronizadas: {$totalSincronizadas}");
        }

        $this->newLine();
        $this->info('✅ Proceso completado.');

        return 0;
    }

    /**
     * Obtiene el nombre del docente principal de una asignatura
     */
    private function getDocenteNombre(Asignatura $asignatura): string
    {
        $grupo = Grupo::where('asignatura_id', $asignatura->id)
            ->whereNotNull('docente_id')
            ->with('docente')
            ->first();

        return $grupo?->docente?->nombre_completo ?? 'Sin asignar';
    }

    /**
     * Encuentra docentes que están asignados a TODAS las asignaturas del grupo
     */
    private function findDocentesEnComun($asignaturas): array
    {
        $docentesPorAsignatura = [];

        foreach ($asignaturas as $a) {
            $docenteIds = Grupo::where('asignatura_id', $a->id)
                ->whereNotNull('docente_id')
                ->pluck('docente_id')
                ->unique()
                ->toArray();

            $docentesPorAsignatura[] = $docenteIds;
        }

        if (count($docentesPorAsignatura) < 2) {
            return [];
        }

        // Intersección de todos los arrays de docentes
        $enComun = array_shift($docentesPorAsignatura);
        foreach ($docentesPorAsignatura as $docentes) {
            $enComun = array_intersect($enComun, $docentes);
        }

        // Obtener nombres
        if (empty($enComun)) {
            return [];
        }

        return \App\Models\Docente::whereIn('id', $enComun)
            ->pluck('nombre_completo')
            ->toArray();
    }

    /**
     * Sincroniza la documentación de master a target
     */
    private function syncDocumentation(Asignatura $master, Asignatura $target): void
    {
        // Usar el servicio de sincronización existente
        // Este método sincroniza: campos de asignatura, unidades, temas, logros, indicadores

        // 1. Sincronizar campos de nivel asignatura
        $target->update([
            'descripcion' => $master->descripcion,
            'justificacion' => $master->justificacion,
            'proposito_general' => $master->proposito_general,
            'metodologia_general' => $master->metodologia_general,
            'sistema_evaluacion' => $master->sistema_evaluacion,
            'contenido_minimo' => $master->contenido_minimo,
            'requisitos' => $master->requisitos,
            'competencia_global_especifica' => $master->competencia_global_especifica,
            'competencia_asignatura' => $master->competencia_asignatura,
            'elementos_competencia' => $master->elementos_competencia,
            'reglamento_normativa' => $master->reglamento_normativa,
            'organizacion_calendario' => $master->organizacion_calendario,
        ]);

        // 2. Limpiar estructura existente en target
        foreach ($target->unidades as $unidad) {
            $unidad->temas()->each(function ($tema) {
                $tema->logros()->delete();
            });
            $unidad->temas()->delete();
        }
        $target->unidades()->delete();

        // 3. Clonar estructura desde master
        $master->load('unidades.temas.logros.indicadores');

        foreach ($master->unidades as $masterUnidad) {
            $newUnidad = $target->unidades()->create([
                'numero' => $masterUnidad->numero,
                'titulo' => $masterUnidad->titulo,
                'objetivo' => $masterUnidad->objetivo,
                'contenido_minimo' => $masterUnidad->contenido_minimo,
                'elemento_competencia' => $masterUnidad->elemento_competencia,
                'tipo' => $masterUnidad->tipo,
            ]);

            foreach ($masterUnidad->temas as $masterTema) {
                $newTema = $newUnidad->temas()->create([
                    'titulo' => $masterTema->titulo,
                    'descripcion' => $masterTema->descripcion,
                    'orden' => $masterTema->orden,
                    'resultado_aprendizaje' => $masterTema->resultado_aprendizaje,
                    'horas_practicas' => $masterTema->horas_practicas,
                    'horas_teoricas' => $masterTema->horas_teoricas,
                    'tipo' => $masterTema->tipo,
                    'contenido_conceptual' => $masterTema->contenido_conceptual,
                    'contenido_procedimental' => $masterTema->contenido_procedimental,
                    'contenido_actitudinal' => $masterTema->contenido_actitudinal,
                    'estrategias_metodologicas' => $masterTema->estrategias_metodologicas,
                    'estrategias_aprendizaje' => $masterTema->estrategias_aprendizaje,
                    'estrategias_recursos' => $masterTema->estrategias_recursos,
                    'evaluacion_formativa' => $masterTema->evaluacion_formativa,
                    'evaluacion_sumativa' => $masterTema->evaluacion_sumativa,
                ]);

                foreach ($masterTema->logros as $masterLogro) {
                    $newLogro = $newTema->logros()->create([
                        'descripcion' => $masterLogro->descripcion,
                        'tipo_logro' => $masterLogro->tipo_logro,
                        'periodo' => $masterLogro->periodo,
                    ]);

                    foreach ($masterLogro->indicadores as $indicador) {
                        $newLogro->indicadores()->create([
                            'descripcion' => $indicador->descripcion,
                        ]);
                    }
                }
            }
        }
    }
}
