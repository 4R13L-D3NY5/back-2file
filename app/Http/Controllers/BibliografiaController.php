<?php

namespace App\Http\Controllers;

use App\Models\Bibliografia;
use App\Models\Asignatura;
use Illuminate\Http\Request;

class BibliografiaController extends Controller
{
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

        return response()->json($bibliografia, 201);
    }

    /**
     * Actualizar referencia
     */
    public function update(Request $request, $id)
    {
        $bibliografia = Bibliografia::findOrFail($id);
        $bibliografia->update($request->all());

        return response()->json($bibliografia);
    }

    /**
     * Eliminar referencia
     */
    public function destroy($id)
    {
        $bibliografia = Bibliografia::findOrFail($id);
        $bibliografia->delete();

        return response()->json(['message' => 'Eliminado correctamente']);
    }
}
