<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sede;
use App\Models\Campus;
use App\Models\Carrera;
use Illuminate\Support\Facades\DB;

class CampusSedesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // 1. Obtener sedes que no sean Cochabamba (id 1)
        $sedes = Sede::where('id', '!=', 1)->get();

        foreach ($sedes as $sede) {
            $nombreCampus = "Campus " . $sede->nombre;
            
            // 2. Crear o actualizar el campus principal para esa sede
            $campus = Campus::updateOrCreate(
                ['sede_id' => $sede->id, 'nombre' => $nombreCampus],
                [
                    'direccion' => "Campus Principal en " . $sede->ciudad,
                    'activo' => true
                ]
            );

            $this->command->info("Procesando Campus: {$nombreCampus} (Sede ID: {$sede->id})");

            // 3. Obtener todas las carreras vinculadas a esta sede
            // Buscamos carreras que tengan el sede_id de la sede o que estén en la tabla pivot carrera_sede
            $carrerasIds = Carrera::where('sede_id', $sede->id)
                ->orWhereHas('sedes', function($q) use ($sede) {
                    $q->where('sedes.id', $sede->id);
                })
                ->pluck('id')
                ->toArray();

            if (empty($carrerasIds)) {
                $this->command->warn("  - No se encontraron carreras para la sede {$sede->nombre}");
                continue;
            }

            // 4. Asignar masivamente las carreras al campus (evitando duplicados)
            $campus->carreras()->syncWithoutDetaching($carrerasIds);
            
            $count = count($carrerasIds);
            $this->command->comment("  - Se asignaron {$count} carreras exitosamente.");
        }

        $this->command->info("Seeder CampusSedesSeeder finalizado correctamente.");
    }
}
