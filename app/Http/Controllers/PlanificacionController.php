<?php

namespace App\Http\Controllers;

use App\Models\Unidad; // Added
use App\Models\Tema;
use App\Models\EstrategiaTema;
use App\Models\EvaluacionTema;
use App\Models\SecuenciaTema;
use App\Models\LogroEsperado;
use App\Models\Indicador;
use App\Models\PlanificacionPersonal;
use Illuminate\Http\Request;

class PlanificacionController extends Controller
{
    /**
     * Actualizar Unidad (Objetivo/Competencia)
     */
    public function updateUnidad(Request $request, $id)
    {
        $unidad = Unidad::findOrFail($id);
        $unidad->update($request->only('objetivo', 'contenido_minimo', 'titulo', 'elemento_competencia'));
        return response()->json($unidad);
    }

    public function storeUnidad(Request $request)
    {
        // Validar asignatura_id
        $request->validate([
            'asignatura_id' => 'required|exists:asignaturas,id',
            'titulo' => 'required|string',
            'numero' => 'required|integer'
        ]);

        $unidad = Unidad::create($request->all());
        return response()->json($unidad, 201);
    }

    public function destroyUnidad($id)
    {
        $unidad = Unidad::findOrFail($id);
        $unidad->temas()->delete(); // Cascada manual si no está en DB
        $unidad->delete();
        return response()->json(null, 204);
    }

    // --- TEMAS ---

    public function storeTema(Request $request, $unidadId)
    {
        $unidad = Unidad::findOrFail($unidadId);

        $lastOrder = $unidad->temas()->max('orden') ?? 0;

        $tema = $unidad->temas()->create([
            'titulo' => $request->input('titulo', 'Nuevo Tema'),
            'descripcion' => $request->input('descripcion', ''),
            'orden' => $lastOrder + 1,
            'horas_teoricas' => $request->input('horas_teoricas', 0),
            'horas_practicas' => $request->input('horas_practicas', 0),
        ]);

        return response()->json($tema, 201);
    }

    public function moveTema(Request $request, $id)
    {
        $request->validate([
            'direction' => 'required|in:up,down'
        ]);

        $tema = Tema::findOrFail($id);
        $direction = $request->input('direction');

        if ($direction === 'up') {
            $neighbor = Tema::where('unidad_id', $tema->unidad_id)
                ->where('orden', '<', $tema->orden)
                ->orderBy('orden', 'desc')
                ->first();
        } else {
            $neighbor = Tema::where('unidad_id', $tema->unidad_id)
                ->where('orden', '>', $tema->orden)
                ->orderBy('orden', 'asc')
                ->first();
        }

        if ($neighbor) {
            $currentOrder = $tema->orden;
            $neighborOrder = $neighbor->orden;

            $tema->update(['orden' => $neighborOrder]);
            $neighbor->update(['orden' => $currentOrder]);
        }

        return response()->json(['message' => 'Orden actualizado']);
    }

