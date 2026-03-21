<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call([
            RoleSeeder::class,
            SedeSeeder::class,
            ConstantsMigrationSeeder::class, // Migra constantes hardcodeadas a BD
            // CarreraSeeder::class, // Comentado para evitar conflictos con ConstantsMigrationSeeder
            UserSeeder::class,
            ListaUsuariosSeeder::class,
            // TestRolesSeeder::class,
            // ArielCamaraSeeder::class,
            EvaluacionConfiguracionSeeder::class,
            CampusSeeder::class,
        ]);
    }
}
