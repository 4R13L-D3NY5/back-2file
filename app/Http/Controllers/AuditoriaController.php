<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditoriaController extends Controller
{
    public function index(Request $request)
    {
        $query = Auditoria::with(['asignatura', 'docente', 'auditor']);

        if ($request->has('asignatura_id')) {
            $query->where('asignatura_id', $request->asignatura_id);
        }

        if ($request->has('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        $auditorias = $query->orderBy('created_at', 'desc')->get();

        return response()->json($auditorias);
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'asignatura_id' => 'required|exists:asignaturas,id',
                'docente_id' => 'required|exists:docentes,id',
                'semana' => 'required|string',
                'tipo' => 'required|string',
                'criterios' => 'required|array',
            ]);

            // Auto-calculate semaforo based on criterios to prevent front-end tampering
            $criteriosFiltered = array_filter($request->criterios, function($val) {
                return $val !== null;
            });
            $positivos = count(array_filter($criteriosFiltered, function($val) {
                return $val === true;
            }));

            $semaforoCalc = 'rojo';
            if (empty($criteriosFiltered)) {
                $semaforoCalc = 'verde';
            } elseif ($positivos === 5) {
                $semaforoCalc = 'verde';
            } elseif ($positivos >= 3) {
                $semaforoCalc = 'amarillo';
            }

            $auditoria = Auditoria::create([
                'asignatura_id' => $request->asignatura_id,
                'docente_id' => $request->docente_id,
                'auditor_id' => $request->user()->id,
                'semana' => $request->semana,
                'tipo' => $request->tipo,
                'criterios' => $request->criterios,
                'observaciones' => $request->observaciones,
                'acciones_correctivas' => $request->acciones_correctivas,
                'semaforo' => $semaforoCalc,
            ]);

            return response()->json([
                'message' => 'Auditoría guardada correctamente', 
                'auditoria' => $auditoria
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error guardando auditoria: ' . $e->getMessage());
            return response()->json(['message' => 'Error al guardar la auditoría', 'error' => $e->getMessage()], 500);
        }
    }
}
