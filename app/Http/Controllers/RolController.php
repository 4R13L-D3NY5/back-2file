<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use Illuminate\Http\Request;

class RolController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // El frontend espera esto en el formato del store.
        // El store espera: id, nombre, codigo, descripcion, color, icono, activo, permisos (array), fechaCreacion, orden
        $roles = Rol::orderBy('orden', 'asc')->get();
        return response()->json($roles);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'codigo' => 'required|string|unique:roles,codigo',
            'descripcion' => 'nullable|string',
            'color' => 'required|string',
            'icono' => 'required|string',
            'permisos' => 'array',
            'activo' => 'boolean',
        ]);

        // Calcular orden automáticamente (último + 1)
        $validated['orden'] = Rol::max('orden') + 1;

        // Permisos ya viene como array, el cast en Modelo se encarga de JSON.
        // Pero si usamos TEXT en BD, y cast en Modelo, todo fluye.

        $rol = Rol::create($validated);

        return response()->json($rol, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $rol = Rol::findOrFail($id);
        return response()->json($rol);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $rol = Rol::findOrFail($id);

        $validated = $request->validate([
            'nombre' => 'sometimes|string|max:255',
            'codigo' => 'sometimes|string|unique:roles,codigo,' . $id,
            'descripcion' => 'nullable|string',
            'color' => 'sometimes|string',
            'icono' => 'sometimes|string',
            'permisos' => 'array',
            'activo' => 'boolean',
        ]);

        $rol->update($validated);

        return response()->json($rol);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $rol = Rol::findOrFail($id);
        $rol->delete(); // Si hay FKs, fallará si no es cascade.
        return response()->json(null, 204);
    }
}