    public function destroyTema($id)
    {
        $tema = Tema::findOrFail($id);
        $unidadId = $tema->unidad_id;

        $tema->delete();

        // Re-enumerar
        $temas = Tema::where('unidad_id', $unidadId)->orderBy('orden')->get();
        foreach ($temas as $index => $t) {
            $t->update(['orden' => $index + 1]);
        }

        return response()->json(null, 204);
    }
    /**
     * Actualiza los contenidos (saberes) de un tema.
     */
    /**
     * Actualiza CUALQUIER campo del tema (Contenidos, Estrategias, Evaluaciones).
     * El frontend envía 'contenidos', 'estrategias', 'evaluacion' como objetos completos.
     */
    public function updateTema(Request $request, $temaId)
    {
        $tema = Tema::findOrFail($temaId);

        // Mapeo de lo que envía el frontend vs base de datos
        // Frontend: formTema.contenidos -> { conceptual: [], ... }
        // DB: contenido_conceptual (json), ...

        $data = $request->all();
        $updateData = [];

        // 0. Campos Básicos
        if (isset($data['titulo'])) $updateData['titulo'] = $data['titulo'];
        if (isset($data['descripcion'])) $updateData['descripcion'] = $data['descripcion'];
        if (isset($data['horas_teoricas'])) $updateData['horas_teoricas'] = $data['horas_teoricas'];
        if (isset($data['horas_practicas'])) $updateData['horas_practicas'] = $data['horas_practicas'];

        // 1. Contenidos
        if (isset($data['contenidos'])) {
            $updateData['contenido_conceptual'] = $data['contenidos']['conceptual'] ?? null;
            $updateData['contenido_procedimental'] = $data['contenidos']['procedimental'] ?? null;
            $updateData['contenido_actitudinal'] = $data['contenidos']['actitudinal'] ?? null;
        }

        // 1.5 Resultado de Aprendizaje
        if (isset($data['resultado_aprendizaje'])) {
            $updateData['resultado_aprendizaje'] = $data['resultado_aprendizaje'];
        }

        // 1.5 Map Contenidos (Nested -> Flat)
        if (isset($data['contenidos'])) {
            $updateData['contenido_conceptual'] = $data['contenidos']['conceptual'] ?? [];
            $updateData['contenido_procedimental'] = $data['contenidos']['procedimental'] ?? [];
            $updateData['contenido_actitudinal'] = $data['contenidos']['actitudinal'] ?? [];
        }

        // --- PERSONAL DATA INTERCEPTION ---
        // Fields: estrategias_*, evaluacion_*, secuencia_didactica
        $personalData = [];
        $hasPersonalData = false;

        // 2. Estrategias (PERSONAL)
        if (isset($data['estrategias'])) {
            $personalData['estrategias_metodologicas'] = $data['estrategias']['metodologicas'] ?? '';
            $personalData['estrategias_aprendizaje'] = $data['estrategias']['aprendizaje'] ?? '';
            $personalData['estrategias_recursos'] = $data['estrategias']['recursos'] ?? [];
            $hasPersonalData = true;
        }

        // 3. Evaluaciones (PERSONAL)
        if (isset($data['evaluacion'])) {
            $personalData['evaluacion_formativa'] = $data['evaluacion']['formativa'] ?? null;
            $personalData['evaluacion_sumativa'] = $data['evaluacion']['sumativa'] ?? null;
            $hasPersonalData = true;
        }

        // 4. Secuencia (PERSONAL)
        if (isset($data['secuencia_didactica'])) {
            $personalData['secuencia_didactica'] = $data['secuencia_didactica'];
            $hasPersonalData = true;
        }

        // Save Personal Data
        if ($hasPersonalData) {
            $userId = auth()->id() ?? 1; // Default fallback if no auth
            PlanificacionPersonal::updateOrCreate(
                ['tema_id' => $tema->id, 'user_id' => $userId],
                $personalData
            );
        }

        // Only update shared fields on Tema
        $tema->update($updateData);

        // 4. Referencias Bibliograficas (Sync - SHARED)
        if (isset($data['referencias_bibliograficas'])) {
            // Espera: [{ bibliografia_id: 1, pagina_desde: 10, pagina_hasta: 20 }, ...]
            $syncData = [];
            foreach ($data['referencias_bibliograficas'] as $ref) {
                if (!empty($ref['bibliografia_id'])) {
                    $syncData[$ref['bibliografia_id']] = [
                        'pagina_desde' => $ref['pagina_desde'] ?? null,
                        'pagina_hasta' => $ref['pagina_hasta'] ?? null
                    ];
                }
            }
            $tema->bibliografias()->sync($syncData);
        }

        // 5. Resultado de Aprendizaje
        if (isset($data['resultado_aprendizaje'])) {
            $tema->update(['resultado_aprendizaje' => $data['resultado_aprendizaje']]);
        }

        // 6. Logros Esperados y Indicadores (Deep Sync)
        if (isset($data['logros_esperados'])) {
            $incomingLogrosIds = [];
            \Illuminate\Support\Facades\Log::info('Procesando Logros:', ['count' => count($data['logros_esperados'])]);

            foreach ($data['logros_esperados'] as $logroData) {
                // Map 'parcial' from Frontend to 'periodo' in DB
                $periodo = $logroData['parcial'] ?? '1er Parcial'; // Force String default

                if (isset($logroData['id']) && is_numeric($logroData['id']) && $logroData['id'] < 1000000000000) {
                    // Update existing
                    $logro = LogroEsperado::find($logroData['id']);
                    if ($logro && $logro->tema_id == $tema->id) {
                        $logro->update([
                            'descripcion' => $logroData['descripcion'],
                            'tipo_logro' => $logroData['tipo_logro'] ?? null,
                            'periodo' => (string)$periodo
                        ]);
                        $incomingLogrosIds[] = $logro->id;
                        $this->syncIndicadores($logro, $logroData['indicadores'] ?? []);
                    }
                } else {
                    // Create new
                    $newLogro = $tema->logros()->create([
                        'descripcion' => $logroData['descripcion'],
                        'tipo_logro' => $logroData['tipo_logro'] ?? null,
                        'periodo' => (string)$periodo
                    ]);
                    $incomingLogrosIds[] = $newLogro->id;
                    $this->syncIndicadores($newLogro, $logroData['indicadores'] ?? []);
                }
            }

            // Delete removed
            $tema->logros()->whereNotIn('id', $incomingLogrosIds)->delete();
        }

        // RELOAD Logros for response
        $tema->load('logros.indicadores');

        // Prepare response matching Frontend expectations
        $response = $tema->toArray();
        $response['logros_esperados'] = $response['logros'];

        return response()->json($response);
    }

