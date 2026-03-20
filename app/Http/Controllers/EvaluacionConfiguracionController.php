<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\EvaluacionConfiguracion;
use Illuminate\Support\Facades\Validator;

class EvaluacionConfiguracionController extends Controller
{
    /**
     * Obtener la configuración general u orientada a sede/carrera.
     */
    public function obtenerConfiguracion(Request $request)
    {
        $nivel = $request->input('nivel', 'nacional');
        $sedeId = $request->input('sede_id');
        $carreraId = $request->input('carrera_id');

        // Busca el registro más específico para saber si existe o es heredado
        $configQuery = EvaluacionConfiguracion::where('nivel', $nivel);

        if ($nivel === 'sede' && $sedeId) {
            $configQuery->where('sede_id', $sedeId);
        } elseif ($nivel === 'carrera' && $sedeId && $carreraId) {
            $configQuery->where('sede_id', $sedeId)->where('carrera_id', $carreraId);
        }

        $configuracionPropia = $configQuery->first();

        // Obtener la efectiva evaluando la herencia hacia arriba
        $efectiva = EvaluacionConfiguracion::obtenerConfiguracionEfectiva(
            $nivel === 'nacional' ? null : $sedeId, 
            $nivel === 'carrera' ? $carreraId : null
        );

        if (!$efectiva) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró la configuración en la base de datos local.',
            ], 404);
        }

        $origen = $efectiva['_origen'] ?? 'nacional';
        unset($efectiva['_origen']);

        return response()->json([
            'success' => true,
            'configuracion' => $efectiva,
            'nivel_hallado' => $origen,
            'es_propia' => $configuracionPropia !== null
        ]);
    }

    /**
     * Guardar o actualizar la configuración
     */
    public function guardarConfiguracion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nivel' => 'required|in:nacional,sede,carrera',
            'configuracion' => 'required|array',
            'sede_id' => 'nullable|integer',
            'carrera_id' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de configuración no válidos.',
                'errors' => $validator->errors()
            ], 422);
        }

        $nivel = $request->nivel;
        $sedeId = $request->sede_id;
        $carreraId = $request->carrera_id;

        // Limpiar para asegurar compatibilidad única
        if ($nivel === 'nacional') {
            $sedeId = null;
            $carreraId = null;
        } elseif ($nivel === 'sede') {
            $carreraId = null;
        }

        $evalConfig = EvaluacionConfiguracion::updateOrCreate(
            [
                'nivel' => $nivel,
                'sede_id' => $sedeId,
                'carrera_id' => $carreraId
            ],
            [
                'configuracion' => $request->configuracion
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Configuración de evaluaciones guardada correctamente.',
            'data' => $evalConfig
        ]);
    }
}
