<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Carrera;
use App\Models\Sede;

class CarreraSeeder extends Seeder
{
    public function run()
    {
        // 1. Obtener Sede Cochabamba (CBA) para asignar carreras base
        $sedeCBA = Sede::where('codigo', 'CBA')->first();
        if (!$sedeCBA) return;

        // 2. Definir Carreras Base (Simuladas de la API pero con datos ricos)
        $carreras = [
            [
                'codigo' => 'SIS',
                'nombre' => 'Ingeniería de Sistemas',
                'area' => 'Ciencias Exactas y Tecnología',
                'mision' => 'Nuestra misión es formar ingenieros de sistemas altamente capacitados y comprometidos con la excelencia académica, la innovación tecnológica y el servicio a la sociedad.',
                'vision' => 'Ser reconocidos a nivel nacional e internacional como líderes en la formación de ingenieros de sistemas, destacando por nuestra excelencia académica.',
                'perfil_profesional' => 'El Ingeniero de Sistemas formado en nuestra institución posee una sólida base de conocimientos en áreas clave como la programación, la ingeniería de software y la gestión de bases de datos.',
                'sede_id' => $sedeCBA->id,
            ],
            [
                'codigo' => 'CIV',
                'nombre' => 'Ingeniería Civil',
                'area' => 'Ciencias Exactas y Tecnología',
                'mision' => 'Formar profesionales de excelencia en ingeniería civil, comprometidos con el desarrollo sostenible de la infraestructura nacional.',
                'vision' => 'Ser referentes en la formación de ingenieros civiles innovadores y éticos que contribuyan al progreso de Bolivia.',
                'perfil_profesional' => 'El Ingeniero Civil de UNITEPC está capacitado para diseñar, construir y supervisar obras civiles.',
                'sede_id' => $sedeCBA->id,
            ],
            [
                'codigo' => 'MED',
                'nombre' => 'Medicina',
                'area' => 'Ciencias de la Salud',
                'mision' => 'Formar médicos humanistas con excelencia académica y compromiso con la salud de la población.',
                'vision' => 'Ser la facultad líder en formación médica integral en Bolivia.',
                'perfil_profesional' => 'El Médico de UNITEPC está formado con sólidos conocimientos científicos, destrezas clínicas y valores humanísticos.',
                'sede_id' => $sedeCBA->id,
            ],
            [
                'codigo' => 'ODO',
                'nombre' => 'Odontología',
                'area' => 'Ciencias de la Salud',
                'mision' => 'Formar odontólogos con excelencia académica y vocación de servicio.',
                'vision' => 'Ser referentes en la formación de profesionales de la salud bucal.',
                'perfil_profesional' => 'El Odontólogo de UNITEPC está capacitado para prevenir, diagnosticar y tratar enfermedades bucales con enfoque integral.',
                'sede_id' => $sedeCBA->id,
            ],
            [
                'codigo' => 'DER',
                'nombre' => 'Derecho',
                'area' => 'Ciencias Jurídicas y Sociales',
                'mision' => 'Formar abogados con sólidos conocimientos jurídicos y valores éticos para la defensa de la justicia.',
                'vision' => 'Ser reconocidos como formadores de profesionales del derecho comprometidos con la justicia social.',
                'perfil_profesional' => 'El Abogado de UNITEPC posee conocimientos profundos del ordenamiento jurídico.',
                'sede_id' => $sedeCBA->id,
            ],
            [
                'codigo' => 'COM',
                'nombre' => 'Ingeniería Comercial',
                'area' => 'Ciencias Económicas y Empresariales',
                'mision' => 'Formar profesionales líderes en gestión empresarial con visión estratégica e innovadora.',
                'vision' => 'Ser la carrera líder en formación de ejecutivos que impulsen el desarrollo económico del país.',
                'perfil_profesional' => 'El Ingeniero Comercial de UNITEPC está preparado para liderar y gestionar organizaciones.',
                'sede_id' => $sedeCBA->id,
            ]
        ];

        foreach ($carreras as $data) {
            Carrera::updateOrCreate(
                ['codigo' => $data['codigo'], 'sede_id' => $data['sede_id']],
                $data
            );
        }

        // Replicar para LA PAZ si existe (ejemplo)
        $sedeLPZ = Sede::where('codigo', 'LPZ')->first();
        if ($sedeLPZ) {
            foreach ($carreras as $data) {
                // Solo replicamos algunas
                if (in_array($data['codigo'], ['SIS', 'MED', 'DER'])) {
                    $newData = $data;
                    $newData['sede_id'] = $sedeLPZ->id;
                    Carrera::updateOrCreate(
                        ['codigo' => $newData['codigo'], 'sede_id' => $newData['sede_id']],
                        $newData
                    );
                }
            }
        }
    }
}