    private function syncIndicadores($logro, $indicadoresData)
    {
        \Illuminate\Support\Facades\Log::info('Sync Indicadores for Logro ' . $logro->id, ['count' => count($indicadoresData)]);
        $incomingIds = [];
        foreach ($indicadoresData as $indData) {
            if (isset($indData['id']) && is_numeric($indData['id']) && $indData['id'] < 1000000000000) {
                $ind = Indicador::find($indData['id']);
                if ($ind && $ind->logro_esperado_id == $logro->id) {
                    $ind->update(['descripcion' => $indData['descripcion']]);
                    $incomingIds[] = $ind->id;
                }
            } else {
                $newInd = $logro->indicadores()->create(['descripcion' => $indData['descripcion']]);
                $incomingIds[] = $newInd->id;
            }
        }

        // Delete removed indicators (was missing!)
        $logro->indicadores()->whereNotIn('id', $incomingIds)->delete();
    }

    // --- SECUENCIAS (Siguen siendo tabla aparte) ---
    public function storeSecuencia(Request $request, $temaId)
    {
        $tema = Tema::findOrFail($temaId);
        $secuencia = $tema->secuencias()->create($request->all());
        return response()->json($secuencia, 201);
    }

    public function destroySecuencia($id)
    {
        SecuenciaTema::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    // --- LOGROS ---
    public function storeLogro(Request $request, $temaId)
    {
        $tema = Tema::findOrFail($temaId);
        $logro = $tema->logros()->create($request->all());
        return response()->json($logro, 201);
    }

    public function destroyLogro($id)
    {
        LogroEsperado::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    // --- INDICADORES ---
    public function storeIndicador(Request $request, $logroId)
    {
        $logro = LogroEsperado::findOrFail($logroId);
        $indicador = $logro->indicadores()->create($request->all());
        return response()->json($indicador, 201);
    }

    public function destroyIndicador($id)
    {
        Indicador::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    /**
     * Devuelve TODA la data rica de un tema formateada para el Frontend
     */
    public function getFullTema($temaId)
    {
        $tema = Tema::with([
            'secuencias',
            'logros.indicadores', // Changed from logreseEperados to logros
            'bibliografias'
        ])->findOrFail($temaId);

        // --- MERGE PERSONAL DATA ---
        $userId = auth()->id();
        if ($userId) {
            $personal = PlanificacionPersonal::where('tema_id', $temaId) // Use $temaId here
                ->where('user_id', $userId)
                ->first();

            if ($personal) {
                // Override shared fields with personal data for the response
                $tema->estrategias_metodologicas = $personal->estrategias_metodologicas;
                $tema->estrategias_aprendizaje = $personal->estrategias_aprendizaje;
                $tema->estrategias_recursos = $personal->estrategias_recursos;
                $tema->evaluacion_formativa = $personal->evaluacion_formativa;
                $tema->evaluacion_sumativa = $personal->evaluacion_sumativa;
                $tema->secuencia_didactica = $personal->secuencia_didactica;

                // Add flag to frontend knows it is personal (optional)
                $tema->es_personalizado = true;
            }
        }

        // Transformar al formato que espera el Frontend
        $formatted = [
            'id' => $tema->id,
            'titulo' => $tema->titulo,
            'descripcion' => $tema->descripcion,
            'horas_practicas' => $tema->horas_practicas,
            'horas_teoricas' => $tema->horas_teoricas,
            // Reconstruir Objetos
            'contenidos' => [
                'conceptual' => $tema->contenido_conceptual ?? [],
                'procedimental' => $tema->contenido_procedimental ?? [],
                'actitudinal' => $tema->contenido_actitudinal ?? []
            ],
            'estrategias' => [
                'metodologicas' => $tema->estrategias_metodologicas ?? '',
                'aprendizaje' => $tema->estrategias_aprendizaje ?? '',
                'recursos' => $tema->estrategias_recursos ?? []
            ],
            'evaluacion' => [
                'formativa' => $tema->evaluacion_formativa ?? ['actividades' => [], 'instrumentos' => [], 'evidencias' => []],
                'sumativa' => $tema->evaluacion_sumativa ?? ['actividades' => [], 'instrumentos' => [], 'evidencias' => []]
            ],
            'secuencia_didactica' => $tema->secuencia_didactica ?? [], // Use the potentially overridden sequence_didactica
            'logros_esperados' => $tema->logros, // Changed from logreseEperados to logros
            'referencias_bibliograficas' => $tema->bibliografias->map(function ($b) {
                return [
                    'bibliografia_id' => $b->id,
                    'titulo' => $b->titulo, // Extra info for UI
                    'autor' => $b->autor,
                    'anio' => $b->anio,
                    'pagina_desde' => $b->pivot->pagina_desde,
                    'pagina_hasta' => $b->pivot->pagina_hasta
                ];
            })
        ];

        // Add the 'es_personalizado' flag if it was set
        if (isset($tema->es_personalizado)) {
            $formatted['es_personalizado'] = $tema->es_personalizado;
        }

        return response()->json($formatted);
    }
}
