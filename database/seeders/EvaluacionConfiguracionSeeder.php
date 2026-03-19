<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EvaluacionConfiguracion;

class EvaluacionConfiguracionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Default configuration exactly as defined in the frontend
        $config = [
            'minutosAntesEntrega' => 15,
            'horasAntesGeneracion' => 48,
            'horasPatron' => 0,
            'alertaHorasAntes' => 24,
            'parciales' => [
                [
                    'nombre' => '1° Parcial',
                    'totalPreguntas' => 30,
                    'distribucion' => ['facil' => 7, 'medio' => 16, 'dificil' => 7]
                ],
                [
                    'nombre' => '2° Parcial',
                    'totalPreguntas' => 30,
                    'distribucion' => ['facil' => 7, 'medio' => 16, 'dificil' => 7]
                ],
                [
                    'nombre' => 'Examen Final',
                    'totalPreguntas' => 40,
                    'distribucion' => ['facil' => 10, 'medio' => 20, 'dificil' => 10]
                ],
                [
                    'nombre' => '2da Instancia',
                    'totalPreguntas' => 40,
                    'distribucion' => ['facil' => 10, 'medio' => 20, 'dificil' => 10]
                ]
            ]
        ];

        // Insert or update the national default configuration
        EvaluacionConfiguracion::updateOrCreate(
            ['nivel' => 'nacional', 'sede_id' => null, 'carrera_id' => null],
            ['configuracion' => $config]
        );
    }
}
