<?php

namespace App\Http\Controllers;

use App\Models\BancoPregunta;
use App\Models\LogroEsperado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BancoPreguntaController extends Controller
{
    /**
     * Listar preguntas de un logro específico.
     * Por defecto solo muestra las del docente actual (Personal).
     * Si all_docentes=true, muestra todas (para generación de exámenes).
     */
    public function index(Request $request)
    {
        $questions = BancoPregunta::query();
        
        if ($request->has('logro_id')) {
            $questions->where('logro_esperado_id', $request->logro_id);
        }

        if ($request->has('asignatura_id')) {
            $questions->where('asignatura_id', $request->asignatura_id);
        }
        
        // Debug Log
        \Log::info("BancoPregunta Index Request", [
            'asignatura_id' => $request->asignatura_id,
            'user_id' => auth()->id(),
            'all_docentes' => $request->boolean('all_docentes')
        ]);

        // Filtrar por docente actual (a menos que se pida todas o sea por asignatura)
        if (!$request->boolean('all_docentes') && !$request->has('asignatura_id')) {
            $userId = auth()->id();
            if ($userId) {
                $questions->where('created_by', $userId);
            }
        }
        
        $results = $questions->get();
        \Log::info("BancoPregunta Index Results", ['count' => $results->count()]);

        return response()->json($results);
    }

    /**
     * Crear una nueva pregunta manualmente.
     */
    public function store(Request $request)
    {
        // Validar tipos
        $validated = $request->validate([
            'enunciado' => 'required',
            'tipo' => 'required|in:SELECCION_UNICA,SELECCION_MULTIPLE,FALSO_VERDADERO',
            'opciones' => 'nullable|array',
            'respuesta_correcta' => 'required',
            'logro_esperado_id' => 'required|exists:logros_esperados,id'
        ]);


        // Inyectar usuario actual y docente_id
        $validated['created_by'] = $request->user()?->id;
        $validated['docente_id'] = \App\Models\Docente::where('user_id', $validated['created_by'])->first()?->id;

        $pregunta = BancoPregunta::create($validated);
        return response()->json($pregunta, 201);
    }
    
    /**
     * Eliminar pregunta.
     */
    public function destroy($id)
    {
        BancoPregunta::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'asignatura_id' => 'required|exists:asignaturas,id',
            'logro_esperado_id' => 'nullable|exists:logros_esperados,id'
        ]);

        $file = $request->file('file');
        $asignaturaId = $request->input('asignatura_id');
        $logroId = $request->input('logro_esperado_id');

        try {
            $modo = $request->input('modo', 'agregar');

            if ($modo === 'reemplazar') {
                \Log::info("Vaciando banco de preguntas para asignatura: {$asignaturaId}");
                BancoPregunta::where('asignatura_id', $asignaturaId)->delete();
            }

            $spreadsheet = IOFactory::load($file->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            // FORMATO V3:
            // TIPO(0) | ENUNCIADO(1) | A(2) | B(3) | C(4) | D(5) | E(6) | RESPUESTA(7) | DIFICULTAD(8) | PARCIAL(9)

            $count = 0;
            $tipoMap = [
                'FV' => 'FALSO_VERDADERO',
                'SS' => 'SELECCION_UNICA',
                'SM' => 'SELECCION_MULTIPLE'
            ];

            $docenteId = $request->input('docente_id') 
                ?? (\App\Models\Docente::where('user_id', auth()->id())->first()?->id);

            \Log::info("Importación Banco: docente_id detectado: " . ($docenteId ?? 'NULL'));

            foreach ($rows as $index => $row) {
                if ($index === 0) continue; // Skip Header
                if (empty($row[0]) || empty($row[1])) continue; // Skip Empty Rows

                $tipoBasico = strtoupper(trim($row[0]));
                $tipo = $tipoMap[$tipoBasico] ?? 'SELECCION_UNICA';
                
                // Construir Opciones
                $opciones = [];
                $letters = ['A', 'B', 'C', 'D', 'E'];
                foreach ($letters as $k => $letter) {
                    $val = $row[2 + $k] ?? null;
                    if ($val !== null && $val !== '') {
                        $opciones[] = ['id' => $letter, 'text' => (string)$val];
                    }
                }

                // Construir Respuesta
                $rawResp = strtoupper(trim((string)($row[7] ?? '')));
                if (str_contains($rawResp, ',')) {
                    $respuesta = array_map('trim', explode(',', $rawResp));
                } else {
                    $respuesta = $rawResp;
                }

                BancoPregunta::create([
                    'asignatura_id' => $asignaturaId,
                    'docente_id' => $docenteId,
                    'logro_esperado_id' => $logroId,
                    'tipo' => $tipo,
                    'enunciado' => $row[1],
                    'opciones' => $opciones,
                    'respuesta_correcta' => $respuesta,
                    'dificultad' => $row[8] ?? 'MEDIA',
                    'parcial' => $row[9] ?? null,
                    'peso' => 1,
                    'created_by' => auth()->id()
                ]);
                $count++;
            }

            return response()->json([
                'success' => true,
                'message' => "Se han importado {$count} preguntas correctamente.",
                'total' => $count
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Error al procesar el archivo Excel: ' . $e->getMessage()
            ], 500);
        }
    }
}
