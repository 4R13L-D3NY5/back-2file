<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\Cronograma;
use App\Models\Horario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanificacionSemestralController extends Controller
{
    /**
     * Obtiene todo el estado de la planificación semestral
     * (Configuración, Horarios, Sessions de Cronograma)
     */
    public function index($asignaturaId)
    {
        $asignatura = Asignatura::with(['horarios', 'cronogramas' => function($q) {
            $q->orderBy('numero_sesion');
        }])->findOrFail($asignaturaId);

        return response()->json([
            'config' => [
                'fecha_inicio_clases' => $asignatura->fecha_inicio_clases,
                'fecha_fin_clases' => $asignatura->fecha_fin_clases,
                'gestion_academica' => $asignatura->gestion_academica
            ],
            'horarios' => $asignatura->horarios,
            'planificacion' => $asignatura->cronogramas
        ]);
    }

    /**
     * Guarda la configuración del calendario y los horarios
     */
    public function saveConfig(Request $request, $asignaturaId)
    {
        $asignatura = Asignatura::findOrFail($asignaturaId);
        
        DB::transaction(function () use ($asignatura, $request) {
            // 1. Update Asignatura Dates
            $asignatura->update($request->only([
                'fecha_inicio_clases', 
                'fecha_fin_clases', 
                'gestion_academica'
            ]));

            // 2. Sync Horarios (Delete All and Re-create)
            if ($request->has('horarios')) {
                $asignatura->horarios()->delete();
                $asignatura->horarios()->createMany($request->input('horarios'));
            }
        });

        return response()->json(['message' => 'Configuración guardada']);
    }

    /**
     * Guarda (sobrescribe) la planificación de sesiones (Cronograma)
     */
    public function savePlanificacion(Request $request, $asignaturaId)
    {
        $asignatura = Asignatura::findOrFail($asignaturaId);
        $sesiones = $request->input('sesiones', []);

        DB::transaction(function () use ($asignatura, $sesiones) {
            // Option A: Delete all and re-insert (Cleanest for full re-generation)
            // Option B: Upsert (Better if we want to preserve IDs).
            // Given the UI allows "Regenerate", IDs in frontend are likely virtual (1, 2, 3...) until saved.
            // Let's use Delete-Insert for simplicity and robustness against order changes.
            
            $asignatura->cronogramas()->delete();

            $dataToInsert = array_map(function ($sesion) {
                return [
                    'numero_sesion' => $sesion['numeroGlobal'] ?? $sesion['numero_sesion'],
                    'fecha' => $this->parseDate($sesion['fecha']), // Ensure YYYY-MM-DD
                    'semana_academica' => $sesion['semana'],
                    'periodo_examen' => $sesion['periodoExamen'] ?? null,
                    'tema_id' => null, // TODO: Link to real Tema ID if provided? UI sends 'tema' string usually.
                    
                    // Strings
                    'contenido_conceptual' => $sesion['conceptual'] ?? null,
                    'contenido_procedimental' => $sesion['procedimental'] ?? null,
                    'contenido_actitudinal' => $sesion['actitudinal'] ?? null,
                    'criterios_desempeno' => $sesion['criteriosDesempeno'] ?? null,
                    'instrumentos_evaluacion' => $sesion['instrumentosEvaluacion'] ?? null,
                    
                    // Flags
                    'observaciones' => null // Optional extra field
                ];
            }, $sesiones);

            if (!empty($dataToInsert)) {
                $asignatura->cronogramas()->createMany($dataToInsert);
            }
        });

        return response()->json([
            'message' => 'Planificación guardada',
            'count' => count($sesiones)
        ]);
    }
    
    /**
     * Genera automáticamente las sesiones basado en Horarios y Fechas
     */
    public function generarPlanificacion(Request $request, $asignaturaId)
    {
        $asignatura = Asignatura::with('horarios')->findOrFail($asignaturaId);
        
        if (!$asignatura->fecha_inicio_clases || !$asignatura->horarios->count()) {
            return response()->json(['error' => 'Configure fechas y horario primero'], 400); 
        }

        $startDate = \Carbon\Carbon::parse($asignatura->fecha_inicio_clases);
        $endDate = \Carbon\Carbon::parse($asignatura->fecha_fin_clases);
        $horarios = $asignatura->horarios;
        $sesiones = [];
        $count = 1;

        // Semanas Académicas (1 a 20)
        for ($semana = 1; $semana <= 20; $semana++) {
            
            // Determinar si es semana de examen (Lógica fija solicitada)
            $periodoExamen = null;
            if ($semana >= 7 && $semana <= 8) $periodoExamen = '1er Parcial';
            elseif ($semana >= 14 && $semana <= 15) $periodoExamen = '2do Parcial';
            elseif ($semana >= 18 && $semana <= 19) $periodoExamen = 'Examen Final';
            elseif ($semana == 20) $periodoExamen = '2da Instancia';

            // Iterar horarios para esta semana
            foreach ($horarios as $horario) {
                // Calcular fecha exacta
                // $startDate es el inicio del semestre (Lunes o dia X).
                // Asumiremos que start date es el Inicio Semestral.
                // Necesitamos encontrar el primer "Lunes/Martes" a partir de startDate
                // O simplificar: startDate + (semana-1)*7 + offsetDia ?
                
                // Estrategia: "Next Day of Week" a partir del inicio de la semana
                $dayMap = [
                    'Lunes' => 1, 'Martes' => 2, 'Miercoles' => 3, 'Miércoles' => 3, 
                    'Jueves' => 4, 'Viernes' => 5, 'Sabado' => 6, 'Sábado' => 6
                ];
                
                $targetDia = $dayMap[ucfirst($horario->dia)] ?? 1;
                
                // Fecha base de la semana actual
                $weekStart = $startDate->copy()->addWeeks($semana - 1)->startOfWeek(); 
                // Ajustar al día específico
                $sessionDate = $weekStart->copy()->addDays($targetDia - 1);

                // Si la fecha calculada supera el fin de clases, break? (Opcional)
                // if ($sessionDate->gt($endDate)) continue; 

                $sesiones[] = [
                    'asignatura_id' => $asignatura->id,
                    'numero_sesion' => $count++,
                    'fecha' => $sessionDate->format('Y-m-d'),
                    'semana_academica' => $semana,
                    'periodo_examen' => $periodoExamen,
                    // Si es examen, no lleva contenido (user request)
                    'contenido_conceptual' => $periodoExamen ? null : '', 
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
        }

        // Reemplazar existente
        DB::transaction(function () use ($asignatura, $sesiones) {
            $asignatura->cronogramas()->delete();
            Cronograma::insert($sesiones);
        });

        return response()->json(['message' => 'Planificación generada', 'total' => count($sesiones)]);
    }

    /**
     * Copia la planificación (Cronogramas) de otra asignatura (Unificación)
     */
    public function copiarPlanificacion(Request $request, $asignaturaId)
    {
        $request->validate(['source_asignatura_id' => 'required|exists:asignaturas,id']);
        
        $target = Asignatura::findOrFail($asignaturaId);
        $source = Asignatura::with('cronogramas')->findOrFail($request->source_asignatura_id);

        DB::transaction(function () use ($target, $source) {
            // Borrar actual
            $target->cronogramas()->delete();

            // Copiar
            $newCronogramas = [];
            foreach ($source->cronogramas as $c) {
                // Solo copiamos CONTENIDO, mantenemos fechas? 
                // El usuario pide "Unificar planificacion... variarian solo las fechas".
                // Esto es complejo si los horarios son distintos (Lunes/Miercoles vs Martes/Jueves).
                // Estrategia: Copiar contenido por "Número de Sesión".
                // Asumimos que target YA TIENE sesiones generadas (fechas correctas).
                // Actualizamos el contenido macheando numero_sesion.
                
                // Sin embargo, si target está vacío, no podemos machear.
                // Asumiremos: El usuario primero GENERA las fechas (Paso 1), luego IMPORTA contenido (Paso 2).
            }

            // Implementación "Update Content by Session Number"
            foreach ($source->cronogramas as $sourceItem) {
                $target->cronogramas()
                    ->where('numero_sesion', $sourceItem->numero_sesion)
                    ->update([
                        'contenido_conceptual' => $sourceItem->contenido_conceptual,
                        'contenido_procedimental' => $sourceItem->contenido_procedimental,
                        'contenido_actitudinal' => $sourceItem->contenido_actitudinal,
                        'criterios_desempeno' => $sourceItem->criterios_desempeno,
                        'instrumentos_evaluacion' => $sourceItem->instrumentos_evaluacion,
                        'tema_id' => $sourceItem->tema_id
                    ]);
            }
        });

        return response()->json(['message' => 'Contenidos importados correctamente']);
    }
