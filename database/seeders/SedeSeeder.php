<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sede;

class SedeSeeder extends Seeder
{
    public function run()
    {
        // IDs de la API externa (Intranet):
        // CBA=1, CBJ=3, LPZ=4, IVI=5, PTO=6, STC=7, EAL=8, GUA=12
        $sedes = [
            ['codigo' => 'CBA', 'id_api' => 1, 'nombre' => 'Cochabamba', 'ciudad' => 'Cochabamba', 'direccion' => 'Av. Blanco Galindo Km 5', 'telefono' => '4-4424242', 'activo' => true],
            ['codigo' => 'LPZ', 'id_api' => 4, 'nombre' => 'La Paz', 'ciudad' => 'La Paz', 'direccion' => 'Av. 6 de Agosto', 'telefono' => '2-2222222', 'activo' => true],
            ['codigo' => 'EAL', 'id_api' => 8, 'nombre' => 'El Alto', 'ciudad' => 'El Alto', 'direccion' => 'Av. 6 de Marzo', 'telefono' => '2-2882888', 'activo' => true],
            ['codigo' => 'STC', 'id_api' => 7, 'nombre' => 'Santa Cruz', 'ciudad' => 'Santa Cruz', 'direccion' => 'Av. Banzer', 'telefono' => '3-3333333', 'activo' => true],
            ['codigo' => 'GUA', 'id_api' => 12, 'nombre' => 'Guayaramerin', 'ciudad' => 'Guayaramerin', 'direccion' => 'Centro', 'telefono' => '3-8555555', 'activo' => true],
            ['codigo' => 'PTO', 'id_api' => 6, 'nombre' => 'Puerto Quijarro', 'ciudad' => 'Puerto Quijarro', 'direccion' => 'Centro', 'telefono' => '3-9777777', 'activo' => true],
            ['codigo' => 'IVI', 'id_api' => 5, 'nombre' => 'Ivirgarzama', 'ciudad' => 'Ivirgarzama', 'direccion' => 'Av. Principal', 'telefono' => '4-4111111', 'activo' => true],
            ['codigo' => 'CBJ', 'id_api' => 3, 'nombre' => 'Cobija', 'ciudad' => 'Cobija', 'direccion' => 'Av. 9 de Febrero', 'telefono' => '3-8422222', 'activo' => true],
        ];

        foreach ($sedes as $sede) {
            Sede::updateOrCreate(
                ['codigo' => $sede['codigo']],
                $sede
            );
        }
    }
}
