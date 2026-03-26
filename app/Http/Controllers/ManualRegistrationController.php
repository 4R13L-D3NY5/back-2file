<?php

namespace App\Http\Controllers;

use App\Services\PlanningSyncService;
use App\Models\Grupo;
use App\Models\Docente;
use App\Models\Asignatura;
use App\Models\Carrera;
use App\Models\User;
use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;

class ManualRegistrationController extends Controller
{
    protected $planningService;
    protected $apiUrl = 'http://181.188.185.211:9098/api/Grupos/listar/';

    public function __construct(PlanningSyncService $planningService)
    {
        $this->planningService = $planningService;
    }

    /**
     * Fetch data from Planning API and enrich with SIDOPA status per group.
     */
    public function fetchFromPlanning(Request $request)
    {
        $request->validate([
            'gestion'  => 'required|string',
            'sede'     => 'required',
            'carrera'  => 'required|string',
            'sigla'    => 'required|string'
        ]);

        $gestion  = strtoupper(trim($request->gestion));
        $carrera  = strtoupper(trim($request->carrera));
        $sigla    = strtoupper(trim($request->sigla));
        $sedeId   = (int) $request->sede;

        $params = [
            'gestion' => $gestion,
            'sede'    => $sedeId,
            'carrera' => $carrera
        ];

        try {
            $response = Http::timeout(30)->get($this->apiUrl, $params);

            if (!$response->successful()) {
                return response()->json(['error' => 'Error al consultar la API externa: ' . $response->status()], $response->status());
            }

            $data = $response->json();

            if (!is_array($data)) {
                Log::warning('Manual Registration: External API returned non-array data', ['response' => $response->body()]);
                return response()->json(['error' => 'La API retornó un formato inesperado'], 502);
            }

            // Filtrar por sigla (case insensitive)
            $filtered = collect($data)->filter(function ($item) use ($sigla) {
                return isset($item['siglaP']) && strtoupper(trim($item['siglaP'])) === $sigla;
            })->values();

            if ($filtered->isEmpty()) {
                return response()->json([
                    'message' => 'No se encontraron registros para la sigla ' . $sigla . ' en esa carrera/sede.',
                    'data'    => []
                ], 200);
            }

            // Preload: find the local asignatura and carrera for the lookup
            $asignatura = Asignatura::where('codigo', $sigla)->first();
            $carreraModel = Carrera::where('sigla', $carrera)->first();

            $grupos = $filtered->map(function ($item) use ($gestion, $asignatura, $carreraModel, $sedeId) {
                $grupoNombre = $item['grupo'] ?? '';
                $tipoCrudo   = strtoupper(trim($item['tipoClase'] ?? 'TEORICO'));
                $tipo        = ($tipoCrudo === 'REGULAR') ? 'TEORICO' : $tipoCrudo;

                // Check if this group already exists in SIDOPA
                $sidopaGrupo = null;
                $sidopaStatus = 'nuevo';
                $docenteActual = null;

                if ($asignatura && $carreraModel) {
                    // withoutGlobalScope: necesitamos ver también grupos INACTIVOS para mostrar su estado
                    $sidopaGrupo = Grupo::withoutGlobalScope('activo')->where([
                        'gestion'        => $gestion,
                        'asignatura_id'  => $asignatura->id,
                        'carrera_id'     => $carreraModel->id,
                        'nombre'         => $grupoNombre,
                        'tipo'           => $tipo,
                        'sede_id'        => $sedeId,
                    ])->with('docente')->first();

                    if ($sidopaGrupo) {
                        $sidopaStatus = 'registrado';
                        $docenteActual = $sidopaGrupo->docente ? [
                            'id'     => $sidopaGrupo->docente->id,
                            'nombre' => $sidopaGrupo->docente->nombre_completo,
                            'ci'     => $sidopaGrupo->docente->ci,
                        ] : null;
                    }
                }

                return [
                    'nombre'          => $grupoNombre,
                    'tipo'            => $tipo,
                    'docente'         => $item['docente'] ?? 'Sin Asignar',
                    'ci'              => $item['ci'] ?? '',
                    'dia'             => $item['dia'] ?? '',
                    'inicio'          => $item['horaInicio'] ?? '',
                    'fin'             => $item['horaFin'] ?? '',
                    'aula'            => $item['nomAulaLab'] ?? '',
                    'id_horario'      => $item['idHorario'] ?? null,
                    'sidopa_status'   => $sidopaStatus,
                    'sidopa_grupo_id' => $sidopaGrupo?->id,
                    'docente_actual'  => $docenteActual,
                    'raw'             => $item
                ];
            });

            $subject = [
                'nombre'  => $filtered->first()['materia'] ?? 'Sin nombre',
                'sigla'   => $filtered->first()['siglaP'] ?? '',
                'plan'    => $filtered->first()['planEst'] ?? 'N',
                'carrera' => $filtered->first()['carrera'] ?? '',
                'sede_id' => $sedeId,
                'grupos'  => $grupos->values()
            ];

            return response()->json($subject);

        } catch (\Exception $e) {
            Log::error('Manual Registration Fetch Error: ' . $e->getMessage());
            return response()->json(['error' => 'Excepción durante la consulta: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Store NEW groups into the local database using Sync service logic.
     */
    public function storeManual(Request $request)
    {
        $request->validate([
            'items' => 'required|array'
        ]);

        try {
            $stats = $this->planningService->syncBatch($request->items);

            return response()->json([
                'message' => 'Registros procesados correctamente',
                'stats'   => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Manual Registration Store Error: ' . $e->getMessage());
            return response()->json(['error' => 'Error al guardar los registros: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update (or assign) a docente for an existing grupo in SIDOPA.
     */
    public function updateDocente(Request $request)
    {
        $request->validate([
            'grupo_id' => 'required|integer|exists:grupos,id',
            'ci'       => 'required|string',
            'nombre'   => 'required|string',
        ]);

        try {
            $grupo = Grupo::findOrFail($request->grupo_id);

            // Find or create docente
            $docente = Docente::withTrashed()->where('ci', $request->ci)->first();

            if (!$docente) {
                // Check if a user with this CI exists
                $user = User::where('username', $request->ci)->first();

                if (!$user) {
                    $docenteRoleId = Rol::where('codigo', 'DOCENTE')->value('id') ?? 6;
                    $parts    = explode(' ', $request->nombre, 2);
                    $nombre   = $parts[0] ?? $request->nombre;
                    $apellido = $parts[1] ?? 'Apellido';

                    $user = User::create([
                        'email'                    => strtolower($request->ci) . '@unitepc.edu.bo',
                        'username'                 => $request->ci,
                        'password'                 => Hash::make($request->ci),
                        'rol_id'                   => $docenteRoleId,
                        'estado'                   => 1,
                        'password_change_required' => false,
                        'nombre'                   => $nombre,
                        'apellido'                 => $apellido,
                        'ci'                       => $request->ci,
                        'carrera'                  => '',
                        'telefono'                 => ''
                    ]);
                }

                $docente = Docente::create([
                    'ci'             => $request->ci,
                    'nombre_completo'=> $request->nombre,
                    'user_id'        => $user->id,
                    'sede_id'        => $grupo->sede_id
                ]);

            } else {
                // Update name and restore if soft-deleted
                $docente->nombre_completo = $request->nombre;
                $docente->save();

                if ($docente->trashed()) {
                    $docente->restore();
                }
            }

            // Assign docente to group
            $grupo->docente_id = $docente->id;
            $grupo->save();

            return response()->json([
                'message' => 'Docente actualizado correctamente',
                'docente' => [
                    'id'     => $docente->id,
                    'nombre' => $docente->nombre_completo,
                    'ci'     => $docente->ci,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Manual Registration UpdateDocente Error: ' . $e->getMessage());
            return response()->json(['error' => 'Error al actualizar docente: ' . $e->getMessage()], 500);
        }
    }
}
