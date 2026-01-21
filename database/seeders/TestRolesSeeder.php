<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class TestRolesSeeder extends Seeder
{
    /**
     * Seeder para crear usuarios de prueba con diferentes roles administrativos.
     * Roles: Vicerrector, Director Académico, Director de Carrera
     */
    public function run(): void
    {
        // Vicerrector Nacional (rol_id = 3)
        User::updateOrCreate(
            ['ci' => '1111111'],
            [
                'username' => '1111111',
                'nombre' => 'Carlos',
                'apellido' => 'Vicerrector',
                'email' => 'vicerrector@unitepc.edu.bo',
                'password' => Hash::make('12345678'),
                'rol_id' => 3, // VICERRECTORADO
                'estado' => true,
                'password_change_required' => false,
            ]
        );

        // Director Académico (rol_id = 4)
        User::updateOrCreate(
            ['ci' => '2222222'],
            [
                'username' => '2222222',
                'nombre' => 'Maria',
                'apellido' => 'Academica',
                'email' => 'diracademico@unitepc.edu.bo',
                'password' => Hash::make('12345678'),
                'rol_id' => 4, // DIRECCIÓN ACADÉMICA
                'estado' => true,
                'password_change_required' => false,
            ]
        );

        // Director de Carrera (rol_id = 5)
        User::updateOrCreate(
            ['ci' => '3333333'],
            [
                'username' => '3333333',
                'nombre' => 'Juan',
                'apellido' => 'Director',
                'email' => 'dircarrera@unitepc.edu.bo',
                'password' => Hash::make('12345678'),
                'rol_id' => 5, // DIRECTOR DE CARRERA
                'estado' => true,
                'password_change_required' => false,
            ]
        );

        $this->command->info('✅ Usuarios de prueba creados:');
        $this->command->info('   - Vicerrector:        CI 1111111 / Password: 12345678');
        $this->command->info('   - Dir. Académico:     CI 2222222 / Password: 12345678');
        $this->command->info('   - Dir. Carrera:       CI 3333333 / Password: 12345678');
    }
}
