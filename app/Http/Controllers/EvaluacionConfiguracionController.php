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

        // Busca el registro más específico
        $config = EvaluacionConfiguracion::where('nivel', $nivel);

        if ($nivel === 'sede' && $sedeId) {
            $config->where('sede_id', $sedeId);
        } elseif ($nivel === 'carrera' && $sedeId && $carreraId) {
            $config->where('sede_id', $sedeId)->where('carrera_id', $carreraId);
        }

        $configuracion = $config->first();

        // Fallback: si no hay para la sede o carrera, obtener la nacional
        if (!$configuracion && $nivel !== 'nacional') {
            $configuracion = EvaluacionConfiguracion::where('nivel', 'nacional')->first();
        }

        // Si por alguna razón ni siquiera hay nacional creada
        if (!$configuracion) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró la configuración en la base de datos local.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'configuracion' => $configuracion->configuracion,
            'nivel_hallado' => $configuracion->nivel
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
