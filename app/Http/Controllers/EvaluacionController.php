<?php

namespace App\Http\Controllers;

use App\Models\Evaluacion;
use App\Models\ExamenGenerado;
use App\Models\BancoPregunta;
use App\Models\Asignatura;
use App\Models\LogroEsperado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EvaluacionController extends Controller
{
    /**
     * Listar evaluaciones de una asignatura
     */
    public function index(Request $request)
    {
        $asignaturaId = $request->query('asignatura_id');
        $evaluaciones = Evaluacion::where('asignatura_id', $asignaturaId)->orderBy('fecha_examen', 'desc')->get();
        return response()->json($evaluaciones);
    }

    /**
     * Crear Evaluación y Generar Patrón (Tipo A)
     */
    public function store(Request $request)
    {
        $request->validate([
            'asignatura_id' => 'required|exists:asignaturas,id',
            'nombre' => 'required|string',
            'parcial' => 'required|string', // "1er Parcial"
            'fecha_examen' => 'required|date',
            'hora_inicio' => 'required',
            'duracion_minutos' => 'required|integer',
            // Configuración de Dificultad
            'distribucion_facil' => 'required|integer',
            'distribucion_medio' => 'required|integer',
            'distribucion_dificil' => 'required|integer',
        ]);

        return DB::transaction(function () use ($request) {
            // 1. Crear Header
            $evaluacion = Evaluacion::create([
                'asignatura_id' => $request->asignatura_id,
                'nombre' => $request->nombre,
                'parcial' => $request->parcial,
                'fecha_examen' => $request->fecha_examen,
                'hora_inicio' => $request->hora_inicio,
                'duracion_minutos' => $request->duracion_minutos,
                'mezclar_preguntas' => $request->boolean('mezclar_preguntas', true),
                'mezclar_opciones' => $request->boolean('mezclar_opciones', true),
                'estado' => 'PROGRAMADA'
            ]);

            // 2. Seleccionar Preguntas (Lógica Aleatoria basada en Logros del Parcial)
            // Buscar Logros de la Asignatura que coincidan con el Parcial (o todos si no se filtra)
            // Asumimos filtro por texto "1er Parcial" en banco_preguntas...
            // O mejor: Logros de la asignatura -> Preguntas.

            // Buscar IDs de Logros de esta Asignatura y Periodo
            // Nota: LogroEsperado tiene 'periodo' (Ej: 1er Parcial).
            $logrosIds = LogroEsperado::whereHas('tema', function ($q) use ($request) {
                $q->where('asignatura_id', $request->asignatura_id);
            })->where('periodo', $request->parcial)->pluck('id');

            // Seleccionar Preguntas del Banco
            $preguntasFaciles = BancoPregunta::whereIn('logro_esperado_id', $logrosIds)
                ->where('dificultad', 'BAJA')->inRandomOrder()->take($request->distribucion_facil)->get();
            $preguntasMedias = BancoPregunta::whereIn('logro_esperado_id', $logrosIds)
                ->where('dificultad', 'MEDIA')->inRandomOrder()->take($request->distribucion_medio)->get();
            $preguntasDificiles = BancoPregunta::whereIn('logro_esperado_id', $logrosIds)
                ->where('dificultad', 'ALTA')->inRandomOrder()->take($request->distribucion_dificil)->get();

            $poolPreguntas = $preguntasFaciles->merge($preguntasMedias)->merge($preguntasDificiles);

            // Validar cantidad insuficiente? (Opcional, por ahora permitimos menos)

            // 3. Generar Variante A (Patrón)
            $examenA = ExamenGenerado::create([
                'evaluacion_id' => $evaluacion->id,
                'tipo' => 'TIPO A',
                'patron_respuestas_json' => [] // Se puede llenar tras el attach
            ]);

            // Insertar en Pivot (Orden aleatorio si 'mezclar_preguntas' es true)
            $orden = 1;
            $shuffled = $evaluacion->mezclar_preguntas ? $poolPreguntas->shuffle() : $poolPreguntas;

            $pivotData = [];
            foreach ($shuffled as $pregunta) {
                $pivotData[$pregunta->id] = ['orden' => $orden++];
            }
            $examenA->preguntas()->attach($pivotData);

            // Actualizar Cache de Patrón (Opcional, útil para render rápido)
            // Estructura: [{1: "A"}, {2: "B"}]
            // Para simplificar, lo dejamos vacío o lo calculamos on-the-fly en 'patron'

            return $evaluacion->load('examenesGenerados');
        });
    }

    /**
     * Obtener datos para el PDF del Patrón
     */
    public function patron($examenGeneradoId)
    {
        $examen = ExamenGenerado::with(['evaluacion.asignatura', 'preguntas'])->findOrFail($examenGeneradoId);

        // Regla de Negocio: 3 horas después del inicio
        $inicio = \Carbon\Carbon::parse($examen->evaluacion->fecha_examen . ' ' . $examen->evaluacion->hora_inicio);
        $disponible = $inicio->copy()->addHours(3);

        if (now()->lt($disponible)) {
            return response()->json([
                'error' => 'El patrón estará disponible a las ' . $disponible->format('H:i d/m/Y')
            ], 403);
        }

        // Formatear para la Vista PDF
        $data = [
            'institucion' => 'UNIVERSIDAD TÉCNICA PRIVADA COSMOS',
            'carrera' => 'Medicina', // Hardcoded o traer de Asignatura->Carrera
            'asignatura' => $examen->evaluacion->asignatura->nombre,
            'docente' => 'Docente Asignado', // Traer de Auth o Asignatura
            'examen_titulo' => $examen->evaluacion->parcial,
            'tipo_examen' => $examen->tipo, // TIPO A
            'fecha' => $examen->evaluacion->fecha_examen,
            'preguntas' => $examen->preguntas->map(function ($p) {
                return [
                    'numero' => $p->pivot->orden,
                    'respuesta_correcta' => $p->respuesta_correcta, // Ej: "B" o ["A", "C"]
                    'opciones' => $p->opciones // Para validar
                ];
            })
        ];

        return response()->json($data);
    }

    /**
     * Listar evaluaciones asignadas al docente (Rol de Exámenes)
     */
    public function misEvaluaciones(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // 1. Get Docente linked to User
        $docente = \App\Models\Docente::where('user_id', $user->id)->first();

        if (!$docente) {
            return response()->json([]);
        }

        // 2. Get subjects and groups
        $gruposDocente = $docente->grupos()->with('asignatura')->get();

        if ($gruposDocente->isEmpty()) {
            return response()->json([]);
        }

        // 3. Query RolExamen linked to Docente's Subjects
        $query = \App\Models\RolExamen::query();

        $query->where(function ($q) use ($gruposDocente) {
            foreach ($gruposDocente as $grupo) {
                if ($grupo->asignatura) {
                    $q->orWhere(function ($sub) use ($grupo) {
                        $sub->where('materia_codigo', $grupo->asignatura->codigo)
                            ->where('grupo', $grupo->nombre);
                    });
                }
            }
        });

        $examenes = $query->orderBy('fecha', 'desc')->orderBy('hora_inicio', 'asc')->get();

        // 4. Map to matched structure
        $data = $examenes->map(function ($ex) {
            $estado = 'Programada';

            if ($ex->fecha) {
                // Assuming date is Carbon cast
                $start = \Carbon\Carbon::parse($ex->fecha->format('Y-m-d') . ' ' . $ex->hora_inicio);
                $end = $start->copy()->addHours(2);

                if (now()->gt($end)) $estado = 'Completada';
                elseif (now()->gt($start) && now()->lt($end)) $estado = 'En Curso';
            }

            return [
                'id' => $ex->id,
                'materia' => $ex->materia_nombre,
                'codigo' => $ex->materia_codigo,
                'grupo' => $ex->grupo,
                'parcial' => $ex->tipo_examen,
                'fecha' => $ex->fecha ? $ex->fecha->format('Y-m-d') : null,
                'hora' => $ex->hora_inicio,
                'aula' => $ex->aula,
                'preguntas' => 0, // No online exam linked yet
                'duracion' => 90,
                'estado' => $estado,
            ];
        });

        return response()->json($data);
    }
}
