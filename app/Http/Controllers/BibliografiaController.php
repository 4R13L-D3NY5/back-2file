<?php

namespace App\Http\Controllers;

use App\Models\Bibliografia;
use App\Models\Asignatura;
use App\Services\MateriasComunesSyncService;
use Illuminate\Http\Request;

class BibliografiaController extends Controller
{
    protected $syncService;

    public function __construct(MateriasComunesSyncService $syncService)
    {
        $this->syncService = $syncService;
    }
    /**
     * Listar bibliografía de una asignatura
     */
    public function index(Request $request)
    {
        $asignaturaId = $request->query('asignatura_id');
        if (!$asignaturaId) {
            return response()->json(['error' => 'asignatura_id es requerido'], 400);
        }

        $bibliografias = Bibliografia::where('asignatura_id', $asignaturaId)
                            ->orderBy('titulo')
                            ->get();

        return response()->json($bibliografias);
    }

    /**
     * Crear nueva referencia bibliográfica
     */
    public function store(Request $request)
    {
        $request->validate([
            'asignatura_id' => 'required|exists:asignaturas,id',
            'titulo' => 'required|string',
            'autor' => 'nullable|string',
        ]);

        $bibliografia = Bibliografia::create($request->all());

        if ($bibliografia->asignatura) {
            $this->syncService->syncBibliografias($bibliografia->asignatura);
        }

        return response()->json($bibliografia, 201);
    }

    /**
     * Actualizar referencia
     */
    public function update(Request $request, $id)
    {
        $bibliografia = Bibliografia::findOrFail($id);
        $bibliografia->update($request->all());

        if ($bibliografia->asignatura) {
            $this->syncService->syncBibliografias($bibliografia->asignatura);
        }

        return response()->json($bibliografia);
    }

    /**
     * Eliminar referencia
     */
    public function destroy($id)
    {
        $bibliografia = Bibliografia::findOrFail($id);
        $asignatura = $bibliografia->asignatura;
        $bibliografia->delete();

        if ($asignatura) {
            $this->syncService->syncBibliografias($asignatura);
        }

        return response()->json(['message' => 'Eliminado correctamente']);
    }
}
