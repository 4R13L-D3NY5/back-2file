<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sede;

class SedeSeeder extends Seeder
{
    public function run()
    {
        // IDs de la API externa (VERIFICADOS desde la respuesta de la API):
        // 1=Cochabamba, 5=Ivirgarzama, 6=La Paz, 9=Santa Cruz, 12=Guayaramerin
        // NOTA: Sedes 2,3,4,7,8,10,11,13,14,15 no tienen datos en la API actualmente
        $sedes = [
            ['codigo' => 'CBA', 'id_api' => 1, 'nombre' => 'Cochabamba', 'ciudad' => 'Cochabamba', 'direccion' => 'Av. Blanco Galindo Km 5', 'telefono' => '4-4424242', 'activo' => true],
            ['codigo' => 'LPZ', 'id_api' => 6, 'nombre' => 'La Paz', 'ciudad' => 'La Paz', 'direccion' => 'Av. 6 de Agosto', 'telefono' => '2-2222222', 'activo' => true],
            ['codigo' => 'STC', 'id_api' => 9, 'nombre' => 'Santa Cruz', 'ciudad' => 'Santa Cruz', 'direccion' => 'Av. Banzer', 'telefono' => '3-3333333', 'activo' => true],
            ['codigo' => 'IVI', 'id_api' => 5, 'nombre' => 'Ivirgarzama', 'ciudad' => 'Ivirgarzama', 'direccion' => 'Av. Principal', 'telefono' => '4-4111111', 'activo' => true],
            ['codigo' => 'GUA', 'id_api' => 12, 'nombre' => 'Guayaramerin', 'ciudad' => 'Guayaramerin', 'direccion' => 'Centro', 'telefono' => '3-8555555', 'activo' => true],
            ['codigo' => 'PTO', 'id_api' => 8, 'nombre' => 'Puerto Quijarro', 'ciudad' => 'Puerto Quijarro', 'direccion' => 'Centro', 'telefono' => '3-9777777', 'activo' => true],
            ['codigo' => 'CBJ', 'id_api' => 3, 'nombre' => 'Cobija', 'ciudad' => 'Cobija', 'direccion' => 'Av. 9 de Febrero', 'telefono' => '3-8422222', 'activo' => true],
            // Sedes sin datos confirmados (El Alto figuraba como 8, pero 8 es PTO)
            ['codigo' => 'EAL', 'id_api' => null, 'nombre' => 'El Alto', 'ciudad' => 'El Alto', 'direccion' => 'Av. 6 de Marzo', 'telefono' => '2-2882888', 'activo' => true],
        ];

        foreach ($sedes as $sede) {
            Sede::updateOrCreate(
                ['codigo' => $sede['codigo']],
                $sede
            );
        }
    }
}
