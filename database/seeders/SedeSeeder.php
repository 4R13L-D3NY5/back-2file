<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sede;

class SedeSeeder extends Seeder
{
    public function run()
    {
        $sedes = [
            ['codigo' => 'CBA', 'nombre' => 'Cochabamba', 'ciudad' => 'Cochabamba', 'direccion' => 'Av. Blanco Galindo Km 5', 'telefono' => '4-4424242', 'activo' => true],
            ['codigo' => 'LPZ', 'nombre' => 'La Paz', 'ciudad' => 'La Paz', 'direccion' => 'Av. 6 de Agosto', 'telefono' => '2-2222222', 'activo' => true],
            ['codigo' => 'EAL', 'nombre' => 'El Alto', 'ciudad' => 'El Alto', 'direccion' => 'Av. 6 de Marzo', 'telefono' => '2-2882888', 'activo' => true],
            ['codigo' => 'STC', 'nombre' => 'Santa Cruz', 'ciudad' => 'Santa Cruz', 'direccion' => 'Av. Banzer', 'telefono' => '3-3333333', 'activo' => true],
            ['codigo' => 'GUA', 'nombre' => 'Guayaramerin', 'ciudad' => 'Guayaramerin', 'direccion' => 'Centro', 'telefono' => '3-8555555', 'activo' => true],
            ['codigo' => 'PTO', 'nombre' => 'Puerto Quijarro', 'ciudad' => 'Puerto Quijarro', 'direccion' => 'Centro', 'telefono' => '3-9777777', 'activo' => true],
            ['codigo' => 'IVI', 'nombre' => 'Ivirgarzama', 'ciudad' => 'Ivirgarzama', 'direccion' => 'Av. Principal', 'telefono' => '4-4111111', 'activo' => true],
            ['codigo' => 'CBJ', 'nombre' => 'Cobija', 'ciudad' => 'Cobija', 'direccion' => 'Av. 9 de Febrero', 'telefono' => '3-8422222', 'activo' => true],
        ];

        foreach ($sedes as $sede) {
            Sede::updateOrCreate(
                ['codigo' => $sede['codigo']],
                $sede
            );
        }
    }
}
