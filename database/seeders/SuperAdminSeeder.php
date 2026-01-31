<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admins = [
            [
                'username' => '67760520',
                'email' => 'juanjose.mamani@unitepc.edu.bo',
                'password' => Hash::make('67760520'),
                'nombre' => 'Juan Jose',
                'apellido' => 'Mamani Via',
                'ci' => '67760520',
                'rol_id' => 1,
            ],
            [
                'username' => '79326793',
                'email' => 'ariel.camara@unitepc.edu.bo',
                'password' => Hash::make('79326793'),
                'nombre' => 'Ariel Denys',
                'apellido' => 'Camara Arce',
                'ci' => '79326793',
                'rol_id' => 1,
            ],
            [
                'username' => '78311416',
                'email' => 'marco.rojas@unitepc.edu.bo',
                'password' => Hash::make('78311416'),
                'nombre' => 'Marco Antonio Harold',
                'apellido' => 'Rojas Torres',
                'ci' => '78311416',
                'rol_id' => 1,
            ]
        ];

        foreach ($admins as $admin) {
            User::updateOrCreate(
                ['ci' => $admin['ci']],
                array_merge($admin, [
                    'estado' => true,
                    'password_change_required' => false,
                ])
            );
        }

        $this->command->info('3 Super Admins creados/actualizados.');
    }
}
