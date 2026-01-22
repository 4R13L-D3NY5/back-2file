<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SedeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sedes = [
            // Verified IDs from Planning API
            ['id' => 1,  'codigo' => 'CBA', 'nombre' => 'Cochabamba', 'ciudad' => 'Cochabamba'],
            ['id' => 6,  'codigo' => 'LPZ', 'nombre' => 'La Paz', 'ciudad' => 'La Paz'],
            ['id' => 9,  'codigo' => 'STC', 'nombre' => 'Santa Cruz', 'ciudad' => 'Santa Cruz'],
            ['id' => 12, 'codigo' => 'GUA', 'nombre' => 'Guayaramerin', 'ciudad' => 'Guayaramerin'],
            ['id' => 8,  'codigo' => 'PTO', 'nombre' => 'Puerto Quijarro', 'ciudad' => 'Puerto Quijarro'],
            ['id' => 5,  'codigo' => 'IVI', 'nombre' => 'Ivirgarzama', 'ciudad' => 'Ivirgarzama'],
            // TODO: Cobija y El Alto - IDs pendientes de confirmar desde Planning API
        ];

        foreach ($sedes as $sede) {
            DB::table('sedes')->updateOrInsert(
                ['id' => $sede['id']],
                [
                    'codigo' => $sede['codigo'],
                    'nombre' => $sede['nombre'],
                    'ciudad' => $sede['ciudad'],
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
