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
        User::updateOrCreate(
            ['ci' => '5927724'],
            [
                'username' => '5927724',
                'email' => 'admin@unitepc.edu.bo',
                'password' => Hash::make('5927724'),
                'estado' => true,
                'rol_id' => 1, // SUPER_ADMIN
                'nombre' => 'Super',
                'apellido' => 'Administrador',
                'password_change_required' => true,
            ]
        );

        $this->command->info('Super Admin creado: CI 5927724');
    }
}
