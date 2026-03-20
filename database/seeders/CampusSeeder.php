<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Campus;

class CampusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // 1. Crear los Campus para Sede 1
        $campus1 = Campus::updateOrCreate(
            ['id' => 1],
            ['nombre' => 'COLONIAL', 'sede_id' => 1, 'activo' => true]
        );

        $campus2 = Campus::updateOrCreate(
            ['id' => 2],
            ['nombre' => 'JUAN PABLO II', 'sede_id' => 1, 'activo' => true]
        );

        $campus3 = Campus::updateOrCreate(
            ['id' => 3],
            ['nombre' => 'FLORIDA NORTE', 'sede_id' => 1, 'activo' => true]
        );

        // 2. Asociar las carreras según la distribución solicitada
        // Campus 1
        $carrerasCampus1 = [1, 2, 3, 4, 5, 6, 7, 10, 8, 9, 18, 21, 22, 23, 24];
        $campus1->carreras()->syncWithoutDetaching($carrerasCampus1);

        // Campus 2
        $carrerasCampus2 = [11, 12, 13, 14, 15, 16, 19];
        $campus2->carreras()->syncWithoutDetaching($carrerasCampus2);

        // Campus 3
        $carrerasCampus3 = [17, 20, 24];
        $campus3->carreras()->syncWithoutDetaching($carrerasCampus3);
    }
}
