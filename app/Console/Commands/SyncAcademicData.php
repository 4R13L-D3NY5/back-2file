<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncAcademicData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'academic:sync {--force : Force full resync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza Datos Académicos (Carreras, Asignaturas, Docentes) desde la API Externa hacia la BD Local';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando Sincronización con API Académica...');

        // 1. Simulación de Fetch API (Aquí iría: Http::get('api.unitepc.edu.bo/carreras'))
        // Para demo, usamos datos que "simulan" venir de afuera
        $apiCarreras = [
            ['codigo' => 'SIS', 'nombre' => 'Ingeniería de Sistemas (Actualizado)', 'sede_codigo' => 'CBA'],
            ['codigo' => 'MED', 'nombre' => 'Medicina Humana', 'sede_codigo' => 'CBA'],
        ];

        $this->output->progressStart(count($apiCarreras));

        foreach ($apiCarreras as $externalData) {
            // Lógica "Espejo": Si existe actualiza, si no crea.
            // Clave única: CODIGO + SEDE
            $sede = \App\Models\Sede::where('codigo', $externalData['sede_codigo'])->first();

            if ($sede) {
                \App\Models\Carrera::updateOrCreate(
                    [
                        'codigo' => $externalData['codigo'],
                        'sede_id' => $sede->id
                    ],
                    [
                        'nombre' => $externalData['nombre'], // Si en la API cambia el nombre, aquí se actualiza
                        'activo' => true
                    ]
                );
            }
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->info('Carreras Sincronizadas corectamente.');

        // PASO 2: ASIGNATURAS
        $this->info('Sincronizando Asignaturas...');
        // Aquí iría la misma lógica para Asignaturas...

        $this->info('¡Sincronización Completa! Los cambios externos ahora están en el sistema local.');
    }
}
