<?php

namespace App\Http\Controllers;

use App\Services\CargaAcademicaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CargaAcademicaController extends Controller
{
    protected CargaAcademicaService $cargaService;

    public function __construct(CargaAcademicaService $cargaService)
    {
        $this->cargaService = $cargaService;
    }

    /**
     * GET /api/carga-academica/materia
     * Query: sede_id, carrera_id, asignatura_id, [gestion]
     */
    public function getByMateria(Request $request)
    {
        $request->validate([
            'sede_id' => 'required|integer|exists:sedes,id',
            'carrera_id' => 'required|integer|exists:carreras,id',
            'asignatura_id' => 'required|integer|exists:asignaturas,id',
            'gestion' => 'nullable|string|max:20',
        ]);

        try {
            $data = $this->cargaService->getCargaByMateria(
                (int) $request->sede_id,
                (int) $request->carrera_id,
                (int) $request->asignatura_id,
                $request->gestion
            );

            return response()->json($data);
        } catch (\Throwable $e) {
            Log::error('CargaAcademicaController::getByMateria error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al obtener la carga académica: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/carga-academica/carrera
     * Query: sede_id, carrera_id, [gestion]
     */
    public function getByCarrera(Request $request)
    {
        $request->validate([
            'sede_id' => 'required|integer|exists:sedes,id',
            'carrera_id' => 'required|integer|exists:carreras,id',
            'gestion' => 'nullable|string|max:20',
        ]);

        try {
            $data = $this->cargaService->getCargaByCarrera(
                (int) $request->sede_id,
                (int) $request->carrera_id,
                $request->gestion
            );

            return response()->json($data);
        } catch (\Throwable $e) {
            Log::error('CargaAcademicaController::getByCarrera error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al obtener la carga académica: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/carga-academica/grupo
     */
    public function storeGrupo(Request $request)
    {
        try {
            $grupo = $this->cargaService->createGrupoConHorarios($request->all());

            return response()->json([
                'message' => 'Grupo creado exitosamente.',
                'grupo' => $grupo,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('CargaAcademicaController::storeGrupo error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al crear el grupo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/carga-academica/grupo/{id}
     */
    public function updateGrupo(Request $request, $id)
    {
        try {
            $grupo = $this->cargaService->updateGrupoConHorarios((int) $id, $request->all());

            return response()->json([
                'message' => 'Grupo actualizado exitosamente.',
                'grupo' => $grupo,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('CargaAcademicaController::updateGrupo error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al actualizar el grupo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/carga-academica/grupo/{id}/docente
     */
    public function assignDocente(Request $request, $id)
    {
        $request->validate([
            'docente_id' => 'nullable|integer|exists:docentes,id',
        ]);

        try {
            $grupo = $this->cargaService->assignDocente(
                (int) $id,
                $request->docente_id ? (int) $request->docente_id : null
            );

            return response()->json([
                'message' => 'Docente asignado exitosamente.',
                'grupo' => $grupo,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('CargaAcademicaController::assignDocente error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al asignar docente: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/carga-academica/validar
     * Valida conflictos sin guardar.
     */
    public function validar(Request $request)
    {
        $resultado = $this->cargaService->validarConflictos($request->all());

        return response()->json($resultado);
    }

    /**
     * POST /api/carga-academica/sync
     * Sincronización granular por materia + sede + carrera.
     */
    public function syncMateria(Request $request)
    {
        $request->validate([
            'sede_id' => 'required|integer|exists:sedes,id',
            'carrera_id' => 'required|integer|exists:carreras,id',
            'asignatura_id' => 'required|integer|exists:asignaturas,id',
            'gestion' => 'required|string|max:20',
        ]);

        try {
            $resultado = $this->cargaService->sincronizarMateria(
                (int) $request->sede_id,
                (int) $request->carrera_id,
                (int) $request->asignatura_id,
                $request->gestion
            );

            return response()->json($resultado);
        } catch (\Throwable $e) {
            Log::error('CargaAcademicaController::syncMateria error: ' . $e->getMessage());
            return response()->json([
                'ok' => false,
                'error' => 'Error en sincronización: ' . $e->getMessage(),
            ], 500);
        }
    }
}
