<?php

namespace App\Http\Controllers;

use App\Models\BancoPregunta;
use App\Models\BancoPreguntaConfiguracion;
use App\Models\Docente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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

        if ($request->has('sede_id')) {
            $questions->where('sede_id', $request->sede_id);
        }

        if ($request->has('grupoTeorico')) {
            $grupoTeorico = $request->grupoTeorico;
            $grupoNormalizado = strtoupper(trim((string) $grupoTeorico));
            $grupoNormalizado = str_replace(['G. ', 'GRUPO ', 'G-', 'G'], '', $grupoNormalizado);
            $questions->where(function ($query) use ($grupoTeorico, $grupoNormalizado) {
                $query->where('grupoTeorico', $grupoTeorico)
                    ->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupoTeorico), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                        [$grupoNormalizado]
                    );
            });
        }

        if ($request->has('parcial')) {
            $questions->where('parcial', $request->parcial);
        }

        // Debug Log
        \Illuminate\Support\Facades\Log::info('BancoPregunta Index Request', [
            'asignatura_id' => $request->asignatura_id,
            'docente_id' => $request->docente_id,
            'sede_id' => $request->sede_id,
            'grupoTeorico' => $request->grupoTeorico,
            'parcial' => $request->parcial,
            'user_id' => auth()->id(),
            'all_docentes' => $request->boolean('all_docentes'),
        ]);

        // Filtrar por docente actual (a menos que se pida todas o sea por asignatura/docente específico)
        if (! $request->boolean('all_docentes') && ! $request->has('asignatura_id') && ! $request->has('docente_id')) {
            $userId = auth()->id();
            if ($userId) {
                $questions->where('created_by', $userId);
            }
        }

        $results = $questions->get()->map(function ($pregunta) {
            return $this->sanitizePreguntaForResponse($pregunta);
        });
        $count = $results->count();
        $stats = [
            'facil' => $results->filter(fn ($p) => $p->dificultad == 'FACIL' || $p->dificultad == '1')->count(),
            'medio' => $results->filter(fn ($p) => $p->dificultad == 'MEDIA' || $p->dificultad == 'MEDIO' || $p->dificultad == '2')->count(),
            'dificil' => $results->filter(fn ($p) => $p->dificultad == 'DIFICIL' || $p->dificultad == '3')->count(),
        ];

        return response()->json([
            'total' => $count,
            'preguntas' => $results,
            'stats' => $stats,
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
            'grupo' => 'nullable',
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
            $grupoNormalizado = strtoupper(trim((string) $grupo));
            $grupoNormalizado = str_replace(['G. ', 'GRUPO ', 'G-', 'G'], '', $grupoNormalizado);
            $query->where(function ($q) use ($grupo, $grupoNormalizado) {
                $q->where('grupoTeorico', $grupo)
                    ->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupoTeorico), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                        [$grupoNormalizado]
                    )
                    ->orWhere(function ($legacy) use ($grupo, $grupoNormalizado) {
                        $legacy->where(function ($emptyGrupoTeorico) {
                            $emptyGrupoTeorico->whereNull('grupoTeorico')
                                ->orWhere('grupoTeorico', '');
                        })->where(function ($legacyGrupo) use ($grupo, $grupoNormalizado) {
                            $legacyGrupo->where('grupo', $grupo)
                                ->orWhereRaw(
                                    "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(grupo), 'G. ', ''), 'GRUPO ', ''), 'G-', ''), 'G', '') = ?",
                                    [$grupoNormalizado]
                                );
                        });
                    });
            });
        }

        $preguntas = (clone $query)->get(['tipo']);
        $stats = $query->selectRaw("
            SUM(CASE WHEN dificultad = 'FACIL' OR dificultad = '1' THEN 1 ELSE 0 END) as facil,
            SUM(CASE WHEN dificultad = 'MEDIA' OR dificultad = 'MEDIO' OR dificultad = '2' THEN 1 ELSE 0 END) as medio,
            SUM(CASE WHEN dificultad = 'DIFICIL' OR dificultad = '3' THEN 1 ELSE 0 END) as dificil,
            COUNT(*) as total
        ")->first();
        $porTipo = $preguntas
            ->map(fn ($pregunta) => $this->normalizarTipoPreguntaBanco($pregunta->tipo) ?: 'SIN_TIPO')
            ->filter(fn ($tipo) => ! in_array($tipo, ['EMPAREJAMIENTO', 'PROBLEMA'], true))
            ->countBy()
            ->toArray();
        $porGrupoTipo = $this->contarGruposTipoPregunta($porTipo);
        $statsPayload = [
            'facil' => (int) ($stats->facil ?? 0),
            'medio' => (int) ($stats->medio ?? 0),
            'dificil' => (int) ($stats->dificil ?? 0),
            'total' => (int) ($stats->total ?? 0),
            'por_tipo' => $porTipo,
            'por_grupo_tipo' => $porGrupoTipo,
            'g1' => $porGrupoTipo['g1'],
            'g2' => $porGrupoTipo['g2'],
            'g3' => $porGrupoTipo['g3'],
        ];

        // Conteo general para la asignatura y docente (sin parcial/grupo)
        $totalAsignatura = BancoPregunta::where('asignatura_id', $request->asignatura_id)
            ->where('docente_id', $request->docente_id)
            ->when($request->filled('sede_id'), function ($query) use ($request) {
                $query->where('sede_id', $request->sede_id);
            })
            ->count();

        $configuracion = null;
        if ($request->filled('grupo') && $request->filled('parcial')) {
            $configuracion = BancoPreguntaConfiguracion::query()
                ->where('asignatura_id', $request->asignatura_id)
                ->where('grupo_teorico', $request->grupo)
                ->where('parcial', $this->normalizarTipoExamen($request->parcial))
                ->first();
        }

        return response()->json([
            'success' => true,
            'stats' => $statsPayload,
            'por_tipo' => $porTipo,
            'por_grupo_tipo' => $porGrupoTipo,
            'total_asignatura' => $totalAsignatura,
            'con_cartilla' => $configuracion?->con_cartilla ?? true,
            'configuracion' => $configuracion,
        ]);
    }

    /**
     * Crear una nueva pregunta manualmente.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'enunciado' => 'required|string',
            'tipo' => 'required|string',
            'opciones' => 'nullable',
            'respuesta_correcta' => 'nullable',
            'dificultad' => 'nullable',
            'parcial' => 'nullable|string',
            'grupo' => 'nullable|string',
            'grupoTeorico' => 'nullable|string',
            'image_file' => 'nullable|image|max:5120',
            'logro_esperado_id' => 'nullable|exists:logros_esperados,id',
            'asignatura_id' => 'required|exists:asignaturas,id',
            'docente_id' => 'nullable|exists:docentes,id',
            'sede_id' => 'nullable|exists:sedes,id',
        ]);

        $validated['tipo'] = $this->normalizarTipoPreguntaManual($validated['tipo'] ?? null);

        if (! in_array($validated['tipo'], $this->tiposPreguntaPermitidos(), true)) {
            throw ValidationException::withMessages([
                'tipo' => 'Tipo de pregunta no permitido.',
            ]);
        }

        $this->parsePreguntaPayload($validated);
        $this->sanitizePreguntaPayload($validated);
        $this->normalizePreguntaPayload($validated);

        if (! empty($validated['parcial'])) {
            $validated['parcial'] = $this->normalizarTipoExamen($validated['parcial']);
        }

        if (empty($validated['grupoTeorico']) && ! empty($validated['grupo'])) {
            $validated['grupoTeorico'] = $validated['grupo'];
        }

        $validated['created_by'] = $request->user()?->id;
        $validated['docente_id'] = $request->input('docente_id')
            ?? Docente::where('user_id', $validated['created_by'])->first()?->id;
        $validated['peso'] = $validated['peso'] ?? 1;

        $this->storePreguntaImage($request, $validated);

        $pregunta = BancoPregunta::create($validated);

        return response()->json($this->sanitizePreguntaForResponse($pregunta), 201);
    }

    public function destroy($id)
    {
        $pregunta = BancoPregunta::findOrFail($id);
        if ($pregunta->imagen) {
            Storage::disk('public')->delete('preguntas/'.$pregunta->imagen);
        }
        $pregunta->delete();

        return response()->json(null, 204);
    }

    public function destroyByFiltro(Request $request)
    {
        $validated = $request->validate([
            'asignatura_id' => 'required|exists:asignaturas,id',
            'docente_id' => 'nullable|exists:docentes,id',
            'grupo_teorico' => 'required|string',
            'parcial' => 'required|string',
        ]);

        $queryDelete = $this->buildBancoDeleteQuery(
            $validated['asignatura_id'],
            $validated['grupo_teorico'],
            $validated['parcial'],
            $validated['docente_id'] ?? null
        );

        $preguntas = (clone $queryDelete)->get(['id', 'imagen']);
        $imagenes = $preguntas
            ->pluck('imagen')
            ->filter()
            ->unique()
            ->values();

        $deletedCount = $queryDelete->delete();

        foreach ($imagenes as $imagen) {
            Storage::disk('public')->delete('preguntas/'.$imagen);
        }

        Log::info('Borrado masivo del banco de preguntas', [
            'asignatura_id' => $validated['asignatura_id'],
            'grupo_teorico' => $validated['grupo_teorico'],
            'parcial' => $this->normalizarTipoExamen($validated['parcial']),
            'docente_id' => $validated['docente_id'] ?? null,
            'deleted' => $deletedCount,
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'deleted' => $deletedCount,
            'message' => "Se eliminaron {$deletedCount} preguntas del banco filtrado.",
        ]);
    }

    /**
     * Actualizar una pregunta existente (con soporte para imagen).
     */
    public function update(Request $request, $id)
    {
        $pregunta = BancoPregunta::findOrFail($id);

        $validated = $request->validate([
            'enunciado' => 'required|string',
            'tipo' => 'required|string',
            'opciones' => 'nullable',
            'respuesta_correcta' => 'nullable',
            'dificultad' => 'nullable',
            'parcial' => 'nullable|string',
            'grupo' => 'nullable|string',
            'grupoTeorico' => 'nullable|string',
            'image_file' => 'nullable|image|max:5120',
            'remove_image' => 'nullable|boolean',
            'logro_esperado_id' => 'nullable|exists:logros_esperados,id',
            'asignatura_id' => 'nullable|exists:asignaturas,id',
            'sede_id' => 'nullable|exists:sedes,id',
        ]);

        $validated['tipo'] = $this->normalizarTipoPreguntaManual($validated['tipo'] ?? null);

        if (! in_array($validated['tipo'], $this->tiposPreguntaPermitidos(), true)) {
            throw ValidationException::withMessages([
                'tipo' => 'Tipo de pregunta no permitido.',
            ]);
        }

        $this->parsePreguntaPayload($validated);
        $this->sanitizePreguntaPayload($validated);
        $this->normalizePreguntaPayload($validated);

        if (! empty($validated['parcial'])) {
            $validated['parcial'] = $this->normalizarTipoExamen($validated['parcial']);
        }

        if (empty($validated['grupoTeorico']) && ! empty($validated['grupo'])) {
            $validated['grupoTeorico'] = $validated['grupo'];
        }

        if (($validated['remove_image'] ?? false) && ! $request->hasFile('image_file') && $pregunta->imagen) {
            Storage::disk('public')->delete('preguntas/'.$pregunta->imagen);
            $validated['imagen'] = null;
        }
        unset($validated['remove_image']);

        $this->storePreguntaImage($request, $validated, $pregunta);

        $pregunta->update($validated);

        return response()->json($this->sanitizePreguntaForResponse($pregunta));
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'asignatura_id' => 'required|exists:asignaturas,id',
            'docente_id' => 'nullable|exists:docentes,id',
            'logro_esperado_id' => 'nullable|exists:logros_esperados,id',
            'sede_id' => 'nullable|exists:sedes,id',
            'grupo' => 'nullable|string|max:255',
            'grupoTeorico' => 'nullable|string|max:255',
            'con_cartilla' => 'nullable|boolean',
            'parcial' => 'nullable|string',
            'modo' => 'nullable|in:agregar,reemplazar',
        ]);

        $file = $request->file('file');
        $asignaturaId = $request->input('asignatura_id');
        $logroId = $request->input('logro_esperado_id');
        $sedeId = $request->input('sede_id');
        $grupoTeorico = $request->input('grupoTeorico');
        $conCartilla = $request->boolean('con_cartilla', true);
        $parcialSolicitado = $request->input('parcial');
        $parcialSolicitadoNormalizado = $parcialSolicitado
            ? $this->normalizarTipoExamen($parcialSolicitado)
            : null;

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

                Log::info('Vaciando banco de preguntas filtrado', [
                    'asignatura' => $asignaturaId,
                    'docente' => $docenteId,
                    'grupo' => $grupoTeorico,
                    'parcial' => $request->parcial,
                ]);

                $queryDelete->delete();
            }

            $spreadsheet = IOFactory::load($file->getPathname());
            $worksheet = $this->resolveBancoWorksheet($spreadsheet);
            $rows = $this->truncateBancoRowsAtNotasCarga($worksheet->toArray());

            if (count($rows) < 2) {
                throw new \Exception('El archivo no tiene filas de datos útiles.');
            }

            $headers = array_map(function ($h) {
                return mb_strtoupper(trim((string) $h));
            }, $rows[0]);

            $cols = [];
            foreach ($headers as $index => $header) {
                if (empty($header)) {
                    continue;
                }
                if (str_contains($header, 'TIPO')) {
                    $cols['TIPO'] = $index;
                } elseif (str_contains($header, 'GRUPO')) {
                    $cols['GRUPO'] = $index;
                } elseif (str_contains($header, 'ENUNCIADO')) {
                    $cols['ENUNCIADO'] = $index;
                } elseif ($header === 'A' || str_contains($header, 'OPCION A') || str_contains($header, 'OPCIÓN A') || str_contains($header, 'OPCION_A') || str_contains($header, 'OPCIÓN_A')) {
                    $cols['A'] = $index;
                } elseif ($header === 'B' || str_contains($header, 'OPCION B') || str_contains($header, 'OPCIÓN B') || str_contains($header, 'OPCION_B') || str_contains($header, 'OPCIÓN_B')) {
                    $cols['B'] = $index;
                } elseif ($header === 'C' || str_contains($header, 'OPCION C') || str_contains($header, 'OPCIÓN C') || str_contains($header, 'OPCION_C') || str_contains($header, 'OPCIÓN_C')) {
                    $cols['C'] = $index;
                } elseif ($header === 'D' || str_contains($header, 'OPCION D') || str_contains($header, 'OPCIÓN D') || str_contains($header, 'OPCION_D') || str_contains($header, 'OPCIÓN_D')) {
                    $cols['D'] = $index;
                } elseif ($header === 'E' || str_contains($header, 'OPCION E') || str_contains($header, 'OPCIÓN E') || str_contains($header, 'OPCION_E') || str_contains($header, 'OPCIÓN_E')) {
                    $cols['E'] = $index;
                } elseif (str_contains($header, 'RESPUESTA')) {
                    $cols['RESPUESTA'] = $index;
                } elseif (str_contains($header, 'DIFICULTAD')) {
                    $cols['DIFICULTAD'] = $index;
                } elseif (str_contains($header, 'PARCIAL')) {
                    $cols['PARCIAL'] = $index;
                }
            }

            // Defaults if column isn't found
            $cols['TIPO'] = $cols['TIPO'] ?? 0;
            $cols['ENUNCIADO'] = $cols['ENUNCIADO'] ?? 1;

            $count = 0;
            $evaluables = 0;
            $auxiliares = 0;
            $omitidas = 0;
            $existingDuplicateKeys = [];
            $batchDuplicateKeys = [];

            $existingQuery = BancoPregunta::where('asignatura_id', $asignaturaId)
                ->where('docente_id', $docenteId);

            if ($sedeId) {
                $existingQuery->where('sede_id', $sedeId);
            }

            if ($grupoTeorico) {
                $existingQuery->where('grupoTeorico', $grupoTeorico);
            }

            if ($parcialSolicitadoNormalizado) {
                $existingQuery->where('parcial', $parcialSolicitadoNormalizado);
            }

            $existingQuery
                ->get(['enunciado', 'grupo', 'grupoTeorico', 'parcial'])
                ->each(function ($pregunta) use (&$existingDuplicateKeys) {
                    $key = $this->construirClaveDuplicadoBanco([
                        'enunciado' => $pregunta->enunciado,
                        'grupo' => $pregunta->grupo,
                        'grupoTeorico' => $pregunta->grupoTeorico,
                        'parcial' => $pregunta->parcial,
                    ]);

                    if ($key !== '') {
                        $existingDuplicateKeys[$key] = true;
                    }
                });

            \Log::info('Importación Banco: docente_id detectado: '.($docenteId ?? 'NULL'));

            foreach ($rows as $index => $row) {
                if ($index === 0) {
                    continue;
                } // Skip Header

                $rawTipo = mb_strtoupper(trim((string) ($row[$cols['TIPO']] ?? '')));
                $rawEnunciado = $this->sanitizePreguntaText(trim((string) ($row[$cols['ENUNCIADO']] ?? '')));

                if (empty($rawTipo) && empty($rawEnunciado)) {
                    continue;
                } // Skip Empty Rows

                $tipo = $this->normalizarTipoPreguntaBanco($rawTipo);
                if (empty($tipo)) {
                    $tipo = 'SELECCION_SIMPLE';
                }

                $enunciado = nl2br($rawEnunciado); // Soporte saltos de línea (html)
                $grupo = isset($cols['GRUPO'])
                    ? $this->sanitizePreguntaText(trim((string) ($row[$cols['GRUPO']] ?? '')))
                    : null;

                // Construir Opciones
                $opciones = [];
                $letters = ['A', 'B', 'C', 'D', 'E'];
                foreach ($letters as $letter) {
                    if (isset($cols[$letter])) {
                        $val = $this->sanitizePreguntaText(trim((string) ($row[$cols[$letter]] ?? '')));
                        if ($val !== '') {
                            $opciones[] = ['id' => $letter, 'text' => nl2br($val)];
                        }
                    }
                }

                // Construir Respuesta
                $rawResp = isset($cols['RESPUESTA'])
                    ? $this->sanitizePreguntaText(trim((string) ($row[$cols['RESPUESTA']] ?? '')))
                    : '';
                $respuesta = $this->normalizarRespuestaBancoExcel($rawResp);

                // Validaciones por Tipo
                // 1. Respuesta y Dificultad Obligatorias (Excepto PROBLEMA/EMPAREJAMIENTO)
                if ($tipo !== 'PROBLEMA' && $tipo !== 'EMPAREJAMIENTO') {
                    if (empty($respuesta)) {
                        throw new \Exception('Fila '.($index + 1).": tipo \"$tipo\" requiere respuesta.");
                    }
                    $rawDif = isset($cols['DIFICULTAD'])
                        ? $this->sanitizePreguntaText(trim((string) ($row[$cols['DIFICULTAD']] ?? '')))
                        : '';
                    if ($rawDif === '') {
                        throw new \Exception('Fila '.($index + 1).": tipo \"$tipo\" requiere nivel de dificultad (1, 2 o 3).");
                    }
                } else {
                    // PROBLEMA y EMPAREJAMIENTO deben estar vacíos
                    if (! empty($respuesta)) {
                        throw new \Exception('Fila '.($index + 1).": tipo \"$tipo\" NO debe tener respuesta (debe estar vacía).");
                    }
                    $rawDif = isset($cols['DIFICULTAD'])
                        ? $this->sanitizePreguntaText(trim((string) ($row[$cols['DIFICULTAD']] ?? '')))
                        : '';
                    if ($rawDif !== '') {
                        throw new \Exception('Fila '.($index + 1).": tipo \"$tipo\" NO debe tener dificultad (debe estar vacía).");
                    }
                }

                // 2. Validaciones por estructura especial
                if ($tipo === 'RESPUESTA_COMPUESTA') {
                    $respLetter = is_array($respuesta) ? ($respuesta[0] ?? '') : $respuesta;
                    if (! in_array($respLetter, ['A', 'B', 'C', 'D'], true)) {
                        throw new \Exception('Fila '.($index + 1).': Respuesta Compuesta debe tener una respuesta entre A y D.');
                    }
                    if (count($opciones) !== 4) {
                        throw new \Exception('Fila '.($index + 1).': Respuesta Compuesta debe tener exactamente 4 opciones fijas (A, B, C y D).');
                    }
                }

                if ($tipo === 'PREGUNTA_CON_CLAVE') {
                    $respLetter = is_array($respuesta) ? ($respuesta[0] ?? '') : $respuesta;
                    if (! in_array($respLetter, ['A', 'B', 'C', 'D', 'E'], true)) {
                        throw new \Exception('Fila '.($index + 1).': Pregunta con Clave debe tener una respuesta entre A y E.');
                    }
                    if (count($opciones) !== 4) {
                        throw new \Exception('Fila '.($index + 1).': Pregunta con Clave debe tener exactamente 4 incisos (1, 2, 3 y 4).');
                    }
                }

                $dificultad = isset($cols['DIFICULTAD'])
                    ? $this->sanitizePreguntaText(trim((string) ($row[$cols['DIFICULTAD']] ?? '')))
                    : '';

                // PR y EM no llevan dificultad por regla de negocio
                if ($tipo === 'PROBLEMA' || $tipo === 'EMPAREJAMIENTO') {
                    $dificultad = '';
                } else {
                    $dificultad = $dificultad ?: 'MEDIA';
                }

                $parcial = isset($cols['PARCIAL'])
                    ? $this->sanitizePreguntaText(trim((string) ($row[$cols['PARCIAL']] ?? '')))
                    : null;
                if ($parcial) {
                    $parcial = $this->normalizarTipoExamen($parcial);
                } elseif ($parcialSolicitadoNormalizado) {
                    $parcial = $parcialSolicitadoNormalizado;
                }

                $duplicateKey = $this->construirClaveDuplicadoBanco([
                    'enunciado' => $enunciado,
                    'grupo' => $grupo,
                    'grupoTeorico' => $grupoTeorico,
                    'parcial' => $parcial,
                ]);

                if (
                    $duplicateKey !== ''
                    && (isset($existingDuplicateKeys[$duplicateKey]) || isset($batchDuplicateKeys[$duplicateKey]))
                ) {
                    $omitidas++;

                    continue;
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
                    'created_by' => auth()->id(),
                ]);

                if ($duplicateKey !== '') {
                    $batchDuplicateKeys[$duplicateKey] = true;
                }

                $count++;
                if ($tipo === 'PROBLEMA' || $tipo === 'EMPAREJAMIENTO') {
                    $auxiliares++;
                } else {
                    $evaluables++;
                }
            }

            $this->updateOrCreateConfig($asignaturaId, $grupoTeorico, $request->parcial, $conCartilla);

            return response()->json([
                'success' => true,
                'message' => "Se han importado {$evaluables} preguntas nuevas correctamente.",
                'total' => $count,
                'evaluables' => $evaluables,
                'auxiliares' => $auxiliares,
                'omitidas' => $omitidas,
            ]);

        } catch (\Exception $e) {
            $message = $e->getMessage();
            $statusCode = 500;

            if (
                str_starts_with($message, 'Fila ')
                || str_contains($message, 'hoja Banco')
                || str_contains($message, 'filas de datos')
            ) {
                $statusCode = 422;
            }

            return response()->json([
                'success' => false,
                'error' => 'Error al procesar el archivo Excel: '.$message,
            ], $statusCode);
        }
    }

    private function resolveBancoWorksheet($spreadsheet)
    {
        $worksheet = $spreadsheet->getSheetByName('Banco');
        if ($worksheet) {
            return $worksheet;
        }

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $title = mb_strtoupper(trim((string) $sheet->getTitle()));
            if ($title === 'BANCO') {
                return $sheet;
            }
        }

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $rows = $sheet->toArray(null, false, false, false);
            $headers = array_map(
                fn ($header) => mb_strtoupper(trim((string) $header)),
                $rows[0] ?? []
            );

            if (in_array('TIPO', $headers, true) && in_array('ENUNCIADO', $headers, true)) {
                return $sheet;
            }
        }

        throw new \Exception('No se encontro la hoja Banco con los encabezados esperados.');
    }

    private function truncateBancoRowsAtNotasCarga(array $rows): array
    {
        foreach ($rows as $index => $row) {
            if ($this->isNotasCargaRow((array) $row)) {
                return array_slice($rows, 0, $index);
            }
        }

        return $rows;
    }

    private function isNotasCargaRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (mb_strtoupper(trim((string) $cell)) === 'NOTAS DE CARGA') {
                return true;
            }
        }

        return false;
    }

    private function construirClaveDuplicadoBanco(array $payload): string
    {
        $enunciado = $this->normalizarTextoDuplicadoBanco($payload['enunciado'] ?? '');

        if ($enunciado === '') {
            return '';
        }

        return implode('||', [
            $enunciado,
            $this->normalizarGrupoDuplicadoBanco($payload['grupoTeorico'] ?? $payload['grupo_teorico'] ?? ''),
            $this->normalizarTipoExamen($payload['parcial'] ?? ''),
            $this->normalizarGrupoDuplicadoBanco($payload['grupo'] ?? ''),
        ]);
    }

    private function normalizarTextoDuplicadoBanco($value): string
    {
        $text = $this->sanitizePreguntaText((string) ($value ?? ''));
        $text = preg_replace('/<br\s*\/?>/i', ' ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N', 'ü' => 'u', 'Ü' => 'U',
        ]);
        $text = preg_replace('/\s+/u', ' ', $text);

        return mb_strtoupper(trim($text), 'UTF-8');
    }

    private function normalizarGrupoDuplicadoBanco($value): string
    {
        $text = $this->normalizarTextoDuplicadoBanco($value);
        $text = preg_replace('/^(GRUPO|G\.?|GT)\s*/u', '', $text);

        return trim((string) $text);
    }

    /**
     * Guardar configuración de cartilla (independiente de la importación)
     */
    public function saveConfig(Request $request)
    {
        $request->validate([
            'asignatura_id' => 'required|exists:asignaturas,id',
            'docente_id' => 'nullable|exists:docentes,id',
            'grupo_teorico' => 'required|string',
            'parcial' => 'required|string',
            'con_cartilla' => 'required|boolean',
        ]);

        $asignaturaId = $request->asignatura_id;
        $docenteId = $request->docente_id;
        $grupoTeorico = $request->grupo_teorico;
        $parcial = $request->parcial;
        $conCartilla = $request->con_cartilla;

        // Si es Sin Cartilla (false), procedemos a limpiar el banco de preguntas para este grupo/parcial
        if (! $conCartilla) {
            $queryDelete = $this->buildBancoDeleteQuery($asignaturaId, $grupoTeorico, $parcial, $docenteId);

            $deletedCount = $queryDelete->delete();

            Log::info("Preferencia Sin Cartilla: Se eliminaron {$deletedCount} preguntas", [
                'asignatura' => $asignaturaId,
                'grupo' => $grupoTeorico,
                'parcial' => $parcial,
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
            'configuracion' => $config,
        ]);
    }

    private function buildBancoDeleteQuery($asignaturaId, $grupoTeorico, $parcial, $docenteId = null)
    {
        $queryDelete = BancoPregunta::where('asignatura_id', $asignaturaId)
            ->where('grupoTeorico', $grupoTeorico)
            ->where('parcial', $this->normalizarTipoExamen($parcial));

        $user = auth()->user();
        $user?->loadMissing('rol');
        $rolCodigo = $user?->rol?->codigo;

        $rolesConAlcanceAmpliado = [
            'SUPER_ADMIN',
            'ADMIN',
            'DIRECTOR_CARRERA',
            'VICERRECTORADO',
            'VICERRECTOR_SEDE',
            'DIRECCION_ACADEMICA',
            'DIRECCIÓN ACADÉMICA',
            'RESPONSABLE_EVALUACIONES',
        ];

        if ($docenteId && in_array($rolCodigo, $rolesConAlcanceAmpliado, true)) {
            $queryDelete->where('docente_id', $docenteId);

            return $queryDelete;
        }

        $docenteId = Docente::where('user_id', auth()->id())->first()?->id;

        if ($docenteId) {
            $queryDelete->where('docente_id', $docenteId);
        } elseif (auth()->id()) {
            $queryDelete->where('created_by', auth()->id());
        }

        return $queryDelete;
    }

    private function updateOrCreateConfig($asignaturaId, $grupoTeorico, $parcial, $conCartilla)
    {
        if (! $parcial) {
            return null;
        }

        $parcialNormalizado = $this->normalizarTipoExamen($parcial);

        return BancoPreguntaConfiguracion::updateOrCreate(
            [
                'asignatura_id' => $asignaturaId,
                'grupo_teorico' => $grupoTeorico,
                'parcial' => $parcialNormalizado,
            ],
            [
                'con_cartilla' => $conCartilla,
                'updated_by' => auth()->id(),
            ]
        );
    }

    private function normalizarTipoExamen($tipo)
    {
        $tipo = strtolower(trim((string) $tipo));

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

    private function tiposPreguntaPermitidos(): array
    {
        return [
            'FALSO_VERDADERO',
            'RESPUESTA_COMPUESTA',
            'PREGUNTA_CON_CLAVE',
            'SELECCION_SIMPLE',
            'EMPAREJAMIENTO',
            'OPCION_EMPAREJAMIENTO',
            'PROBLEMA',
            'SUBPROBLEMA',
        ];
    }

    private function normalizarTipoPreguntaBanco(?string $tipo): ?string
    {
        return $this->normalizarTipoPreguntaManual($tipo);
    }

    private function contarGruposTipoPregunta(array $porTipo): array
    {
        $conteo = ['g1' => 0, 'g2' => 0, 'g3' => 0];

        foreach ($porTipo as $tipo => $total) {
            $grupo = $this->resolverGrupoTipoPregunta((string) $tipo);
            if ($grupo) {
                $conteo[$grupo] += (int) $total;
            }
        }

        return $conteo;
    }

    private function resolverGrupoTipoPregunta(string $tipo): ?string
    {
        $tipoNormalizado = $this->normalizarTipoPreguntaBanco($tipo);

        if (in_array($tipoNormalizado, ['FALSO_VERDADERO', 'PREGUNTA_CON_CLAVE', 'RESPUESTA_COMPUESTA'], true)) {
            return 'g1';
        }

        if ($tipoNormalizado === 'SELECCION_SIMPLE') {
            return 'g2';
        }

        if (in_array($tipoNormalizado, ['SUBPROBLEMA', 'OPCION_EMPAREJAMIENTO'], true)) {
            return 'g3';
        }

        return null;
    }

    private function normalizarRespuestaBancoExcel($value)
    {
        $respuesta = mb_strtoupper(trim((string) $value));

        if ($respuesta === '') {
            return '';
        }

        if (preg_match('/^([A-E])(?:\s*[:\.\)\-]|\b)/u', $respuesta, $matches)) {
            return $matches[1];
        }

        if (str_contains($respuesta, ',')) {
            return array_map(
                fn ($item) => $this->normalizarRespuestaBancoExcel($item),
                explode(',', $respuesta)
            );
        }

        return $respuesta;
    }

    private function normalizarTipoPreguntaManual(?string $tipo): ?string
    {
        $normalized = mb_strtoupper(trim((string) $tipo));
        $normalized = strtr($normalized, [
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
            'Ü' => 'U',
        ]);

        $map = [
            'FV' => 'FALSO_VERDADERO',
            'FALSO_VERDADERO' => 'FALSO_VERDADERO',
            'VERDADERO O FALSO' => 'FALSO_VERDADERO',
            'FALSO O VERDADERO' => 'FALSO_VERDADERO',
            'VERDADERO O FALSO SIMPLE' => 'FALSO_VERDADERO',
            'RESPUESTA_COMPUESTA' => 'RESPUESTA_COMPUESTA',
            'SELECCION_MULTIPLE' => 'RESPUESTA_COMPUESTA',
            'SM' => 'RESPUESTA_COMPUESTA',
            'RESPUESTA A/B/AMBAS/NINGUNA' => 'RESPUESTA_COMPUESTA',
            'PREGUNTA_CON_CLAVE' => 'PREGUNTA_CON_CLAVE',
            'PREGUNTA CON CLAVE' => 'PREGUNTA_CON_CLAVE',
            'VERDADERO O FALSO COMPLEJAS' => 'PREGUNTA_CON_CLAVE',
            'SELECCION_SIMPLE' => 'SELECCION_SIMPLE',
            'SELECCION_UNICA' => 'SELECCION_SIMPLE',
            'SELECCION SIMPLE' => 'SELECCION_SIMPLE',
            'SS' => 'SELECCION_SIMPLE',
            'SU' => 'SELECCION_SIMPLE',
            'SELECCION DE LA MEJOR RESPUESTA' => 'SELECCION_SIMPLE',
            'EMPAREJAMIENTO' => 'EMPAREJAMIENTO',
            'EM' => 'EMPAREJAMIENTO',
            'EMPAREJAMIENTO AMPLIADO' => 'EMPAREJAMIENTO',
            'OPCION_EMPAREJAMIENTO' => 'OPCION_EMPAREJAMIENTO',
            'OPCION EMPAREJAMIENTO' => 'OPCION_EMPAREJAMIENTO',
            'OPCION DE EMPAREJAMIENTO' => 'OPCION_EMPAREJAMIENTO',
            'OPCION EMPAREJAMIENTO AMPLIADO' => 'OPCION_EMPAREJAMIENTO',
            'OPCION DE EMPAREJAMIENTO AMPLIADO' => 'OPCION_EMPAREJAMIENTO',
            'PROBLEMA' => 'PROBLEMA',
            'PROBLEMA O CASO' => 'PROBLEMA',
            'PR' => 'PROBLEMA',
            'ITEMS AGRUPADOS POR CASO CLINICO O PROBLEMA' => 'PROBLEMA',
            'SUBPROBLEMA' => 'SUBPROBLEMA',
            'SUB PROBLEMA' => 'SUBPROBLEMA',
            'SUBPREGUNTA' => 'SUBPROBLEMA',
            'SP' => 'SUBPROBLEMA',
            'SUBITEM DE CASO O PROBLEMA' => 'SUBPROBLEMA',
        ];

        return $map[$normalized] ?? $normalized;
    }

    private function sanitizePreguntaForResponse(BancoPregunta $pregunta): BancoPregunta
    {
        $pregunta->enunciado = $this->sanitizePreguntaText($pregunta->enunciado);
        $pregunta->grupo = $this->sanitizePreguntaText($pregunta->grupo);
        $pregunta->grupoTeorico = $this->sanitizePreguntaText($pregunta->grupoTeorico);
        $pregunta->dificultad = $this->sanitizePreguntaText($pregunta->dificultad);
        $pregunta->parcial = $this->sanitizePreguntaText($pregunta->parcial);
        $pregunta->opciones = $this->sanitizePreguntaValue($pregunta->opciones);
        $pregunta->respuesta_correcta = $this->sanitizePreguntaValue($pregunta->respuesta_correcta);

        return $pregunta;
    }

    private function sanitizePreguntaPayload(array &$validated): void
    {
        foreach (['enunciado', 'grupo', 'grupoTeorico', 'parcial', 'dificultad'] as $field) {
            if (array_key_exists($field, $validated) && is_string($validated[$field])) {
                $validated[$field] = $this->sanitizePreguntaText($validated[$field]);
            }
        }

        if (array_key_exists('opciones', $validated)) {
            $validated['opciones'] = $this->sanitizePreguntaValue($validated['opciones']);
        }

        if (array_key_exists('respuesta_correcta', $validated)) {
            $validated['respuesta_correcta'] = $this->sanitizePreguntaValue($validated['respuesta_correcta']);
        }
    }

    private function sanitizePreguntaValue($value)
    {
        if (is_string($value)) {
            return $this->sanitizePreguntaText($value);
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->sanitizePreguntaValue($item), $value);
        }

        return $value;
    }

    private function sanitizePreguntaText($value): string
    {
        $text = (string) ($value ?? '');

        if ($text === '') {
            return '';
        }

        $text = str_replace(
            [
                'F�cil',
                'Dif�cil',
                'Te�rico',
                'relaci�n',
                't�rmino',
                'instrucci�n',
                'informaci�n',
                'visualizaci�n',
                'importaci�n',
                'configuraci�n',
                'combinaci�n',
                'despu�s',
                'm�nimo',
                'v�lidas',
                'cl�nico',
                'cl�nica',
                'diagn�stico',
                'diagn�stica',
                'presi�n',
                'sist�lica',
                'diast�lica',
                'activaci�n',
                'parasimp�tica',
                'simp�tica',
                'contracci�n',
                'relajaci�n',
                'est�n',
                'm�s',
                'n�useas',
                'pedi�trico',
                'radiograf�a',
                'ecograf�a',
                '�ptico',
                'raqu�deo',
                'hipertensi�n',
                'fibrilaci�n',
                'Cu�les',
                'cu�les',
                'Qu�',
                'qu�',
                'est�',
            ],
            [
                'Fácil',
                'Difícil',
                'Teórico',
                'relación',
                'término',
                'instrucción',
                'información',
                'visualización',
                'importación',
                'configuración',
                'combinación',
                'después',
                'mínimo',
                'válidas',
                'clínico',
                'clínica',
                'diagnóstico',
                'diagnóstica',
                'presión',
                'sistólica',
                'diastólica',
                'activación',
                'parasimpática',
                'simpática',
                'contracción',
                'relajación',
                'están',
                'más',
                'náuseas',
                'pediátrico',
                'radiografía',
                'ecografía',
                'óptico',
                'raquídeo',
                'hipertensión',
                'fibrilación',
                'Cuáles',
                'cuáles',
                'Qué',
                'qué',
                'está',
            ],
            $text
        );

        if (preg_match('/[ÃÂâ]/u', $text)) {
            $decoded = $this->decodeLatin1Utf8String($text);
            if ($this->mojibakeScore($decoded) < $this->mojibakeScore($text)) {
                $text = $decoded;
            }
        }

        return $text;
    }

    private function decodeLatin1Utf8String(string $text): string
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $bytes = '';

        foreach ($chars as $char) {
            $ord = mb_ord($char, 'UTF-8');

            if ($ord === false || $ord > 255) {
                return $text;
            }

            $bytes .= chr($ord);
        }

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $text;
    }

    private function mojibakeScore(string $text): int
    {
        preg_match_all('/[ÃÂâ�]/u', $text, $matches);

        return count($matches[0]);
    }

    private function parsePreguntaPayload(array &$validated): void
    {
        if (isset($validated['opciones']) && is_string($validated['opciones'])) {
            $decoded = json_decode($validated['opciones'], true);
            $validated['opciones'] = is_array($decoded) ? $decoded : [];
        }

        if (isset($validated['respuesta_correcta']) && is_string($validated['respuesta_correcta'])) {
            $trimmed = trim($validated['respuesta_correcta']);

            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                $validated['respuesta_correcta'] = is_array($decoded) ? $decoded : [];
            }
        }
    }

    private function normalizePreguntaPayload(array &$validated): void
    {
        $tipo = $validated['tipo'] ?? null;
        $requiereRespuesta = ! in_array($tipo, ['PROBLEMA', 'EMPAREJAMIENTO'], true);
        $usaOpciones = in_array(
            $tipo,
            ['FALSO_VERDADERO', 'RESPUESTA_COMPUESTA', 'PREGUNTA_CON_CLAVE', 'SELECCION_SIMPLE', 'SUBPROBLEMA'],
            true
        );

        if (! $requiereRespuesta) {
            $validated['respuesta_correcta'] = [];
            if ($tipo !== 'EMPAREJAMIENTO') {
                $validated['opciones'] = [];
            }
            $validated['dificultad'] = '';

            return;
        }

        $respuesta = $validated['respuesta_correcta'] ?? null;
        $respuestaVacia = is_array($respuesta)
            ? count(array_filter($respuesta, fn ($item) => trim((string) $item) !== '')) === 0
            : trim((string) $respuesta) === '';

        if ($respuestaVacia) {
            throw ValidationException::withMessages([
                'respuesta_correcta' => 'La respuesta correcta es obligatoria para este tipo de pregunta.',
            ]);
        }

        if (! $usaOpciones) {
            $validated['opciones'] = [];
        }
    }

    private function storePreguntaImage(Request $request, array &$validated, ?BancoPregunta $pregunta = null): void
    {
        if (! $request->hasFile('image_file')) {
            return;
        }

        if ($pregunta?->imagen) {
            Storage::disk('public')->delete('preguntas/'.$pregunta->imagen);
        }

        $file = $request->file('image_file');
        $filename = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
        $file->storeAs('preguntas', $filename, 'public');
        $validated['imagen'] = $filename;
    }

    public function showImage($filename)
    {
        $path = storage_path('app/public/preguntas/'.$filename);
        if (! file_exists($path)) {
            abort(404);
        }

        return response()->file($path);
    }

    public function getLogo()
    {
        $path = public_path('descargas/unitepc-logo.png');
        if (! file_exists($path)) {
            abort(404);
        }

        return response()->file($path);
    }
}
