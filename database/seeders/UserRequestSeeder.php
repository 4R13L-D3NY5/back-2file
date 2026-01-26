<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class UserRequestSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $password = Hash::make('12345678');

        $users = [
            [
                'username' => 'test.vicerrector',
                'email' => 'vicerrector@test.com',
                'password' => $password,
                'nombre' => 'Vicerrector',
                'apellido' => 'Test',
                'ci' => '1111111',
                'telefono' => '70000001',
                'carrera' => null,
                'rol_id' => 3, // VICERRECTORADO
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'test.academico',
                'email' => 'academico@test.com',
                'password' => $password,
                'nombre' => 'Director',
                'apellido' => 'Académico Test',
                'ci' => '2222222',
                'telefono' => '70000002',
                'carrera' => null,
                'rol_id' => 4, // DIRECCIÓN ACADÉMICA
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'test.director',
                'email' => 'director.carrera@test.com',
                'password' => $password,
                'nombre' => 'Director',
                'apellido' => 'Carrera Test',
                'ci' => '3333333',
                'telefono' => '70000003',
                'carrera' => 'Ingeniería de Sistemas',
                'rol_id' => 5, // DIRECTOR DE CARRERA
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('users')->insert($users);
    }
}
