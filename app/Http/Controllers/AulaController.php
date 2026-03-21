<?php

namespace App\Http\Controllers;

use App\Models\Aula;
use Illuminate\Http\Request;

class AulaController extends Controller
{
    /** GET /api/aulas */
    public function index(Request $request)
    {
        $query = Aula::with('bloque.sede');

        if ($request->filled('bloque_id')) {
            $query->where('bloque_id', $request->bloque_id);
        }
        if ($request->filled('sede_id')) {
            $query->whereHas('bloque', fn($q) => $q->where('sede_id', $request->sede_id));
        }

        return response()->json($query->orderBy('nombre')->get());
    }

    /** POST /api/aulas */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre'    => 'required|string|max:50',
            'bloque_id' => 'required|exists:bloques,id',
            'capacidad' => 'nullable|integer|min:0',
            'pupitres'  => 'nullable|integer|min:0',
        ]);

        $aula = Aula::create($validated);
        $aula->load('bloque.sede');

        return response()->json($aula, 201);
    }

    /** PUT /api/aulas/{id} */
    public function update(Request $request, $id)
    {
        $aula = Aula::findOrFail($id);

        $validated = $request->validate([
            'nombre'    => 'sometimes|string|max:50',
            'bloque_id' => 'sometimes|exists:bloques,id',
            'capacidad' => 'nullable|integer|min:0',
            'pupitres'  => 'nullable|integer|min:0',
        ]);

        $aula->update($validated);
        $aula->load('bloque.sede');

        return response()->json($aula);
    }

    /** DELETE /api/aulas/{id} */
    public function destroy($id)
    {
        $aula = Aula::findOrFail($id);
        $aula->delete();
        return response()->json(null, 204);
    }
}
