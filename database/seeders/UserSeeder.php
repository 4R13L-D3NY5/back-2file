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
                'username' => 'carlos.mendoza',
                'email' => 'carlos.mendoza@unitepc.edu.bo',
                'password' => $password,
                'nombre' => 'Carlos',
                'apellido' => 'Mendoza Quispe',
                'ci' => '8765432',
                'telefono' => '+591 71234567',
                'carrera' => null,
                'rol_id' => 1, // SUPER ADMIN
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'username' => 'maria.fernandez',
                'email' => 'maria.fernandez@unitepc.edu.bo',
                'password' => $password,
                'nombre' => 'María',
                'apellido' => 'Fernández López',
                'ci' => '7654321',
                'telefono' => '+591 72345678',
                'carrera' => null,
                'rol_id' => 2, // ADMIN
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'username' => 'roberto.garcia',
                'email' => 'roberto.garcia@unitepc.edu.bo',
                'password' => $password,
                'nombre' => 'Roberto',
                'apellido' => 'García Vargas',
                'ci' => '6543210',
                'telefono' => '+591 73456789',
                'carrera' => null,
                'rol_id' => 3, // VICERRECTORADO
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 4,
                'username' => 'ana.mamani',
                'email' => 'ana.mamani@unitepc.edu.bo',
                'password' => $password,
                'nombre' => 'Ana',
                'apellido' => 'Mamani Churata',
                'ci' => '5432109',
                'telefono' => '+591 74567890',
                'carrera' => null,
                'rol_id' => 4, // DIRECCIÓN ACADÉMICA
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 5,
                'username' => 'juan.condori',
                'email' => 'juan.condori@unitepc.edu.bo',
                'password' => $password,
                'nombre' => 'Juan',
                'apellido' => 'Condori Apaza',
                'ci' => '4321098',
                'telefono' => '+591 75678901',
                'carrera' => 'Ingeniería de Sistemas',
                'rol_id' => 5, // DIRECTOR DE CARRERA
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 6,
                'username' => 'patricia.ticona',
                'email' => 'patricia.ticona@unitepc.edu.bo',
                'password' => $password,
                'nombre' => 'Patricia',
                'apellido' => 'Ticona Flores',
                'ci' => '3210987',
                'telefono' => '+591 76789012',
                'carrera' => 'Ingeniería de Sistemas',
                'rol_id' => 6, // DOCENTE
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 7,
                'username' => 'miguel.huanca',
                'email' => 'miguel.huanca@unitepc.edu.bo',
                'password' => $password,
                'nombre' => 'Miguel',
                'apellido' => 'Huanca Quispe',
                'ci' => '2109876',
                'telefono' => '+591 77890123',
                'carrera' => 'Administración de Empresas',
                'rol_id' => 6, // DOCENTE
                'estado' => false, // Inactivo
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 8,
                'username' => 'lucia.choque',
                'email' => 'lucia.choque@unitepc.edu.bo',
                'password' => $password,
                'nombre' => 'Lucia',
                'apellido' => 'Choque Mamani',
                'ci' => '1098765',
                'telefono' => '+591 78901234',
                'carrera' => null,
                'rol_id' => 7, // EVALUACIONES
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('users')->insert($users);
    }
}
