<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateManualExamPackageJob;
use App\Models\GeneracionManual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GeneracionManualController extends Controller
{
    private function firstExistingStoragePath(array $paths): ?array
    {
        foreach ($paths as $path) {
            if (Storage::exists($path)) {
                return ['disk' => null, 'path' => $path];
            }

            if (str_starts_with($path, 'public/')) {
                $publicPath = substr($path, strlen('public/'));
                if (Storage::disk('public')->exists($publicPath)) {
                    return ['disk' => 'public', 'path' => $publicPath];
                }
            }
        }

        return null;
    }

    private function downloadStoragePath(array $file)
    {
        return $file['disk']
            ? Storage::disk($file['disk'])->download($file['path'])
            : Storage::download($file['path']);
    }

    public function index(Request $request)
    {
        $query = GeneracionManual::with('user', 'sede', 'carrera', 'asignatura', 'docente');

        if ($request->has('sede_id')) {
            $query->where('sede_id', $request->sede_id);
        }

        if ($request->has('carrera_id')) {
            $query->where('carrera_id', $request->carrera_id);
        }

        if ($request->has('asignatura_id')) {
            $query->where('asignatura_id', $request->asignatura_id);
        }

        if ($request->has('docente_id')) {
            $query->where('docente_id', $request->docente_id);
        }

        if ($request->filled('fecha_inicio') || $request->filled('fecha_fin')) {
            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            if ($fechaInicio && $fechaFin && $fechaInicio > $fechaFin) {
                [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];
            }

            if ($fechaInicio) {
                $query->whereDate('fecha_examen', '>=', $fechaInicio);
            }

            if ($fechaFin) {
                $query->whereDate('fecha_examen', '<=', $fechaFin);
            }
        } elseif ($request->filled('fecha')) {
            $query->whereDate('fecha_examen', $request->fecha);
        }

        $this->aplicarAlcanceUsuario($query, $request);

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    private function aplicarAlcanceUsuario($query, Request $request): void
    {
        $user = $request->user();

        if (! $user) {
            $query->whereRaw('1 = 0');
            return;
        }

        $user->loadMissing(['rol', 'director.carreras', 'docente', 'campus', 'campusAsignados.sede']);
        $rol = $this->normalizarRol($user->rol?->codigo);

        if (in_array($rol, ['SUPER_ADMIN', 'ADMIN', 'RESPONSABLE_EVALUACIONES', 'VICERRECTOR_NACIONAL'], true)) {
            return;
        }

        if ($rol === 'DIRECTOR_CARRERA') {
            $sedeId = $user->director?->sede_id ?? $user->docente?->sede_id ?? $user->sede_id;
            $carreraIds = collect([
                $user->director?->carrera_id,
                $user->carrera_id ?? null,
            ])->merge($user->director?->carreras?->pluck('id') ?? collect())
                ->filter()
                ->unique()
                ->values();

            if (! $sedeId || $carreraIds->isEmpty()) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->where('sede_id', $sedeId)->whereIn('carrera_id', $carreraIds->all());
            return;
        }

        if (in_array($rol, ['VICERRECTOR_SEDE', 'DIRECCION_ACADEMICA'], true)) {
            $sedeId = $user->director?->sede_id ?? $user->docente?->sede_id ?? $user->sede_id;

            $sedeId ? $query->where('sede_id', $sedeId) : $query->whereRaw('1 = 0');
            return;
        }

        if ($rol === 'EVALUACIONES') {
            $campusIds = collect([$user->campus_id])
                ->merge($user->campusAsignados->pluck('id'))
                ->filter()
                ->unique()
                ->values();

            $sedeIds = collect([$user->sede_id])
                ->merge($user->campusAsignados->pluck('sede_id'))
                ->merge($user->campusAsignados->pluck('sede.id'))
                ->merge($user->campus?->sede_id ? [$user->campus->sede_id] : [])
                ->filter()
                ->unique()
                ->values();

            $carreraIds = $campusIds->isNotEmpty()
                ? DB::table('campus_carrera')
                    ->whereIn('campus_id', $campusIds->all())
                    ->pluck('carrera_id')
                    ->unique()
                    ->values()
                : collect();

            if ($sedeIds->isEmpty() && $carreraIds->isEmpty()) {
                $query->whereRaw('1 = 0');
                return;
            }

            if ($sedeIds->isNotEmpty()) {
                $query->whereIn('sede_id', $sedeIds->all());
            }

            if ($carreraIds->isNotEmpty()) {
                $query->whereIn('carrera_id', $carreraIds->all());
            }
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function normalizarRol(?string $rol): string
    {
        return [
            'VICERRECTORADO_NACIONAL' => 'VICERRECTOR_NACIONAL',
            'VICERRECTORADO' => 'VICERRECTOR_SEDE',
            'DIRECCIÃ“N ACADÃ‰MICA' => 'DIRECCION_ACADEMICA',
            'DIRECCIÓN ACADÉMICA' => 'DIRECCION_ACADEMICA',
        ][trim((string) $rol)] ?? trim((string) $rol);
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
        $validated['estado'] = 'PROGRAMADO';

        $registro = GeneracionManual::create($validated);

        return response()->json($registro, 201);
    }

    public function generatePackage(Request $request, $id)
    {
        $registro = GeneracionManual::findOrFail($id);

        $validated = $request->validate([
            'questions' => 'nullable|array|min:1',
            'config' => 'nullable|array',
        ]);

        $config = array_merge(
            $registro->configuracion_json ?? [],
            $validated['config'] ?? []
        );

        if (!empty($validated['questions'])) {
            $config['questions'] = $validated['questions'];
        }

        if (empty($config['questions'])) {
            return response()->json([
                'message' => 'No hay preguntas para enviar a la cola de generacion manual.'
            ], 422);
        }

        $config['job_status'] = 'queued';
        $config['job_error'] = null;
        $config['timestamps'] = array_merge($config['timestamps'] ?? [], [
            'generacion_solicitada' => now()->toISOString(),
        ]);

        $registro->update([
            'configuracion_json' => $config,
            'archivo_examen' => null,
            'archivo_patron_pdf' => null,
            'archivos_patron_xlsx' => [],
            'patron_respuestas_json' => [],
        ]);

        GenerateManualExamPackageJob::dispatch($registro->id, auth()->id());

        return response()->json([
            'success' => true,
            'message' => 'La generacion manual fue enviada a la cola.',
            'job_status' => 'queued',
            'registro' => $registro->fresh(),
        ], 202);
    }

    public function updateEstado(Request $request, $id)
    {
        $request->validate([
            'estado' => 'required|in:PROGRAMADO,GENERADO,IMPRESO,ENTREGADO,DEVUELTO,REVISADO,SUBIDO'
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

        $path = $this->firstExistingStoragePath([
            'public/examenes/' . $registro->archivo_examen,
            'public/examenes_queue/' . $registro->archivo_examen,
        ]);
        if (!$path) {
            return response()->json(['message' => 'El archivo físico no se encuentra'], 404);
        }

        return $this->downloadStoragePath($path);
    }

    public function downloadPatronPdf($id)
    {
        $registro = GeneracionManual::findOrFail($id);
        if (!$registro->archivo_patron_pdf) {
            return response()->json(['message' => 'No hay patrón PDF generado'], 404);
        }

        $path = $this->firstExistingStoragePath([
            'public/patrones/' . $registro->archivo_patron_pdf,
            'public/patrones_queue/' . $registro->archivo_patron_pdf,
        ]);
        if (!$path) {
            return response()->json(['message' => 'El archivo físico no se encuentra'], 404);
        }

        return $this->downloadStoragePath($path);
    }

    public function downloadPatronXlsx($id)
    {
        $registro = GeneracionManual::findOrFail($id);
        if (empty($registro->archivos_patron_xlsx)) {
            return response()->json(['message' => 'No hay patrón Excel generado'], 404);
        }

        $filename = $registro->archivos_patron_xlsx[0];
        $path = $this->firstExistingStoragePath([
            'public/patrones/' . $filename,
            'public/patrones_queue/' . $filename,
        ]);
        if (!$path) {
            return response()->json(['message' => 'El archivo físico no se encuentra'], 404);
        }

        return $this->downloadStoragePath($path);
    }
}
