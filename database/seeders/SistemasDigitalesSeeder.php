<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Asignatura;
use App\Models\Docente;
use App\Models\Grupo;
use App\Models\Unidad;
use App\Models\Tema;
use App\Models\Cronograma;
use App\Models\PlanificacionPersonal;
use Carbon\Carbon;

class SistemasDigitalesSeeder extends Seeder
{
    public function run()
    {
        $userId = 60;
        $asignaturaId = 580;

        $user = User::find($userId);
        $asignatura = Asignatura::find($asignaturaId);

        if (!$user || !$asignatura) {
            $this->command->error("User $userId or Asignatura $asignaturaId not found.");
            return;
        }

        $this->command->info("Populating data for: " . $asignatura->nombre);

        // 1. Update Asignatura Details
        $asignatura->update([
            'justificacion' => 'La asignatura de Sistemas Digitales I es fundamental para la formación del Ingeniero en Sonido, ya que proporciona las bases lógicas y matemáticas para comprender el funcionamiento de los sistemas electrónicos digitales modernos, procesadores y hardware de audio digital.',
            'competencia_asignatura' => 'Diseña y analiza circuitos digitales combinacionales y secuenciales básicos utilizando álgebra de Boole y compuertas lógicas para la resolución de problemas de hardware.',
            'metodologia_general' => [
                'Clases Teóricas Expositivas',
                'Resolución de Ejercicios en Pizarra',
                'Laboratorios de Simulación (Proteus/Multisim)',
                'Aprendizaje Basado en Proyectos'
            ],
            'sistema_evaluacion' => [
                '1er Parcial: 30%',
                '2do Parcial: 30%',
                'Examen Final: 40%'
            ],
            'carga_horaria_total' => 80,
            'horas_teoricas' => 40,
            'horas_practicas' => 40,
        ]);

        // 2. Ensure Docente Exists
        $docente = Docente::firstOrCreate(
            ['user_id' => $userId],
            [
                'nombre' => 'DANIEL',
                'apellido' => 'FUENTES VILLARROEL',
                'ci' => '6526617',
                'telefono' => '70000000',
                'especialidad' => 'Ingeniería Electrónica / Sonido'
            ]
        );

        // 3. Ensure Grupo Exists
        $grupo = Grupo::firstOrCreate(
            [
                'asignatura_id' => $asignaturaId,
                'docente_id' => $docente->id
            ],
            [
                'nombre' => 'GRP-A',
                'gestion' => '1-2026',
                'tipo' => 'TEORIA',
                'turno' => 'NOCHE',
                'cupo_maximo' => 40
            ]
        );

        // 4. Create Unidades and Temas
        $unidadesData = [
            [
                'numero' => 1,
                'titulo' => 'Sistemas de Numeración y Códigos',
                'objetivo' => 'Comprender y operar con diferentes sistemas numéricos binarios y códigos digitales.',
                'contenido_minimo' => 'Sistemas binario, octal, hexadecimal. Conversiones. Códigos BCD, ASCII, Gray.',
                'temas' => [
                    ['titulo' => 'Introducción a Sistemas Digitales', 'tipo' => 'TEORIA'],
                    ['titulo' => 'Sistemas de Numeración (Binario, Hexadecimal)', 'tipo' => 'TEORIA'],
                    ['titulo' => 'Conversión entre bases numéricas', 'tipo' => 'PRACTICA'],
                    ['titulo' => 'Códigos Binarios (BCD, Alfanuméricos)', 'tipo' => 'TEORIA']
                ]
            ],
            [
                'numero' => 2,
                'titulo' => 'Álgebra de Boole y Compuertas Lógicas',
                'objetivo' => 'Aplicar el álgebra de Boole para la simplificación de circuitos lógicos.',
                'contenido_minimo' => 'Teoremas de Boole. Compuertas AND, OR, NOT, NAND, NOR, XOR. Mapas de Karnaugh.',
                'temas' => [
                    ['titulo' => 'Postulados y Teoremas del Álgebra de Boole', 'tipo' => 'TEORIA'],
                    ['titulo' => 'Compuertas Lógicas Básicas y Universales', 'tipo' => 'TEORIA'],
                    ['titulo' => 'Simplificación de Funciones Lógicas', 'tipo' => 'PRACTICA'],
                    ['titulo' => 'Mapas de Karnaugh (3 y 4 variables)', 'tipo' => 'PRACTICA']
                ]
            ],
            [
                'numero' => 3,
                'titulo' => 'Circuitos Combinacionales',
                'objetivo' => 'Diseñar circuitos lógicos combinacionales para aplicaciones específicas.',
                'contenido_minimo' => 'Sumadores, Restadores, Decodificadores, Multiplexores.',
                'temas' => [
                    ['titulo' => 'Diseño de Circuitos Combinacionales', 'tipo' => 'TEORIA'],
                    ['titulo' => 'Sumadores y Restadores Binarios', 'tipo' => 'TEORIA'],
                    ['titulo' => 'Codificadores y Decodificadores', 'tipo' => 'TEORIA'],
                    ['titulo' => 'Multiplexores y Demultiplexores', 'tipo' => 'TEORIA']
                ]
            ]
        ];

        // Clean existing units if creating new ones structure
        if ($asignatura->unidades()->count() == 0) {
            foreach ($unidadesData as $uData) {
                $unidad = Unidad::create([
                    'asignatura_id' => $asignaturaId,
                    'numero' => $uData['numero'],
                    'titulo' => $uData['titulo'],
                    'objetivo' => $uData['objetivo'],
                    'contenido_minimo' => $uData['contenido_minimo']
                ]);

                foreach ($uData['temas'] as $order => $tData) {
                    $tema = Tema::create([
                        'unidad_id' => $unidad->id,
                        'titulo' => $tData['titulo'],
                        'orden' => $order + 1,
                        'tipo' => $tData['tipo'],
                        'contenido_conceptual' => ['Conceptos fundamentales de ' . $tData['titulo']],
                        'contenido_procedimental' => ['Resolución de problemas de ' . $tData['titulo']],
                        'contenido_actitudinal' => ['Participación activa', 'Pensamiento crítico'],
                        'estrategias_metodologicas' => 'Clase magistral participativa',
                        'evaluacion_formativa' => ['actividades' => ['Preguntas en clase', 'Ejercicios rápidos']]
                    ]);

                    // 5. Create Planificacion Personal automatically for teacher
                    PlanificacionPersonal::create([
                        'tema_id' => $tema->id,
                        'user_id' => $userId,
                        'estrategias_recursos' => ['Pizarra', 'Proyector', 'Simulador'],
                        'evaluacion_formativa' => ['actividades' => ['Práctica en clase']],
                        'secuencia_didactica' => [
                            ['momento' => 'Inicio', 'actividad' => 'Introducción al tema y motivación'],
                            ['momento' => 'Desarrollo', 'actividad' => 'Explicación teórica y ejemplos prácticos'],
                            ['momento' => 'Cierre', 'actividad' => 'Resumen y conclusiones']
                        ]
                    ]);
                }
            }
        }

        // 6. Create Cronograma (Schedule)
        // Configure term dates
        $asignatura->update([
            'fecha_inicio_clases' => '2026-02-02',
            'fecha_fin_clases' => '2026-06-30',
            'gestion_academica' => '1-2026'
        ]);

        // Delete existing cronograma for this group
        Cronograma::where('grupo_id', $grupo->id)->delete();

        // Get all themes ordered
        $allTemas = Tema::whereHas('unidad', function ($q) use ($asignaturaId) {
            $q->where('asignatura_id', $asignaturaId);
        })->orderBy('unidad_id')->orderBy('orden')->get();

        $startDate = Carbon::parse('2026-02-02');
        $currentDate = $startDate->copy();

        // Days of class: Monday (1) and Thursday (4)
        $classDays = [1, 4];
        $temaIndex = 0;
        $sessionCount = 1;

        for ($semana = 1; $semana <= 20; $semana++) {
            foreach ($classDays as $dayOfWeek) {
                // Set date to the correct day of this week
                $sessionDate = $startDate->copy()->addWeeks($semana - 1)->startOfWeek()->addDays($dayOfWeek - 1);

                // Exams Schedule (Fixed weeks)
                $periodoExamen = null;
                $temaId = null;
                $conceptual = null;
                $esExamen = false;

                // 1er Parcial Week 8
                if ($semana == 8 && $dayOfWeek == 1) { // Monday of week 8
                    $periodoExamen = '1er Parcial';
                    $esExamen = true;
                }
                // 2do Parcial Week 15
                else if ($semana == 15 && $dayOfWeek == 1) {
                    $periodoExamen = '2do Parcial';
                    $esExamen = true;
                }
                // Final Week 19
                else if ($semana == 19 && $dayOfWeek == 1) {
                    $periodoExamen = 'Examen Final';
                    $esExamen = true;
                }
                // 2da Instancia Week 20
                else if ($semana == 20 && $dayOfWeek == 4) {
                    $periodoExamen = '2da Instancia';
                    $esExamen = true;
                }
                // Regular Class
                else {
                    if ($temaIndex < $allTemas->count()) {
                        $tema = $allTemas[$temaIndex];
                        $temaId = $tema->id;
                        $conceptual = $tema->titulo;

                        // Advance theme every 2 sessions approx
                        if ($sessionCount % 2 == 0) {
                            $temaIndex++;
                        }
                    } else {
                        $conceptual = "Repaso y Práctica General";
                    }
                }

                // Create Cronograma
                $cronograma = Cronograma::create([
                    'asignatura_id' => $asignaturaId,
                    'grupo_id' => $grupo->id,
                    'numero_sesion' => $sessionCount++,
                    'fecha' => $sessionDate->format('Y-m-d'),
                    'semana_academica' => $semana,
                    'periodo_examen' => $periodoExamen,
                    'tema_id' => $temaId,
                    'contenido_conceptual' => $conceptual,
                    'contenido_procedimental' => $esExamen ? 'Evaluación escrita' : 'Resolución de ejercicios',
                    'contenido_actitudinal' => $esExamen ? 'Honestidad' : 'Participación',
                    'cumplido' => $sessionDate->lt(now()) // Mark past dates as done
                ]);

                if ($temaId) {
                    $cronograma->temas()->attach($temaId);
                }
            }
        }

        $this->command->info("Seeding completed successfully.");
    }
}
