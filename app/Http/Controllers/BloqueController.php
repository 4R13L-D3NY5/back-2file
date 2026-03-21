<?php

namespace App\Http\Controllers;

use App\Models\Bloque;
use Illuminate\Http\Request;

class BloqueController extends Controller
{
    /** GET /api/bloques */
    public function index(Request $request)
    {
        $query = Bloque::with(['sede', 'aulas']);

        if ($request->filled('sede_id')) {
            $query->where('sede_id', $request->sede_id);
        }

        return response()->json($query->orderBy('nombre')->get());
    }

    /** POST /api/bloques */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre'  => 'required|string|max:100',
            'sede_id' => 'required|exists:sedes,id',
        ]);

        $bloque = Bloque::create($validated);
        $bloque->load('sede');

        return response()->json($bloque, 201);
    }

    /** PUT /api/bloques/{id} */
    public function update(Request $request, $id)
    {
        $bloque = Bloque::findOrFail($id);

        $validated = $request->validate([
            'nombre'  => 'sometimes|string|max:100',
            'sede_id' => 'sometimes|exists:sedes,id',
        ]);

        $bloque->update($validated);
        $bloque->load('sede');

        return response()->json($bloque);
    }

    /** DELETE /api/bloques/{id} */
    public function destroy($id)
    {
        $bloque = Bloque::findOrFail($id);
        // Verificar si tiene aulas
        if ($bloque->aulas()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar el bloque porque tiene aulas asignadas. Elimina primero las aulas.'
            ], 422);
        }
        $bloque->delete();
        return response()->json(null, 204);
    }
}
