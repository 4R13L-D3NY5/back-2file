<?php

namespace App\Http\Controllers;

use App\Models\Sede;
use Illuminate\Http\Request;

class SedeController extends Controller
{
    public function index()
    {
        $sedes = Sede::query()
            ->withCount(['carreras']) // Relación definida en Sede model
            ->orderBy('id')
            ->get()
            ->map(function ($sede) {
                return [
                    'id' => $sede->id,
                    'nombre' => $sede->nombre,
                    'codigo' => $sede->codigo,
                    'ciudad' => $sede->ciudad,
                    'direccion' => $sede->direccion,
                    'telefono' => $sede->telefono,
                    'activo' => $sede->activo,
                    // Stats calculadas o simuladas por ahora
                    'carreras_count' => $sede->carreras_count,
                    'docentes_count' => $this->calculateDocentes($sede->id), // Simulamos métrica compleja
                    'progreso' => rand(50, 95) // Simulamos progreso
                ];
            });

        return response()->json($sedes);
    }

    public function show($id)
    {
        return Sede::with('carreras')->findOrFail($id);
    }

    private function calculateDocentes($sedeId)
    {
        // En un futuro esto sumará los docentes de las carreras asignadas
        $base = [
            1 => 180,
            2 => 150,
            3 => 165,
            4 => 95,
            5 => 110,
            6 => 70,
            7 => 80,
            8 => 55,
            9 => 45
        ];
        return $base[$sedeId] ?? 0;
    }
}
