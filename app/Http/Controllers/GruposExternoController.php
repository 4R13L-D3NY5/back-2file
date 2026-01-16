<?php

namespace App\Http\Controllers;

use App\Services\GruposExternoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GruposExternoController extends Controller
{
    protected GruposExternoService $service;

    public function __construct(GruposExternoService $service)
    {
        $this->service = $service;
    }

    /**
     * Listar grupos desde la API externa
     *
     * Query params:
     * - gestion: string (ej: "1-2026")
     * - carrera: string (ej: "carsis")
     * - sede: int (ej: 1)
     */
    public function index(Request $request): JsonResponse
    {
        $gestion = $request->input('gestion', '1-2026');
        $carrera = $request->input('carrera', 'carsis');
        $sede = (int) $request->input('sede', 1);

        $data = $this->service->listarGrupos($gestion, $carrera, $sede);

        // Calcular estadísticas
        $totalGrupos = 0;
        $totalDocentes = [];
        foreach ($data as $materia) {
            $totalGrupos += count($materia['grupos']);
            foreach ($materia['grupos'] as $grupo) {
                if (!empty($grupo['docente_ci'])) {
                    $totalDocentes[$grupo['docente_ci']] = $grupo['docente'];
                }
            }
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'gestion' => $gestion,
                'carrera' => strtoupper($carrera),
                'sede' => $sede,
                'total_materias' => count($data),
                'total_grupos' => $totalGrupos,
                'total_docentes' => count($totalDocentes)
            ]
        ]);
    }

    /**
     * Limpiar cache y recargar datos
     */
    public function refresh(Request $request): JsonResponse
    {
        $gestion = $request->input('gestion', '1-2026');
        $carrera = $request->input('carrera', 'carsis');
        $sede = (int) $request->input('sede', 1);

        $this->service->limpiarCache($gestion, $carrera, $sede);
        $data = $this->service->listarGrupos($gestion, $carrera, $sede);

        return response()->json([
            'message' => 'Cache actualizado',
            'data' => $data,
            'meta' => [
                'total_materias' => count($data)
            ]
        ]);
    }
}
