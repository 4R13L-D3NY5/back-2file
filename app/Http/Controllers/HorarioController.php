<?php

namespace App\Http\Controllers;

use App\Models\Horario;
use Illuminate\Http\Request;

class HorarioController extends Controller
{
    /**
     * Listar horarios con filtros opcionales.
     * GET /api/horarios?grupo_id=1
     */
    public function index(Request $request)
    {
        $query = Horario::query();

        if ($request->has('grupo_id') && $request->grupo_id) {
            $query->where('grupo_id', $request->grupo_id);
        }

        if ($request->has('aula_id') && $request->aula_id) {
            $query->where('aula_id', $request->aula_id);
        }

        if ($request->has('dia') && $request->dia) {
            $query->where('dia', $request->dia);
        }

        $horarios = $query->with(['grupo', 'aula.bloque'])->get();

        return response()->json($horarios);
    }

    /**
     * Obtener detalles de un horario.
     * GET /api/horarios/{id}
     */
    public function show($id)
    {
        $horario = Horario::with(['grupo', 'aula.bloque'])->findOrFail($id);
        return response()->json($horario);
    }

    /**
     * Crear un nuevo horario.
     * POST /api/horarios
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'grupo_id' => 'required|exists:grupos,id',
            'aula_id' => 'nullable|exists:aulas,id',
            'dia' => 'required|string|max:20',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
            'id_horario_api' => 'nullable|integer|unique:horarios,id_horario_api',
        ]);

        // Establecer modificado_localmente en true ya que es creación local
        $validated['modificado_localmente'] = true;

        $horario = Horario::create($validated);

        return response()->json($horario, 201);
    }

    /**
     * Actualizar un horario existente.
     * PUT /api/horarios/{id}
     */
    public function update(Request $request, $id)
    {
        $horario = Horario::findOrFail($id);

        $validated = $request->validate([
            'grupo_id' => 'sometimes|exists:grupos,id',
            'aula_id' => 'nullable|exists:aulas,id',
            'dia' => 'sometimes|string|max:20',
            'hora_inicio' => 'sometimes|date_format:H:i',
            'hora_fin' => 'sometimes|date_format:H:i|after:hora_inicio',
            'id_horario_api' => 'nullable|integer|unique:horarios,id_horario_api,' . $horario->id,
        ]);

        // Si hay cambios en campos que pueden provenir de API, marcar como modificado localmente
        if ($horario->isDirty(['dia', 'hora_inicio', 'hora_fin', 'aula_id', 'grupo_id'])) {
            $validated['modificado_localmente'] = true;
        }

        $horario->update($validated);

        return response()->json($horario);
    }

    /**
     * Eliminar un horario.
     * DELETE /api/horarios/{id}
     */
    public function destroy($id)
    {
        $horario = Horario::findOrFail($id);
        $horario->delete();

        return response()->json(null, 204);
    }
}