<?php

namespace App\Http\Controllers;

use App\Models\Asignatura;
use App\Models\Cronograma;
use App\Models\Horario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PlanificacionSemestralController extends Controller
{
    /**
     * Obtiene todo el estado de la planificación semestral
     * (Configuración, Horarios, Sessions de Cronograma)
     */
    public function index($asignaturaId, Request $request)
    {
        $grupoId = $request->input('grupo_id');

        $asignatura = Asignatura::with(['horarios', 'cronogramas' => function ($q) use ($grupoId) {
            $q->orderBy('numero_sesion')
                ->with(['temas', 'tema.planificacionPersonal' => function ($query) {
                    $query->where('user_id', Auth::id());
                }]);

            if ($grupoId) {
                $q->where('grupo_id', $grupoId);
            }
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
        $grupoId = $request->input('grupo_id');

        DB::transaction(function () use ($asignatura, $sesiones, $grupoId) {

            if ($grupoId) {
                $asignatura->cronogramas()->where('grupo_id', $grupoId)->delete();
            } else {
                $asignatura->cronogramas()->whereNull('grupo_id')->delete();
            }

            foreach ($sesiones as $sesionData) {
                $cronograma = $asignatura->cronogramas()->create([
                    'numero_sesion' => $sesionData['numeroGlobal'] ?? $sesionData['numero_sesion'],
                    'fecha' => $this->parseDate($sesionData['fecha']),
                    'semana_academica' => $sesionData['semana'] ?? null,
                    'periodo_examen' => $sesionData['periodoExamen'] ?? null,
                    'tema_id' => $sesionData['tema_id'] ?? null,
                    'grupo_id' => $grupoId,
                    'contenido_conceptual' => $sesionData['conceptual'] ?? null,
                    'contenido_procedimental' => $sesionData['procedimental'] ?? null,
                    'contenido_actitudinal' => $sesionData['actitudinal'] ?? null,
                    'criterios_desempeno' => $sesionData['criteriosDesempeno'] ?? null,
                    'instrumentos_evaluacion' => $sesionData['instrumentosEvaluacion'] ?? null,
                    'observaciones' => $sesionData['observaciones'] ?? null
                ]);

                // Sincronizar múltiples temas si vienen en el request
                if (isset($sesionData['temas_ids']) && is_array($sesionData['temas_ids'])) {
                    $cronograma->temas()->sync($sesionData['temas_ids']);
                } elseif (!empty($sesionData['tema_id'])) {
                    $cronograma->temas()->sync([$sesionData['tema_id']]);
                }
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
                $dayMap = [
                    'Lunes' => 1,
                    'Martes' => 2,
                    'Miercoles' => 3,
                    'Miércoles' => 3,
                    'Jueves' => 4,
                    'Viernes' => 5,
                    'Sabado' => 6,
                    'Sábado' => 6
                ];

                $targetDia = $dayMap[ucfirst($horario->dia)] ?? 1;

                // Fecha base de la semana actual
                $weekStart = $startDate->copy()->addWeeks($semana - 1)->startOfWeek();
                // Ajustar al día específico
                $sessionDate = $weekStart->copy()->addDays($targetDia - 1);

                $sesiones[] = [
                    'asignatura_id' => $asignatura->id,
                    'numero_sesion' => $count++,
                    'fecha' => $sessionDate->format('Y-m-d'),
                    'semana_academica' => $semana,
                    'periodo_examen' => $periodoExamen,
                    'grupo_id' => $request->input('grupo_id'), // Link to Group
                    // Si es examen, no lleva contenido (user request)
                    'contenido_conceptual' => $periodoExamen ? null : '',
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
        }

        // Reemplazar existente
        DB::transaction(function () use ($asignatura, $sesiones, $request) {
            $grupoId = $request->input('grupo_id');
            if ($grupoId) {
                $asignatura->cronogramas()->where('grupo_id', $grupoId)->delete();
            } else {
                $asignatura->cronogramas()->whereNull('grupo_id')->delete();
            }
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
                // Implementation pending based on user requirements for copying
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

    /**
     * Actualiza el seguimiento de una sesión específica de cronograma
     */
    public function updateSeguimiento(Request $request, $id)
    {
        $cronograma = Cronograma::findOrFail($id);

        $cronograma->update([
            'cumplido' => $request->input('cumplido', false),
            'observaciones' => $request->input('observaciones'),
            'pedagogico' => $request->input('pedagogico') // JSON array
        ]);

        return response()->json(['message' => 'Seguimiento guardado correctamente']);
    }

    private function parseDate($dateString)
    {
        return \Carbon\Carbon::parse($dateString)->format('Y-m-d');
    }
}
