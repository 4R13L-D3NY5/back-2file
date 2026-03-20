<?php

namespace App\Http\Controllers;

use App\Models\EvaluacionTiempo;
use Illuminate\Http\Request;

class EvaluacionTiempoController extends Controller
{
    /**
     * Obtener configuración de tiempos para la gestión dada.
     * GET /api/evaluaciones/tiempos?gestion=1/2026
     */
    public function index(Request $request)
    {
        $gestion = $request->query('gestion', '1/2026'); // Fallback arbitrario si no se manda

        $config = EvaluacionTiempo::firstOrCreate(
            ['gestion' => $gestion],
            [
                'minutos_antes_entrega' => 15,
                'horas_antes_generacion' => 48,
                'horas_post_patron' => 0,
                'alerta_horas_antes' => 24
            ]
        );

        return response()->json([
            'success' => true,
            'configuracion' => $config
        ]);
    }

    /**
     * Guardar/Actualizar la configuración de tiempos para una gestión.
     * POST /api/evaluaciones/tiempos
     */
    public function store(Request $request)
    {
        $request->validate([
            'gestion' => 'required|string',
            'minutos_antes_entrega' => 'nullable|integer|min:1',
            'horas_antes_generacion' => 'nullable|integer|min:1',
            'horas_post_patron' => 'nullable|integer|min:0',
            'alerta_horas_antes' => 'nullable|integer|min:1',
        ]);

        $config = EvaluacionTiempo::updateOrCreate(
            ['gestion' => $request->gestion],
            [
                'minutos_antes_entrega' => $request->minutos_antes_entrega ?? 15,
                'horas_antes_generacion' => $request->horas_antes_generacion ?? 48,
                'horas_post_patron' => $request->horas_post_patron ?? 0,
                'alerta_horas_antes' => $request->alerta_horas_antes ?? 24
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Configuración de tiempos guardada correctamente',
            'configuracion' => $config
        ]);
    }
}
