<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();
        $password = Hash::make('password'); // Default password for all

        $users = [
            [
                'id' => 1,
                'username' => '67760520',
                'email' => 'juanjose.mamani@unitepc.edu.bo',
                'password' => Hash::make('67760520'),
                'nombre' => 'Juan Jose',
                'apellido' => 'Mamani Via',
                'ci' => '67760520',
                'telefono' => null,
                'carrera' => null,
                'rol_id' => 1, // SUPER ADMIN
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'username' => '79326793',
                'email' => 'ariel.camara@unitepc.edu.bo',
                'password' => Hash::make('79326793'),
                'nombre' => 'Ariel Denys',
                'apellido' => 'Camara Arce',
                'ci' => '79326793',
                'telefono' => null,
                'carrera' => null,
                'rol_id' => 1, // SUPER ADMIN
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'username' => '78311416',
                'email' => 'marco.rojas@unitepc.edu.bo',
                'password' => Hash::make('78311416'),
                'nombre' => 'Marco Antonio Harold',
                'apellido' => 'Rojas Torres',
                'ci' => '78311416',
                'telefono' => null,
                'carrera' => null,
                'rol_id' => 1, // SUPER ADMIN
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('users')->insert($users);
    }
}
