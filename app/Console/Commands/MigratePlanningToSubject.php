<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Cronograma;
use App\Models\Asignatura;
use Illuminate\Support\Facades\DB;

class MigratePlanningToSubject extends Command
{
    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = 'academic:migrate-planning';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Migrate group-level planning content to subject-level Master records (Shared Planning)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting planning migration to Master records...');

        $asignaturas = Asignatura::has('cronogramas')->get();

        foreach ($asignaturas as $asignatura) {
            $this->info("Processing Asignatura: {$asignatura->nombre}");

            // Get all cronogramas for this asignatura that are linked to a group
            $cronogramasConGrupo = Cronograma::where('asignatura_id', $asignatura->id)
                ->whereNotNull('grupo_id')
                ->get();

            // Group by session number to find content to promote
            $sesionesAgrupadas = $cronogramasConGrupo->groupBy('numero_sesion');

            foreach ($sesionesAgrupadas as $numeroSesion => $grupoCronogramas) {
                // Find a "rich" session to promote (the one with most content)
                $richSession = $grupoCronogramas->sortByDesc(function ($c) {
                    // Length check for arrays or strings
                    $l1 = is_array($c->contenido_conceptual) ? count($c->contenido_conceptual) : strlen($c->contenido_conceptual);
                    $l2 = is_array($c->contenido_items_seleccionados) ? count($c->contenido_items_seleccionados) : 0;
                    return $l1 + $l2 + strlen($c->observaciones);
                })->first();

                if ($richSession && (!empty($richSession->contenido_conceptual) || !empty($richSession->tema_id))) {
                    // Create or update Master Record (grupo_id = NULL)
                    $master = Cronograma::updateOrCreate(
                        [
                            'asignatura_id' => $asignatura->id,
                            'grupo_id' => null, // MASTER RECORD
                            'numero_sesion' => $numeroSesion,
                        ],
                        [
                            'tema_id' => $richSession->tema_id,
                            'contenido_conceptual' => $richSession->contenido_conceptual,
                            'contenido_procedimental' => $richSession->contenido_procedimental,
                            'contenido_actitudinal' => $richSession->contenido_actitudinal,
                            'criterios_desempeno' => $richSession->criterios_desempeno,
                            'instrumentos_evaluacion' => $richSession->instrumentos_evaluacion,
                            'contenido_items_seleccionados' => $richSession->contenido_items_seleccionados,
                        ]
                    );

                    // Migrate topics (pivot)
                    $topicIds = DB::table('cronograma_tema')->where('cronograma_id', $richSession->id)->pluck('tema_id')->toArray();
                    if (!empty($topicIds)) {
                        $master->temas()->sync($topicIds);
                    }

                    $this->line("  -> Session {$numeroSesion} promoted to Subject-Level Master.");
                }
            }
        }

        $this->info('Migration completed successfully!');
    }
}
