<?php

namespace App\Http\Controllers;

use App\Models\BancoPregunta;
use App\Models\LogroEsperado;
use Illuminate\Http\Request;
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
        
        // Filtrar por docente actual (a menos que se pida todas)
        if (!$request->boolean('all_docentes')) {
            $userId = auth()->id();
            if ($userId) {
                $questions->where('created_by', $userId);
            }
        }
        
        return response()->json($questions->get());
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


        // Inyectar usuario actual
        $validated['created_by'] = $request->user()?->id;

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

    /**
     * Importar preguntas desde Excel
     * Formato esperado: ENUNCIADO | TIPO | A | B | C | D | E | DIFICULTAD | PESO | RESPUESTA
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'logro_esperado_id' => 'required|exists:logros_esperados,id'
        ]);

        $file = $request->file('file');
        $logroId = $request->input('logro_esperado_id');

        try {
            $spreadsheet = IOFactory::load($file->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            // Asumimos fila 1 HEADERS
            // ENUNCIADO(0) | TIPO(1) | A(2) | B(3) | C(4) | D(5) | E(6) | DIFICULTAD(7) | PESO(8) | RESPUESTA(9)

            $count = 0;
            foreach ($rows as $index => $row) {
                if ($index === 0) continue; // Skip Header
                if (empty($row[0])) continue; // Skip Empty Rows

                $tipo = strtoupper(trim($row[1] ?? 'SELECCION_UNICA')); // Default
                
                // Construir Opciones
                $opciones = [];
                // Columnas de opciones (A-E -> index 2-6)
                $letters = ['A', 'B', 'C', 'D', 'E'];
                foreach ($letters as $k => $letter) {
                    $val = $row[2 + $k] ?? null;
                    if ($val) {
                        $opciones[] = ['id' => $letter, 'text' => $val];
                    }
                }

                // Construir Respuesta
                $rawResp = $row[9] ?? '';
                // Si es Multiple (Ej: "A,B"), convertimos a array
                if (str_contains($rawResp, ',')) {
                    $respuesta = array_map('trim', explode(',', $rawResp));
                } else {
                    $respuesta = trim($rawResp);
                }

                BancoPregunta::create([
                    'enunciado' => $row[0],
                    'tipo' => $tipo,
                    'opciones' => $opciones,
                    'respuesta_correcta' => $respuesta,
                    'dificultad' => $row[7] ?? 'MEDIA',
                    'peso' => (int)($row[8] ?? 1),
                    'logro_esperado_id' => $logroId,
                    'created_by' => auth()->id()
                ]);
                $count++;
            }

            return response()->json(['message' => "Importadas {$count} preguntas correctamente."]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al leer archivo: ' . $e->getMessage()], 500);
        }
    }
}
