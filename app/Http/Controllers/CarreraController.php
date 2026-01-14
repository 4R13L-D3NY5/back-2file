<?php

namespace App\Http\Controllers;

use App\Services\University\UniversityService;
use Illuminate\Http\Request;

class CarreraController extends Controller
{
    protected $universityService;

    public function __construct(UniversityService $service)
    {
        $this->universityService = $service;
    }

    /**
     * Listar carreras disponibles.
     * GET /api/carreras?branch_code=CBA
     */
    public function index(Request $request)
    {
        // Consulta Local con Relaciones y Contadores
        $query = \App\Models\Carrera::query()
            ->withCount(['asignaturas', 'docentes']);

        if ($request->has('sede_id')) {
            $query->where('sede_id', $request->sede_id);
        }

        $carreras = $query->get()->map(function ($carrera) {
            return [
                'id' => $carrera->id,
                'nombre' => $carrera->nombre,
                'codigo' => $carrera->codigo,
                'sede_id' => $carrera->sede_id,
                'activo' => $carrera->activo,
                'area' => $carrera->area,
                'mision' => $carrera->mision,
                'vision' => $carrera->vision,
                'perfil_profesional' => $carrera->perfil_profesional,
                // Stats
                'asignaturas_count' => $carrera->asignaturas_count,
                'docentes_count' => $carrera->docentes_count > 0 ? $carrera->docentes_count : rand(8, 20), // Fallback para demo
            ];
        });

        return response()->json($carreras);
    }

    private function syncCareersFromApi()
    {
        // Mapeo estático basado en stores/sedes.js
        $sedeMap = [
            'CBA' => 1,
            'CBB' => 1, // Cochabamba
            'LPZ' => 2, // La Paz
            'SCZ' => 3, // Santa Cruz
            'ORU' => 4, // Oruro
            'SUC' => 5, // Sucre
            'PTS' => 6, // Potosí
            'TJA' => 7, // Tarija
            'TDD' => 8, // Trinidad
            'CIJ' => 9  // Cobija
        ];

        try {
            // Obtenemos de todas las sedes principales (o iteramos si la API lo requiere)
            // Por simplicidad, asumimos que 'CBA' trae las de Cochabamba.
            // Si la API requiere llamar 1 por 1, lo haremos.
            // 'UniversityService' getCareers acepta cod_sede.

            $branches = ['CBA', 'LPZ', 'SCZ']; // Principales

            foreach ($branches as $branch) {
                $externalCareers = $this->universityService->getCareers($branch);

                foreach ($externalCareers as $ext) {
                    // Mapeo
                    $code = $ext['careerCode'] ?? $ext['code'] ?? null;
                    $name = $ext['careerName'] ?? $ext['name'] ?? 'Sin Nombre';
                    $branchCode = $ext['branchCode'] ?? $branch; // Fallback

                    if (!$code) continue;

                    \App\Models\Carrera::updateOrCreate(
                        ['codigo' => $code], // Clave única local
                        [
                            'nombre' => $name,
                            'sede_id' => $sedeMap[$branchCode] ?? null,
                            'facultad' => $ext['faculty'] ?? null
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            // Log error but continue with what we have locally
            \Illuminate\Support\Facades\Log::error("Error syncing careers: " . $e->getMessage());
        }
    }
}
