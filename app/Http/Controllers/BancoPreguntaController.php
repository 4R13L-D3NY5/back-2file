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
            
            if (count($rows) < 2) {
                throw new \Exception("El archivo no tiene filas de datos útiles.");
            }

            $headers = array_map(function($h) {
                return mb_strtoupper(trim((string)$h));
            }, $rows[0]);
            
            $cols = [];
            foreach ($headers as $index => $header) {
                if (empty($header)) continue;
                if (str_contains($header, 'TIPO')) $cols['TIPO'] = $index;
                else if (str_contains($header, 'GRUPO')) $cols['GRUPO'] = $index;
                else if (str_contains($header, 'ENUNCIADO')) $cols['ENUNCIADO'] = $index;
                else if ($header === 'A' || str_contains($header, 'OPCION A') || str_contains($header, 'OPCIÓN A') || str_contains($header, 'OPCION_A') || str_contains($header, 'OPCIÓN_A')) $cols['A'] = $index;
                else if ($header === 'B' || str_contains($header, 'OPCION B') || str_contains($header, 'OPCIÓN B') || str_contains($header, 'OPCION_B') || str_contains($header, 'OPCIÓN_B')) $cols['B'] = $index;
                else if ($header === 'C' || str_contains($header, 'OPCION C') || str_contains($header, 'OPCIÓN C') || str_contains($header, 'OPCION_C') || str_contains($header, 'OPCIÓN_C')) $cols['C'] = $index;
                else if ($header === 'D' || str_contains($header, 'OPCION D') || str_contains($header, 'OPCIÓN D') || str_contains($header, 'OPCION_D') || str_contains($header, 'OPCIÓN_D')) $cols['D'] = $index;
                else if ($header === 'E' || str_contains($header, 'OPCION E') || str_contains($header, 'OPCIÓN E') || str_contains($header, 'OPCION_E') || str_contains($header, 'OPCIÓN_E')) $cols['E'] = $index;
                else if (str_contains($header, 'RESPUESTA')) $cols['RESPUESTA'] = $index;
                else if (str_contains($header, 'DIFICULTAD')) $cols['DIFICULTAD'] = $index;
                else if (str_contains($header, 'PARCIAL')) $cols['PARCIAL'] = $index;
            }

            // Defaults if column isn't found
            $cols['TIPO'] = $cols['TIPO'] ?? 0;
            $cols['ENUNCIADO'] = $cols['ENUNCIADO'] ?? 1;

            $count = 0;
            $tipoMap = [
                'FV' => 'FALSO_VERDADERO',
                'SS' => 'SELECCION_UNICA',
                'SM' => 'SELECCION_MULTIPLE',
                'PR' => 'PROBLEMA',
                'SP' => 'SUBPROBLEMA',
                'EM' => 'EMPAREJAMIENTO'
            ];

            $docenteId = $request->input('docente_id') 
                ?? (\App\Models\Docente::where('user_id', auth()->id())->first()?->id);

            \Log::info("Importación Banco: docente_id detectado: " . ($docenteId ?? 'NULL'));

            foreach ($rows as $index => $row) {
                if ($index === 0) continue; // Skip Header

                $rawTipo = mb_strtoupper(trim((string)($row[$cols['TIPO']] ?? '')));
                $rawEnunciado = trim((string)($row[$cols['ENUNCIADO']] ?? ''));
                
                if (empty($rawTipo) && empty($rawEnunciado)) continue; // Skip Empty Rows

                $tipo = $tipoMap[$rawTipo] ?? $rawTipo;
                if (empty($tipo)) $tipo = 'SELECCION_UNICA'; // default
                
                $enunciado = nl2br($rawEnunciado); // Soporte saltos de línea (html)
                $grupo = isset($cols['GRUPO']) ? trim((string)($row[$cols['GRUPO']] ?? '')) : null;

                // Construir Opciones
                $opciones = [];
                $letters = ['A', 'B', 'C', 'D', 'E'];
                foreach ($letters as $letter) {
                    if (isset($cols[$letter])) {
                        $val = trim((string)($row[$cols[$letter]] ?? ''));
                        if ($val !== '') {
                            $opciones[] = ['id' => $letter, 'text' => nl2br($val)];
                        }
                    }
                }

                // Construir Respuesta
                $rawResp = isset($cols['RESPUESTA']) ? mb_strtoupper(trim((string)($row[$cols['RESPUESTA']] ?? ''))) : '';
                if (str_contains($rawResp, ',')) {
                    $respuesta = array_map('trim', explode(',', $rawResp));
                } else {
                    $respuesta = $rawResp;
                }

                $dificultad = isset($cols['DIFICULTAD']) ? (trim((string)($row[$cols['DIFICULTAD']] ?? '')) ?: 'MEDIA') : 'MEDIA';
                $parcial = isset($cols['PARCIAL']) ? trim((string)($row[$cols['PARCIAL']] ?? '')) : null;

                BancoPregunta::create([
                    'asignatura_id' => $asignaturaId,
                    'docente_id' => $docenteId,
                    'logro_esperado_id' => $logroId,
                    'tipo' => $tipo,
                    'grupo' => $grupo,
                    'enunciado' => $enunciado,
                    'opciones' => empty($opciones) ? [] : $opciones,
                    'respuesta_correcta' => empty($respuesta) ? [] : $respuesta,
                    'dificultad' => mb_strtoupper($dificultad),
                    'parcial' => $parcial,
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
