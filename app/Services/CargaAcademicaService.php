<?php

namespace App\Services;

use App\Models\Asignatura;
use App\Models\Carrera;
use App\Models\Docente;
use App\Models\Grupo;
use App\Models\Horario;
use App\Models\Sede;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CargaAcademicaService
{
    protected PlanningSyncService $planningSyncService;
    protected GruposExternoService $gruposExternoService;

    public function __construct(
        PlanningSyncService $planningSyncService,
        GruposExternoService $gruposExternoService
    ) {
        $this->planningSyncService = $planningSyncService;
        $this->gruposExternoService = $gruposExternoService;
    }

    /**
     * Obtener la carga académica completa para una materia específica
     * en una sede y carrera dadas.
     */
    public function getCargaByMateria(int $sedeId, int $carreraId, int $asignaturaId, ?string $gestion = null): array
    {
        $gestion ??= $this->getGestionActual();

        $asignatura = Asignatura::with([
            'carreras' => fn($q) => $q->where('carreras.id', $carreraId),
            'unidades',
            'grupos' => fn($q) => $q->where('sede_id', $sedeId)
                ->where('carrera_id', $carreraId)
                ->where('gestion', $gestion)
                ->withoutGlobalScope('activo')
                ->with(['docente', 'horarios.aula.bloque']),
        ])->findOrFail($asignaturaId);

        $carrera = Carrera::with('sedes')->findOrFail($carreraId);
        $sede = Sede::findOrFail($sedeId);

        // Extraer semestre del pivot
        $pivot = $asignatura->carreras->first()?->pivot;
        $semestre = $pivot?->semestre ?? null;

        return [
            'sede' => [
                'id' => $sede->id,
                'nombre' => $sede->nombre,
            ],
            'carrera' => [
                'id' => $carrera->id,
                'nombre' => $carrera->nombre,
                'sigla' => $carrera->sigla,
            ],
            'asignatura' => [
                'id' => $asignatura->id,
                'codigo' => $asignatura->codigo,
                'nombre' => $asignatura->nombre,
                'plan_estudios' => $asignatura->plan_estudios,
                'creditos' => $asignatura->creditos,
                'semestre' => $semestre,
                'sesiones_semanales' => $asignatura->sesiones_semanales,
                'estado' => $asignatura->estado,
            ],
            'grupos' => $asignatura->grupos->map(fn($g) => [
                'id' => $g->id,
                'nombre' => $g->nombre,
                'tipo' => $g->tipo,
                'turno' => $g->turno,
                'estado' => $g->estado,
                'gestion' => $g->gestion,
                'plan_estudios' => $g->plan_estudios,
                'modificado_localmente' => $g->modificado_localmente,
                'docente' => $g->docente ? [
                    'id' => $g->docente->id,
                    'nombre_completo' => $g->docente->nombre_completo,
                    'ci' => $g->docente->ci,
                ] : null,
                'horarios' => $g->horarios->map(fn($h) => [
                    'id' => $h->id,
                    'dia' => $h->dia,
                    'hora_inicio' => $h->hora_inicio,
                    'hora_fin' => $h->hora_fin,
                    'aula' => $h->aula ? [
                        'id' => $h->aula->id,
                        'nombre' => $h->aula->nombre,
                        'bloque' => $h->aula->bloque ? $h->aula->bloque->nombre : null,
                    ] : null,
                    'modificado_localmente' => $h->modificado_localmente,
                ])->values(),
            ])->values(),
            'resumen' => [
                'total_grupos' => $asignatura->grupos->count(),
                'grupos_activos' => $asignatura->grupos->where('estado', 'ACTIVO')->count(),
                'grupos_inactivos' => $asignatura->grupos->where('estado', 'INACTIVO')->count(),
                'docentes_unicos' => $asignatura->grupos->pluck('docente_id')->filter()->unique()->count(),
                'sin_docente' => $asignatura->grupos->whereNull('docente_id')->count(),
                'con_cambios_locales' => $asignatura->grupos->where('modificado_localmente', true)->count(),
            ],
        ];
    }

    /**
     * Obtener la carga académica de todas las materias de una carrera en una sede.
     */
    public function getCargaByCarrera(int $sedeId, int $carreraId, ?string $gestion = null): array
    {
        $gestion ??= $this->getGestionActual();

        $carrera = Carrera::with([
            'asignaturas' => fn($q) => $q->whereHas('carreras', fn($sq) => $sq->where('sedes.id', $sedeId)),
        ])->findOrFail($carreraId);

        $sede = Sede::findOrFail($sedeId);

        $materias = [];

        foreach ($carrera->asignaturas as $asignatura) {
            $pivot = $asignatura->pivot ?? null;
            $semestre = $pivot?->semestre ?? null;

            $grupos = Grupo::withoutGlobalScope('activo')
                ->where('asignatura_id', $asignatura->id)
                ->where('carrera_id', $carreraId)
                ->where('sede_id', $sedeId)
                ->where('gestion', $gestion)
                ->with(['docente', 'horarios.aula'])
                ->get();

            $materias[] = [
                'id' => $asignatura->id,
                'codigo' => $asignatura->codigo,
                'nombre' => $asignatura->nombre,
                'plan_estudios' => $asignatura->plan_estudios,
                'semestre' => $semestre,
                'creditos' => $asignatura->creditos,
                'estado' => $asignatura->estado,
                'total_grupos' => $grupos->count(),
                'grupos_activos' => $grupos->where('estado', 'ACTIVO')->count(),
                'docentes_unicos' => $grupos->pluck('docente_id')->filter()->unique()->count(),
                'sin_docente' => $grupos->whereNull('docente_id')->count(),
                'modificado_localmente' => $grupos->contains('modificado_localmente', true),
            ];
        }

        return [
            'sede' => ['id' => $sede->id, 'nombre' => $sede->nombre],
            'carrera' => ['id' => $carrera->id, 'nombre' => $carrera->nombre, 'sigla' => $carrera->sigla],
            'gestion' => $gestion,
            'materias' => $materias,
        ];
    }

    /**
     * Crear un nuevo grupo con sus horarios, validando conflictos.
     */
    public function createGrupoConHorarios(array $data): Grupo
    {
        $validated = validator($data, [
            'nombre' => 'required|string|max:50',
            'asignatura_id' => 'required|integer|exists:asignaturas,id',
            'carrera_id' => 'required|integer|exists:carreras,id',
            'sede_id' => 'required|integer|exists:sedes,id',
            'docente_id' => 'nullable|integer|exists:docentes,id',
            'gestion' => 'required|string|max:20',
            'tipo' => 'required|in:TEORICO,PRACTICO',
            'turno' => 'required|in:MAÑANA,TARDE,NOCHE',
            'estado' => 'nullable|in:ACTIVO,INACTIVO',
            'plan_estudios' => 'nullable|string|max:5',
            'horarios' => 'required|array|min:1',
            'horarios.*.dia' => 'required|in:LUNES,MARTES,MIERCOLES,JUEVES,VIERNES,SABADO,DOMINGO',
            'horarios.*.hora_inicio' => 'required|date_format:H:i',
            'horarios.*.hora_fin' => 'required|date_format:H:i|after:horarios.*.hora_inicio',
            'horarios.*.aula_id' => 'nullable|integer|exists:aulas,id',
        ])->validate();

        return DB::transaction(function () use ($validated) {
            $this->validarDuplicidadGrupo(
                $validated['gestion'],
                $validated['asignatura_id'],
                $validated['carrera_id'],
                $validated['nombre'],
                $validated['tipo'],
                $validated['sede_id']
            );

            $grupo = Grupo::create([
                'nombre' => $validated['nombre'],
                'asignatura_id' => $validated['asignatura_id'],
                'carrera_id' => $validated['carrera_id'],
                'sede_id' => $validated['sede_id'],
                'docente_id' => $validated['docente_id'] ?? null,
                'gestion' => $validated['gestion'],
                'tipo' => $validated['tipo'],
                'turno' => $validated['turno'],
                'estado' => $validated['estado'] ?? 'ACTIVO',
                'plan_estudios' => $validated['plan_estudios'] ?? 'N',
                'modificado_localmente' => true,
            ]);

            foreach ($validated['horarios'] as $horarioData) {
                $this->validarConflictoHorario(
                    $validated['docente_id'] ?? null,
                    $horarioData['aula_id'] ?? null,
                    $horarioData['dia'],
                    $horarioData['hora_inicio'],
                    $horarioData['hora_fin'],
                    null,
                    $grupo->id
                );

                Horario::create([
                    'grupo_id' => $grupo->id,
                    'aula_id' => $horarioData['aula_id'] ?? null,
                    'dia' => $horarioData['dia'],
                    'hora_inicio' => $horarioData['hora_inicio'],
                    'hora_fin' => $horarioData['hora_fin'],
                    'modificado_localmente' => true,
                ]);
            }

            return $grupo->load(['docente', 'horarios.aula.bloque']);
        });
    }

    /**
     * Actualizar grupo y sus horarios.
     */
    public function updateGrupoConHorarios(int $grupoId, array $data): Grupo
    {
        $grupo = Grupo::withoutGlobalScope('activo')->findOrFail($grupoId);

        $validated = validator($data, [
            'nombre' => 'sometimes|string|max:50',
            'docente_id' => 'nullable|integer|exists:docentes,id',
            'tipo' => 'sometimes|in:TEORICO,PRACTICO',
            'turno' => 'sometimes|in:MAÑANA,TARDE,NOCHE',
            'estado' => 'sometimes|in:ACTIVO,INACTIVO',
            'horarios' => 'sometimes|array',
            'horarios.*.id' => 'nullable|integer|exists:horarios,id',
            'horarios.*.dia' => 'required_with:horarios|in:LUNES,MARTES,MIERCOLES,JUEVES,VIERNES,SABADO,DOMINGO',
            'horarios.*.hora_inicio' => 'required_with:horarios|date_format:H:i',
            'horarios.*.hora_fin' => 'required_with:horarios|date_format:H:i|after:horarios.*.hora_inicio',
            'horarios.*.aula_id' => 'nullable|integer|exists:aulas,id',
        ])->validate();

        return DB::transaction(function () use ($grupo, $validated) {
            if (isset($validated['nombre'])) {
                $this->validarDuplicidadGrupo(
                    $grupo->gestion,
                    $grupo->asignatura_id,
                    $grupo->carrera_id,
                    $validated['nombre'],
                    $validated['tipo'] ?? $grupo->tipo,
                    $grupo->sede_id,
                    $grupo->id
                );
            }

            $grupo->update(array_merge($validated, ['modificado_localmente' => true]));

            if (isset($validated['horarios'])) {
                $idsRecibidos = collect($validated['horarios'])->pluck('id')->filter()->values();

                // Eliminar horarios que ya no vienen
                $grupo->horarios()->whereNotIn('id', $idsRecibidos)->delete();

                foreach ($validated['horarios'] as $horarioData) {
                    $this->validarConflictoHorario(
                        $validated['docente_id'] ?? $grupo->docente_id,
                        $horarioData['aula_id'] ?? null,
                        $horarioData['dia'],
                        $horarioData['hora_inicio'],
                        $horarioData['hora_fin'],
                        $horarioData['id'] ?? null,
                        $grupo->id
                    );

                    if (!empty($horarioData['id'])) {
                        Horario::where('id', $horarioData['id'])->update([
                            'aula_id' => $horarioData['aula_id'] ?? null,
                            'dia' => $horarioData['dia'],
                            'hora_inicio' => $horarioData['hora_inicio'],
                            'hora_fin' => $horarioData['hora_fin'],
                            'modificado_localmente' => true,
                        ]);
                    } else {
                        Horario::create([
                            'grupo_id' => $grupo->id,
                            'aula_id' => $horarioData['aula_id'] ?? null,
                            'dia' => $horarioData['dia'],
                            'hora_inicio' => $horarioData['hora_inicio'],
                            'hora_fin' => $horarioData['hora_fin'],
                            'modificado_localmente' => true,
                        ]);
                    }
                }
            }

            return $grupo->fresh(['docente', 'horarios.aula.bloque']);
        });
    }

    /**
     * Reasignar docente a un grupo existente.
     */
    public function assignDocente(int $grupoId, ?int $docenteId): Grupo
    {
        $grupo = Grupo::withoutGlobalScope('activo')->findOrFail($grupoId);

        if ($docenteId) {
            Docente::findOrFail($docenteId);

            // Validar conflictos con los horarios actuales del grupo
            foreach ($grupo->horarios as $horario) {
                $this->validarConflictoHorario(
                    $docenteId,
                    $horario->aula_id,
                    $horario->dia,
                    $horario->hora_inicio,
                    $horario->hora_fin,
                    $horario->id,
                    $grupo->id
                );
            }
        }

        $grupo->update([
            'docente_id' => $docenteId,
            'modificado_localmente' => true,
        ]);

        return $grupo->fresh(['docente', 'horarios.aula.bloque']);
    }

    /**
     * Validar conflictos sin guardar.
     */
    public function validarConflictos(array $data): array
    {
        $docenteId = $data['docente_id'] ?? null;
        $aulaId = $data['aula_id'] ?? null;
        $dia = $data['dia'] ?? null;
        $horaInicio = $data['hora_inicio'] ?? null;
        $horaFin = $data['hora_fin'] ?? null;
        $excludeHorarioId = $data['exclude_horario_id'] ?? null;
        $excludeGrupoId = $data['exclude_grupo_id'] ?? null;

        $conflictos = [];

        if ($docenteId && $dia && $horaInicio && $horaFin) {
            $conflictoDocente = $this->detectarConflictoDocente(
                $docenteId, $dia, $horaInicio, $horaFin, $excludeHorarioId, $excludeGrupoId
            );
            if ($conflictoDocente) {
                $conflictos[] = [
                    'tipo' => 'docente',
                    'severidad' => 'error',
                    'mensaje' => "El docente ya tiene asignado el grupo '{$conflictoDocente['grupo']}' ({$conflictoDocente['materia']}) el {$dia} de {$conflictoDocente['hora_inicio']} a {$conflictoDocente['hora_fin']}.",
                    'detalle' => $conflictoDocente,
                ];
            }
        }

        if ($aulaId && $dia && $horaInicio && $horaFin) {
            $conflictoAula = $this->detectarConflictoAula(
                $aulaId, $dia, $horaInicio, $horaFin, $excludeHorarioId, $excludeGrupoId
            );
            if ($conflictoAula) {
                $conflictos[] = [
                    'tipo' => 'aula',
                    'severidad' => 'warning',
                    'mensaje' => "El aula ya está ocupada por el grupo '{$conflictoAula['grupo']}' ({$conflictoAula['materia']}) el {$dia} de {$conflictoAula['hora_inicio']} a {$conflictoAula['hora_fin']}.",
                    'detalle' => $conflictoAula,
                ];
            }
        }

        return [
            'valido' => empty($conflictos),
            'conflictos' => $conflictos,
        ];
    }

    /**
     * Sincronización granular: una materia específica en sede+carrera.
     */
    public function sincronizarMateria(int $sedeId, int $carreraId, int $asignaturaId, string $gestion): array
    {
        $asignatura = Asignatura::findOrFail($asignaturaId);
        $carrera = Carrera::findOrFail($carreraId);
        $sede = Sede::findOrFail($sedeId);

        // 1. Limpiar cache para forzar fetch fresco
        $this->gruposExternoService->limpiarCache($gestion, $carrera->sigla, $sede->id);

        // 2. Obtener datos RAW de la API externa (no transformados)
        $apiUrl = config('services.grupos_api.url', 'http://181.188.185.211:9098') . '/api/Grupos/listar/';
        $response = Http::timeout(60)->get($apiUrl, [
            'gestion' => $gestion,
            'sede'    => $sede->id,
            'carrera' => $carrera->sigla,
        ]);

        if (!$response->successful()) {
            return [
                'ok' => false,
                'mensaje' => 'Error al consultar la API externa: ' . $response->status(),
                'stats' => [],
            ];
        }

        $rawData = $response->json();

        if (!is_array($rawData) || empty($rawData)) {
            return [
                'ok' => false,
                'mensaje' => 'No se obtuvieron datos de la API externa para esta carrera/sede.',
                'stats' => [],
            ];
        }

        // 3. Filtrar solo los items de la materia específica
        $itemsFiltrados = array_filter($rawData, function ($item) use ($asignatura) {
            return isset($item['siglaP']) && strtoupper(trim($item['siglaP'])) === strtoupper($asignatura->codigo);
        });

        if (empty($itemsFiltrados)) {
            return [
                'ok' => false,
                'mensaje' => "La materia {$asignatura->codigo} no fue encontrada en la API externa.",
                'stats' => [],
            ];
        }

        // 4. Ejecutar syncBatch con los items filtrados
        $stats = $this->planningSyncService->syncBatch(array_values($itemsFiltrados));

        // 5. Obtener snapshot post-sync para comparar
        $gruposPostSync = Grupo::withoutGlobalScope('activo')
            ->where('asignatura_id', $asignatura->id)
            ->where('carrera_id', $carrera->id)
            ->where('sede_id', $sede->id)
            ->where('gestion', $gestion)
            ->with(['docente', 'horarios.aula'])
            ->get();

        return [
            'ok' => true,
            'mensaje' => 'Sincronización completada exitosamente.',
            'stats' => $stats,
            'grupos_sincronizados' => $gruposPostSync->count(),
            'asignatura' => [
                'id' => $asignatura->id,
                'codigo' => $asignatura->codigo,
                'nombre' => $asignatura->nombre,
            ],
        ];
    }

    /* ================================================================
       MÉTODOS PRIVADOS DE VALIDACIÓN
       ================================================================ */

    private function validarDuplicidadGrupo(
        string $gestion,
        int $asignaturaId,
        int $carreraId,
        string $nombre,
        string $tipo,
        int $sedeId,
        ?int $excludeId = null
    ): void {
        $query = Grupo::withoutGlobalScope('activo')->where([
            'gestion' => $gestion,
            'asignatura_id' => $asignaturaId,
            'carrera_id' => $carreraId,
            'nombre' => $nombre,
            'tipo' => $tipo,
            'sede_id' => $sedeId,
        ]);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'nombre' => "Ya existe un grupo '{$nombre}' de tipo {$tipo} para esta materia, carrera y sede en la gestión {$gestion}.",
            ]);
        }
    }

    private function validarConflictoHorario(
        ?int $docenteId,
        ?int $aulaId,
        string $dia,
        string $horaInicio,
        string $horaFin,
        ?int $excludeHorarioId = null,
        ?int $excludeGrupoId = null
    ): void {
        if ($docenteId) {
            $conflicto = $this->detectarConflictoDocente(
                $docenteId, $dia, $horaInicio, $horaFin, $excludeHorarioId, $excludeGrupoId
            );
            if ($conflicto) {
                throw ValidationException::withMessages([
                    'horarios' => "Conflicto de docente: ya está asignado en '{$conflicto['grupo']}' ({$conflicto['materia']}) el {$dia} de {$conflicto['hora_inicio']} a {$conflicto['hora_fin']}.",
                ]);
            }
        }

        if ($aulaId) {
            $conflicto = $this->detectarConflictoAula(
                $aulaId, $dia, $horaInicio, $horaFin, $excludeHorarioId, $excludeGrupoId
            );
            if ($conflicto) {
                throw ValidationException::withMessages([
                    'horarios' => "Conflicto de aula: '{$conflicto['grupo']}' ({$conflicto['materia']}) ya ocupa este aula el {$dia} de {$conflicto['hora_inicio']} a {$conflicto['hora_fin']}.",
                ]);
            }
        }
    }

    private function detectarConflictoDocente(
        int $docenteId,
        string $dia,
        string $horaInicio,
        string $horaFin,
        ?int $excludeHorarioId = null,
        ?int $excludeGrupoId = null
    ): ?array {
        $query = Horario::whereHas('grupo', function ($q) use ($docenteId, $excludeGrupoId) {
            $q->where('docente_id', $docenteId);
            if ($excludeGrupoId) {
                $q->where('id', '!=', $excludeGrupoId);
            }
        })
            ->where('dia', $dia)
            ->where(function ($q) use ($horaInicio, $horaFin) {
                $q->whereRaw('? < hora_fin AND ? > hora_inicio', [$horaInicio, $horaFin]);
            });

        if ($excludeHorarioId) {
            $query->where('id', '!=', $excludeHorarioId);
        }

        $conflicto = $query->with(['grupo.asignatura'])->first();

        if (!$conflicto) {
            return null;
        }

        return [
            'grupo' => $conflicto->grupo?->nombre,
            'materia' => $conflicto->grupo?->asignatura?->nombre,
            'dia' => $conflicto->dia,
            'hora_inicio' => $conflicto->hora_inicio,
            'hora_fin' => $conflicto->hora_fin,
        ];
    }

    private function detectarConflictoAula(
        int $aulaId,
        string $dia,
        string $horaInicio,
        string $horaFin,
        ?int $excludeHorarioId = null,
        ?int $excludeGrupoId = null
    ): ?array {
        $query = Horario::whereHas('grupo', function ($q) use ($excludeGrupoId) {
            if ($excludeGrupoId) {
                $q->where('id', '!=', $excludeGrupoId);
            }
        })
            ->where('aula_id', $aulaId)
            ->where('dia', $dia)
            ->where(function ($q) use ($horaInicio, $horaFin) {
                $q->whereRaw('? < hora_fin AND ? > hora_inicio', [$horaInicio, $horaFin]);
            });

        if ($excludeHorarioId) {
            $query->where('id', '!=', $excludeHorarioId);
        }

        $conflicto = $query->with(['grupo.asignatura'])->first();

        if (!$conflicto) {
            return null;
        }

        return [
            'grupo' => $conflicto->grupo?->nombre,
            'materia' => $conflicto->grupo?->asignatura?->nombre,
            'dia' => $conflicto->dia,
            'hora_inicio' => $conflicto->hora_inicio,
            'hora_fin' => $conflicto->hora_fin,
        ];
    }

    private function getGestionActual(): string
    {
        $now = now();
        $periodo = $now->month <= 6 ? '1' : '2';
        return "{$periodo}-{$now->year}";
    }
}
