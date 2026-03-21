<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Carrera;
use App\Models\Sede;

class ConstantsMigrationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // SEDES from SincronizacionPage.vue
        $sedesApi = [
            ['id' => 1, 'nombre' => 'Cochabamba'],
            ['id' => 2, 'nombre' => 'Caranavi'],
            ['id' => 3, 'nombre' => 'Cobija'],
            ['id' => 4, 'nombre' => 'El Alto'],
            ['id' => 5, 'nombre' => 'Ivirgarzama'],
            ['id' => 6, 'nombre' => 'La Paz'],
            ['id' => 8, 'nombre' => 'Puerto Quijarro'],
            ['id' => 9, 'nombre' => 'Santa Cruz'],
            ['id' => 12, 'nombre' => 'Guayaramerin'],
        ];

        foreach ($sedesApi as $sedeApi) {
            // Find existing sede by ID (primary key) or create with basic data
            Sede::updateOrCreate(
                ['id' => $sedeApi['id']],
                [
                    'id_api' => $sedeApi['id'], // Match API ID
                    'nombre' => $sedeApi['nombre'],
                    'codigo' => $this->generateCodigoFromNombre($sedeApi['nombre']),
                    'ciudad' => $this->guessCiudad($sedeApi['nombre']),
                    'activo' => true,
                    'modificado_localmente' => false,
                ]
            );
            $this->command->info("Sede {$sedeApi['nombre']} (ID {$sedeApi['id']}) actualizada.");
        }

        // CARRERAS from SincronizacionPage.vue
        $carrerasApi = [
            'CARADM', 'CARAYE', 'CARBYF', 'CARCAD', 'CARCCP', 'CARCIC', 'CARCNE',
            'CARCPU', 'CARCSO', 'CARDER', 'CARECO', 'CARELE', 'CARENL', 'CARFIS',
            'CARFON', 'CARIBI', 'CARICO', 'CARMED', 'CARNYD', 'CARODO', 'CARPRO',
            'CARSIS', 'CARSON', 'CARVET',
        ];

        // Mapping from API code suffix to display name
        $nameMap = [
            'SIS' => 'Ingeniería de Sistemas',
            'MED' => 'Medicina',
            'ODO' => 'Odontología',
            'DER' => 'Derecho',
            'COM' => 'Ingeniería Comercial',
            'CIV' => 'Ingeniería Civil',
            'ADM' => 'Administración de Empresas',
            'AYE' => 'Arquitectura y Urbanismo',
            'BYF' => 'Biología y Farmacia',
            'CAD' => 'Ciencias de la Administración',
            'CCP' => 'Ciencias de la Computación',
            'CIC' => 'Ciencias de la Información y Comunicación',
            'CNE' => 'Ciencias de la Nutrición y Ejercicio',
            'CPU' => 'Ciencias Políticas y Urbanismo',
            'CSO' => 'Ciencias Sociales',
            'ECO' => 'Economía',
            'ELE' => 'Electrónica',
            'ENL' => 'Enfermería',
            'FIS' => 'Física',
            'FON' => 'Fonoaudiología',
            'IBI' => 'Ingeniería Biomédica',
            'ICO' => 'Ingeniería de Control',
            'NYD' => 'Negocios y Desarrollo',
            'PRO' => 'Producción',
            'SON' => 'Sonido',
            'VET' => 'Veterinaria',
        ];

        foreach ($carrerasApi as $codigoApi) {
            $suffix = substr($codigoApi, 3); // Remove 'CAR' prefix
            $nombre = $nameMap[$suffix] ?? $codigoApi;
            $codigo = $suffix; // Internal code (e.g., 'SIS')
            
            Carrera::updateOrCreate(
                ['codigo_api' => $codigoApi],
                [
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'area' => $this->guessArea($suffix),
                    'plan_estudios' => 'N', // Default to 'N' (Nuevo)
                    'modificado_localmente' => false,
                    'sede_id' => null, // To be assigned by SUPER_ADMIN later
                ]
            );
            $this->command->info("Carrera {$codigoApi} ({$nombre}) migrada.");
        }

        $this->command->info('Migración de constantes completada.');
    }

    private function generateCodigoFromNombre(string $nombre): string
    {
        $map = [
            'Cochabamba' => 'CBA',
            'Caranavi' => 'CRV',
            'Cobija' => 'COB',
            'El Alto' => 'EAL',
            'Ivirgarzama' => 'IVI',
            'La Paz' => 'LPZ',
            'Puerto Quijarro' => 'PTO',
            'Santa Cruz' => 'STC',
            'Guayaramerin' => 'GUA',
        ];
        return $map[$nombre] ?? substr(strtoupper(preg_replace('/[^a-zA-Z]/', '', $nombre)), 0, 3);
    }

    private function guessCiudad(string $nombreSede): string
    {
        // Most sedes are named after their city
        return $nombreSede;
    }

    private function guessArea(string $suffix): string
    {
        $areas = [
            'SIS' => 'Ciencias Exactas y Tecnología',
            'CIV' => 'Ciencias Exactas y Tecnología',
            'MED' => 'Ciencias de la Salud',
            'ODO' => 'Ciencias de la Salud',
            'DER' => 'Ciencias Jurídicas y Sociales',
            'COM' => 'Ciencias Económicas y Empresariales',
            'ADM' => 'Ciencias Económicas y Empresariales',
            'ECO' => 'Ciencias Económicas y Empresariales',
            'FIS' => 'Ciencias Exactas y Tecnología',
            'ENL' => 'Ciencias de la Salud',
            'VET' => 'Ciencias de la Salud',
            'FON' => 'Ciencias de la Salud',
            'IBI' => 'Ciencias de la Salud y Tecnología',
            'ELE' => 'Ciencias Exactas y Tecnología',
            'BYF' => 'Ciencias de la Salud',
            'CNE' => 'Ciencias de la Salud',
            'CSO' => 'Ciencias Sociales',
            'CPU' => 'Ciencias Sociales',
            'CCP' => 'Ciencias Exactas y Tecnología',
            'CIC' => 'Ciencias Exactas y Tecnología',
            'AYE' => 'Ciencias Exactas y Tecnología',
            'NYD' => 'Ciencias Económicas y Empresariales',
            'PRO' => 'Ciencias Exactas y Tecnología',
            'SON' => 'Ciencias Exactas y Tecnología',
        ];
        return $areas[$suffix] ?? 'Ciencias Generales';
    }
}