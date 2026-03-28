<?php

namespace App\Http\Controllers;

use App\Models\BancoPregunta;
use App\Models\LogroEsperado;
use App\Models\BancoPreguntaConfiguracion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

        if ($request->has('docente_id')) {
            $questions->where('docente_id', $request->docente_id);
        }

        if ($request->has('grupoTeorico')) {
            $questions->where('grupoTeorico', $request->grupoTeorico);
        }

        if ($request->has('parcial')) {
            $questions->where('parcial', $request->parcial);
        }
        
        // Debug Log
        \Illuminate\Support\Facades\Log::info("BancoPregunta Index Request", [
            'asignatura_id' => $request->asignatura_id,
            'docente_id' => $request->docente_id,
            'user_id' => auth()->id(),
            'all_docentes' => $request->boolean('all_docentes')
        ]);

        // Filtrar por docente actual (a menos que se pida todas o sea por asignatura/docente específico)
        if (!$request->boolean('all_docentes') && !$request->has('asignatura_id') && !$request->has('docente_id')) {
            $userId = auth()->id();
            if ($userId) {
                $questions->where('created_by', $userId);
            }
        }
        
        $results = $questions->get();
        $count = $results->count();
        $stats = [
            'facil' => $results->filter(fn($p) => $p->dificultad == 'FACIL' || $p->dificultad == '1')->count(),
            'medio' => $results->filter(fn($p) => $p->dificultad == 'MEDIA' || $p->dificultad == 'MEDIO' || $p->dificultad == '2')->count(),
            'dificil' => $results->filter(fn($p) => $p->dificultad == 'DIFICIL' || $p->dificultad == '3')->count(),
        ];

        return response()->json([
            'total' => $count,
            'preguntas' => $results,
            'stats' => $stats
        ]);
    }

    /**
     * Obtener estadísticas de conteo de preguntas por dificultad.
     */
    public function getStats(Request $request)
    {
        $request->validate([
            'asignatura_id' => 'required',
            'docente_id' => 'nullable',
            'sede_id' => 'nullable',
            'parcial' => 'nullable',
            'grupo' => 'nullable'
        ]);

        $query = BancoPregunta::where('asignatura_id', $request->asignatura_id);

        if ($request->has('docente_id')) {
            $query->where('docente_id', $request->docente_id);
        }

        if ($request->has('sede_id')) {
            $query->where('sede_id', $request->sede_id);
        }

        if ($request->has('parcial')) {
            $query->where('parcial', $this->normalizarTipoExamen($request->parcial));
        }

        if ($request->has('grupo')) {
            $grupo = $request->grupo;
            $query->where(function($q) use ($grupo) {
                $q->where('grupoTeorico', $grupo)
                  ->orWhere('grupoTeorico', 'LIKE', '%' . $grupo . '%')
                  ->orWhere('grupo', $grupo)
                  ->orWhere('grupo', 'LIKE', '%' . $grupo . '%');
            });
        }

        $stats = $query->selectRaw("
            SUM(CASE WHEN dificultad = 'FACIL' OR dificultad = '1' THEN 1 ELSE 0 END) as facil,
            SUM(CASE WHEN dificultad = 'MEDIA' OR dificultad = 'MEDIO' OR dificultad = '2' THEN 1 ELSE 0 END) as medio,
            SUM(CASE WHEN dificultad = 'DIFICIL' OR dificultad = '3' THEN 1 ELSE 0 END) as dificil,
            COUNT(*) as total
        ")->first();

        // Conteo general para la asignatura y docente (sin parcial/grupo)
        $totalAsignatura = BancoPregunta::where('asignatura_id', $request->asignatura_id)
            ->where('docente_id', $request->docente_id)
            ->count();

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'total_asignatura' => $totalAsignatura
        ]);
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
    
    public function destroy($id)
    {
        $pregunta = BancoPregunta::findOrFail($id);
        if ($pregunta->imagen) {
            Storage::disk('public')->delete('preguntas/' . $pregunta->imagen);
        }
        $pregunta->delete();
        return response()->json(null, 204);
    }

    /**
     * Actualizar una pregunta existente (con soporte para imagen).
     */
    public function update(Request $request, $id)
    {
        $pregunta = BancoPregunta::findOrFail($id);

        $validated = $request->validate([
            'enunciado' => 'required|string',
            'tipo' => 'required|in:SELECCION_UNICA,SELECCION_MULTIPLE,FALSO_VERDADERO,PR,EM,SP',
            'opciones' => 'nullable',
            'respuesta_correcta' => 'required',
            'dificultad' => 'nullable',
            'parcial' => 'nullable|string',
            'grupo' => 'nullable|string',
            'grupoTeorico' => 'nullable|string',
            'image_file' => 'nullable|image|max:5120'
        ]);

        // Procesar opciones si vienen como string (FormData puede enviarlas así)
        if (isset($validated['opciones']) && is_string($validated['opciones'])) {
            $validated['opciones'] = json_decode($validated['opciones'], true);
        }
        // Procesar respuesta_correcta si viene como string
        if (isset($validated['respuesta_correcta']) && is_string($validated['respuesta_correcta'])) {
             // Si parece un array JSON (e.g. ["A","B"]), decodificar
             if (str_starts_with($validated['respuesta_correcta'], '[')) {
                $validated['respuesta_correcta'] = json_decode($validated['respuesta_correcta'], true);
             }
        }

        if ($request->hasFile('image_file')) {
            if ($pregunta->imagen) {
                Storage::disk('public')->delete('preguntas/' . $pregunta->imagen);
            }
            $file = $request->file('image_file');
            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->storeAs('preguntas', $filename, 'public');
            $validated['imagen'] = $filename;
        }

        $pregunta->update($validated);
        return response()->json($pregunta);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'asignatura_id' => 'required|exists:asignaturas,id',
            'logro_esperado_id' => 'nullable|exists:logros_esperados,id',
            'sede_id' => 'nullable|exists:sedes,id',
            'grupo' => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        $asignaturaId = $request->input('asignatura_id');
        $logroId = $request->input('logro_esperado_id');
        $sedeId = $request->input('sede_id');
        $grupoTeorico = $request->input('grupoTeorico');
        $conCartilla = $request->boolean('con_cartilla', true);

        try {
            $modo = $request->input('modo', 'agregar');
            $docenteId = $request->input('docente_id') 
                ?? (\App\Models\Docente::where('user_id', auth()->id())->first()?->id);

            if ($modo === 'reemplazar') {
                $queryDelete = BancoPregunta::where('asignatura_id', $asignaturaId)
                    ->where('docente_id', $docenteId);
                
                if ($grupoTeorico) {
                    $queryDelete->where('grupoTeorico', $grupoTeorico);
                }
                
                if ($request->has('parcial') && $request->parcial) {
                    $queryDelete->where('parcial', $this->normalizarTipoExamen($request->parcial));
                }

                Log::info("Vaciando banco de preguntas filtrado", [
                    'asignatura' => $asignaturaId,
                    'docente' => $docenteId,
                    'grupo' => $grupoTeorico,
                    'parcial' => $request->parcial
                ]);

                $queryDelete->delete();
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

                // Validaciones por Tipo
                // 1. Respuesta y Dificultad Obligatorias (Excepto PROBLEMA/EMPAREJAMIENTO)
                if ($tipo !== 'PROBLEMA' && $tipo !== 'EMPAREJAMIENTO') {
                    if (empty($respuesta)) {
                        throw new \Exception("Fila " . ($index + 1) . ": tipo \"$tipo\" requiere respuesta.");
                    }
                    $rawDif = isset($cols['DIFICULTAD']) ? trim((string)($row[$cols['DIFICULTAD']] ?? '')) : '';
                    if ($rawDif === '') {
                        throw new \Exception("Fila " . ($index + 1) . ": tipo \"$tipo\" requiere nivel de dificultad (1, 2 o 3).");
                    }
                } else {
                    // PROBLEMA y EMPAREJAMIENTO deben estar vacíos
                    if (!empty($respuesta)) {
                        throw new \Exception("Fila " . ($index + 1) . ": tipo \"$tipo\" NO debe tener respuesta (debe estar vacía).");
                    }
                    $rawDif = isset($cols['DIFICULTAD']) ? trim((string)($row[$cols['DIFICULTAD']] ?? '')) : '';
                    if ($rawDif !== '') {
                        throw new \Exception("Fila " . ($index + 1) . ": tipo \"$tipo\" NO debe tener dificultad (debe estar vacía).");
                    }
                }

                // 2. Validaciones para SM
                if ($tipo === 'SELECCION_MULTIPLE') {
                    // 1. Validar que tenga exactamente 2 respuestas
                    $respLetters = is_array($respuesta) ? implode('', $respuesta) : $respuesta;
                    $lettersOnly = preg_replace('/[^A-E]/', '', $respLetters);
                    if (strlen($lettersOnly) !== 2) {
                        throw new \Exception("Fila " . ($index + 1) . ": Selección Múltiple (SM) DEBE tener exactamente 2 respuestas correctas (ej: A,B).");
                    }
                    // 2. Validar que tenga las 5 opciones A-E rellenadas
                    if (count($opciones) < 5) {
                        throw new \Exception("Fila " . ($index + 1) . ": Selección Múltiple (SM) DEBE tener las 5 opciones (A, B, C, D, E) rellenadas.");
                    }
                }

                $dificultad = isset($cols['DIFICULTAD']) ? trim((string)($row[$cols['DIFICULTAD']] ?? '')) : '';
                
                // PR y EM no llevan dificultad por regla de negocio
                if ($tipo === 'PROBLEMA' || $tipo === 'EMPAREJAMIENTO') {
                    $dificultad = null;
                } else {
                    $dificultad = $dificultad ?: 'MEDIA';
                }

                $parcial = isset($cols['PARCIAL']) ? trim((string)($row[$cols['PARCIAL']] ?? '')) : null;
                if ($parcial) {
                    $parcial = $this->normalizarTipoExamen($parcial);
                }

                BancoPregunta::create([
                    'asignatura_id' => $asignaturaId,
                    'docente_id' => $docenteId,
                    'logro_esperado_id' => $logroId,
                    'sede_id' => $sedeId,
                    'tipo' => $tipo,
                    'grupo' => $grupo,
                    'grupoTeorico' => $grupoTeorico,
                    'enunciado' => $enunciado,
                    'opciones' => empty($opciones) ? [] : $opciones,
                    'respuesta_correcta' => empty($respuesta) ? [] : $respuesta,
                    'dificultad' => mb_strtoupper($dificultad),
                    'parcial' => $parcial,
                    'peso' => 1,
                    'con_cartilla' => $conCartilla,
                    'created_by' => auth()->id()
                ]);
                $count++;
            }

            $this->updateOrCreateConfig($asignaturaId, $grupoTeorico, $request->parcial, $conCartilla);

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

    /**
     * Guardar configuración de cartilla (independiente de la importación)
     */
    public function saveConfig(Request $request)
    {
        $request->validate([
            'asignatura_id' => 'required|exists:asignaturas,id',
            'grupo_teorico' => 'required|string',
            'parcial' => 'required|string',
            'con_cartilla' => 'required|boolean'
        ]);

        $asignaturaId = $request->asignatura_id;
        $grupoTeorico = $request->grupo_teorico;
        $parcial = $request->parcial;
        $conCartilla = $request->con_cartilla;

        // Si es Sin Cartilla (false), procedemos a limpiar el banco de preguntas para este grupo/parcial
        if (!$conCartilla) {
            $docenteId = \App\Models\Docente::where('user_id', auth()->id())->first()?->id;
            
            $queryDelete = BancoPregunta::where('asignatura_id', $asignaturaId)
                ->where('grupoTeorico', $grupoTeorico)
                ->where('parcial', $this->normalizarTipoExamen($parcial));
            
            if ($docenteId) {
                $queryDelete->where('docente_id', $docenteId);
            }

            $deletedCount = $queryDelete->delete();
            
            Log::info("Preferencia Sin Cartilla: Se eliminaron {$deletedCount} preguntas", [
                'asignatura' => $asignaturaId,
                'grupo' => $grupoTeorico,
                'parcial' => $parcial
            ]);
        }

        $config = $this->updateOrCreateConfig(
            $asignaturaId,
            $grupoTeorico,
            $parcial,
            $conCartilla
        );

        return response()->json([
            'success' => true,
            'message' => 'Configuración guardada correctamente',
            'configuracion' => $config
        ]);
    }

    private function updateOrCreateConfig($asignaturaId, $grupoTeorico, $parcial, $conCartilla)
    {
        if (!$parcial) return null;
        
        $parcialNormalizado = $this->normalizarTipoExamen($parcial);

        return BancoPreguntaConfiguracion::updateOrCreate(
            [
                'asignatura_id' => $asignaturaId,
                'grupo_teorico' => $grupoTeorico,
                'parcial' => $parcialNormalizado,
            ],
            [
                'con_cartilla' => $conCartilla,
                'updated_by' => auth()->id()
            ]
        );
    }

    private function normalizarTipoExamen($tipo)
    {
        $tipo = strtolower(trim((string)$tipo));

        $mapping = [
            '1er parcial' => '1er Parcial',
            'primer parcial' => '1er Parcial',
            '1 parcial' => '1er Parcial',
            '1° parcial' => '1er Parcial',
            '1p' => '1er Parcial',
            '2do parcial' => '2do Parcial',
            'segundo parcial' => '2do Parcial',
            '2 parcial' => '2do Parcial',
            '2° parcial' => '2do Parcial',
            '2p' => '2do Parcial',
            'final' => 'Final',
            'ef' => 'Final',
            'examen final' => 'Final',
            '2da instancia' => '2da Instancia',
            'segunda instancia' => '2da Instancia',
            'segunda' => '2da Instancia',
            '2i' => '2da Instancia',
        ];

        return $mapping[$tipo] ?? $tipo;
    }
    public function showImage($filename)
    {
        $path = storage_path('app/public/preguntas/' . $filename);
        if (!file_exists($path)) {
            abort(404);
        }
        return response()->file($path);
    }

    public function getLogo()
    {
        $path = public_path('descargas/unitepc-logo.png');
        if (!file_exists($path)) {
            abort(404);
        }
        return response()->file($path);
    }
}
