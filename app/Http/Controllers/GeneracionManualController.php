<?php

namespace App\Http\Controllers;

use App\Models\GeneracionManual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GeneracionManualController extends Controller
{
    public function index(Request $request)
    {
        $query = GeneracionManual::with('user', 'sede', 'carrera', 'asignatura', 'docente');

        if ($request->has('sede_id')) {
            $query->where('sede_id', $request->sede_id);
        }

        if ($request->has('carrera_id')) {
            $query->where('carrera_id', $request->carrera_id);
        }

        if ($request->has('fecha')) {
            $query->whereDate('fecha_examen', $request->fecha);
        }

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sede_id' => 'nullable|exists:sedes,id',
            'carrera_id' => 'nullable|exists:carreras,id',
            'asignatura_id' => 'nullable|exists:asignaturas,id',
            'docente_id' => 'nullable|exists:docentes,id',
            'sede_nombre' => 'nullable|string',
            'carrera_nombre' => 'nullable|string',
            'asignatura_nombre' => 'nullable|string',
            'docente_nombre' => 'nullable|string',
            'parcial' => 'required|string',
            'gestion' => 'nullable|string',
            'grupo' => 'nullable|string',
            'hora' => 'nullable|string',
            'cant_variantes' => 'required|integer',
            'fecha_examen' => 'required|date',
            'motivo' => 'required|string',
            'patron_respuestas_json' => 'nullable|array',
            'configuracion_json' => 'nullable|array'
        ]);

        $validated['user_id'] = auth()->id();
        $validated['estado'] = 'GENERADO';

        $registro = GeneracionManual::create($validated);

        return response()->json($registro, 201);
    }

    public function updateEstado(Request $request, $id)
    {
        $request->validate([
            'estado' => 'required|in:GENERADO,ENTREGADO,DEVUELTO'
        ]);

        $registro = GeneracionManual::findOrFail($id);
        $registro->estado = $request->estado;
        $registro->save();

        return response()->json($registro);
    }

    public function destroy($id)
    {
        $registro = GeneracionManual::findOrFail($id);
        $registro->delete();

        return response()->json(['success' => true]);
    }

    public function uploadArchivos(Request $request, $id)
    {
        $registro = GeneracionManual::findOrFail($id);

        $request->validate([
            'examen_pdf' => 'nullable|file|mimes:pdf',
            'patron_pdf' => 'nullable|file|mimes:pdf',
            'patrones_xlsx.*' => 'nullable|file|mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel'
        ]);

        if ($request->hasFile('examen_pdf')) {
            $path = $request->file('examen_pdf')->storeAs('public/examenes', $request->file('examen_pdf')->getClientOriginalName());
            $registro->archivo_examen = basename($path);
        }

        if ($request->hasFile('patron_pdf')) {
            $path = $request->file('patron_pdf')->storeAs('public/patrones', $request->file('patron_pdf')->getClientOriginalName());
            $registro->archivo_patron_pdf = basename($path);
        }

        if ($request->hasFile('patrones_xlsx')) {
            $xlsxPaths = $registro->archivos_patron_xlsx ?? [];
            foreach ($request->file('patrones_xlsx') as $file) {
                $path = $file->storeAs('public/patrones', $file->getClientOriginalName());
                $xlsxPaths[] = basename($path);
            }
            $registro->archivos_patron_xlsx = $xlsxPaths;
        }

        $registro->save();

        return response()->json(['success' => true, 'registro' => $registro]);
    }

    public function downloadExamen($id)
    {
        $registro = GeneracionManual::findOrFail($id);
        if (!$registro->archivo_examen) {
            return response()->json(['message' => 'No hay archivo generado'], 404);
        }

        $path = 'public/examenes/' . $registro->archivo_examen;
        if (!Storage::exists($path)) {
            return response()->json(['message' => 'El archivo físico no se encuentra'], 404);
        }

        return Storage::download($path);
    }

    public function downloadPatronPdf($id)
    {
        $registro = GeneracionManual::findOrFail($id);
        if (!$registro->archivo_patron_pdf) {
            return response()->json(['message' => 'No hay patrón PDF generado'], 404);
        }

        $path = 'public/patrones/' . $registro->archivo_patron_pdf;
        if (!Storage::exists($path)) {
            return response()->json(['message' => 'El archivo físico no se encuentra'], 404);
        }

        return Storage::download($path);
    }

    public function downloadPatronXlsx($id)
    {
        $registro = GeneracionManual::findOrFail($id);
        if (empty($registro->archivos_patron_xlsx)) {
            return response()->json(['message' => 'No hay patrón Excel generado'], 404);
        }

        $filename = $registro->archivos_patron_xlsx[0];
        $path = 'public/patrones/' . $filename;
        if (!Storage::exists($path)) {
            return response()->json(['message' => 'El archivo físico no se encuentra'], 404);
        }

        return Storage::download($path);
    }
}
