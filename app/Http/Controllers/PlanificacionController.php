<?php

namespace App\Http\Controllers;

use App\Models\Unidad; // Added
use App\Models\Tema;
use App\Models\EstrategiaTema;
use App\Models\EvaluacionTema;
use App\Models\SecuenciaTema;
use App\Models\LogroEsperado;
use App\Models\Indicador;
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

        // 1. Contenidos
        if (isset($data['contenidos'])) {
            $updateData['contenido_conceptual'] = $data['contenidos']['conceptual'] ?? null;
            $updateData['contenido_procedimental'] = $data['contenidos']['procedimental'] ?? null;
            $updateData['contenido_actitudinal'] = $data['contenidos']['actitudinal'] ?? null;
        }

        // 2. Estrategias
        if (isset($data['estrategias'])) {
            $updateData['estrategias_metodologicas'] = $data['estrategias']['metodologicas'] ?? '';
            $updateData['estrategias_aprendizaje'] = $data['estrategias']['aprendizaje'] ?? '';
            $updateData['estrategias_recursos'] = $data['estrategias']['recursos'] ?? [];
        }

        // 3. Evaluaciones
        if (isset($data['evaluacion'])) {
            $updateData['evaluacion_formativa'] = $data['evaluacion']['formativa'] ?? null;
            $updateData['evaluacion_sumativa'] = $data['evaluacion']['sumativa'] ?? null;
        }

        $tema->update($updateData);

        // 4. Referencias Bibliograficas (Sync)
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

        return response()->json($tema);
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
        $logro = $tema->logreseEperados()->create($request->all());
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
            'logreseEperados.indicadores',
            'bibliografias'
        ])->findOrFail($temaId);

        // Transformar al formato que espera el Frontend
        $formatted = [
            'id' => $tema->id,
            'titulo' => $tema->titulo,
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
            'secuencia_didactica' => $tema->secuencias,
            'logros_esperados' => $tema->logreseEperados,
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

        return response()->json($formatted);
    }
}
