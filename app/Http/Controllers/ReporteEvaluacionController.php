<?php

namespace App\Http\Controllers;

use App\Models\GeneracionManual;
use App\Models\RolExamen;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReporteEvaluacionController extends Controller
{
    private const ESTADOS_OPERATIVOS = [
        'PROGRAMADO' => 'Programado',
        'GENERADO' => 'Generado',
        'IMPRESO' => 'Impreso',
        'ENTREGADO' => 'Entregado',
        'DEVUELTO' => 'Devuelto',
        'REVISADO' => 'Revisado',
        'SUBIDO' => 'Subido',
    ];

    private const ESTADO_ROL_MAP = [
        'programados' => 'PROGRAMADO',
        'generados' => 'GENERADO',
        'impresos' => 'IMPRESO',
        'entregados' => 'ENTREGADO',
        'devueltos' => 'DEVUELTO',
        'recibidos' => 'DEVUELTO',
        'revisados' => 'REVISADO',
        'subidos' => 'SUBIDO',
    ];

    private const ESTADOS_GENERADOS_O_MAS = [
        'GENERADO',
        'IMPRESO',
        'ENTREGADO',
        'DEVUELTO',
        'REVISADO',
        'SUBIDO',
    ];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'gestion' => 'nullable|string|max:20',
            'sede_id' => 'nullable|integer|exists:sedes,id',
            'carrera_id' => 'nullable|integer|exists:carreras,id',
            'parcial' => 'nullable|string|max:50',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'estado' => 'nullable',
            'origen' => 'nullable|in:todos,rol,manual',
        ]);

        [$fechaInicio, $fechaFin] = $this->normalizarRangoFechas(
            $validated['fecha_inicio'] ?? null,
            $validated['fecha_fin'] ?? null
        );

        $filtros = [
            'gestion' => $validated['gestion'] ?? null,
            'sede_id' => $validated['sede_id'] ?? null,
            'carrera_id' => $validated['carrera_id'] ?? null,
            'parcial' => $validated['parcial'] ?? null,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'estados' => $this->normalizarEstadosFiltro($request->input('estado')),
            'origen' => $validated['origen'] ?? 'todos',
        ];

        $rol = $filtros['origen'] === 'manual'
            ? collect()
            : $this->obtenerProgramacionRol($filtros);
        $manuales = $filtros['origen'] === 'rol'
            ? collect()
            : $this->obtenerGeneracionesManuales($filtros);

        return response()->json([
            'filtros' => $filtros,
            'resumen' => $this->construirResumen($rol, $manuales),
            'por_estado' => $this->agruparPorEstado($rol, $manuales),
            'por_fecha' => $this->agruparPorFecha($rol, $manuales),
            'por_sede' => $this->agruparPorCampo($rol, $manuales, 'sede_id', 'sede'),
            'por_carrera' => $this->agruparPorCampo($rol, $manuales, 'carrera_id', 'carrera'),
            'detalle' => $this->construirDetalle($rol, $manuales),
            'meta' => [
                'generado_en' => now()->toISOString(),
                'estados' => self::ESTADOS_OPERATIVOS,
            ],
        ]);
    }

    private function obtenerProgramacionRol(array $filtros): Collection
    {
        $query = RolExamen::query()
            ->leftJoin('sedes', 'rol_examenes.sede_id', '=', 'sedes.id')
            ->leftJoin('carreras', 'rol_examenes.carrera_id', '=', 'carreras.id')
            ->select([
                'rol_examenes.id',
                'rol_examenes.gestion',
                'rol_examenes.sede_id',
                DB::raw('sedes.nombre as sede'),
                'rol_examenes.carrera_id',
                DB::raw('carreras.nombre as carrera'),
                'rol_examenes.materia_codigo',
                'rol_examenes.materia_nombre',
                DB::raw("(
                    SELECT docentes.nombre_completo
                    FROM grupos
                    INNER JOIN asignaturas ON asignaturas.id = grupos.asignatura_id
                    INNER JOIN docentes ON docentes.id = grupos.docente_id
                    WHERE grupos.sede_id = rol_examenes.sede_id
                        AND grupos.carrera_id = rol_examenes.carrera_id
                        AND asignaturas.codigo = rol_examenes.materia_codigo
                        AND grupos.estado = 'ACTIVO'
                        AND grupos.deleted_at IS NULL
                        AND (
                            grupos.nombre = rol_examenes.grupo
                            OR REPLACE(REPLACE(REPLACE(UPPER(grupos.nombre), 'GRUPO ', ''), 'G-', ''), 'G', '') =
                               REPLACE(REPLACE(REPLACE(UPPER(rol_examenes.grupo), 'GRUPO ', ''), 'G-', ''), 'G', '')
                        )
                    ORDER BY docentes.nombre_completo
                    LIMIT 1
                ) as docente"),
                'rol_examenes.tipo_examen',
                'rol_examenes.grupo',
                'rol_examenes.fecha',
                'rol_examenes.hora_inicio',
                'rol_examenes.estado',
                'rol_examenes.created_at',
            ]);

        $this->aplicarFiltrosComunesRol($query, $filtros);

        return $query->get()->map(function ($row) {
            $estado = $this->normalizarEstadoRol($row->estado);

            return [
                'origen' => 'rol',
                'id' => $row->id,
                'gestion' => $row->gestion,
                'sede_id' => $row->sede_id,
                'sede' => $row->sede ?: 'Sin sede',
                'carrera_id' => $row->carrera_id,
                'carrera' => $row->carrera ?: 'Sin carrera',
                'materia_codigo' => $row->materia_codigo,
                'materia_nombre' => $row->materia_nombre,
                'docente' => $row->docente ?: 'Por asignar',
                'parcial' => $row->tipo_examen,
                'grupo' => $row->grupo,
                'fecha' => $this->formatearFecha($row->fecha),
                'hora' => $row->hora_inicio,
                'estado' => $estado,
                'estado_label' => self::ESTADOS_OPERATIVOS[$estado] ?? $estado,
            ];
        });
    }

    private function obtenerGeneracionesManuales(array $filtros): Collection
    {
        $query = GeneracionManual::query()
            ->leftJoin('sedes', 'generaciones_manuales.sede_id', '=', 'sedes.id')
            ->leftJoin('carreras', 'generaciones_manuales.carrera_id', '=', 'carreras.id')
            ->select([
                'generaciones_manuales.id',
                'generaciones_manuales.gestion',
                'generaciones_manuales.sede_id',
                DB::raw('COALESCE(generaciones_manuales.sede_nombre, sedes.nombre) as sede'),
                'generaciones_manuales.carrera_id',
                DB::raw('COALESCE(generaciones_manuales.carrera_nombre, carreras.nombre) as carrera'),
                'generaciones_manuales.asignatura_id',
                'generaciones_manuales.asignatura_nombre',
                'generaciones_manuales.docente_nombre',
                'generaciones_manuales.parcial',
                'generaciones_manuales.grupo',
                'generaciones_manuales.fecha_examen',
                'generaciones_manuales.hora',
                'generaciones_manuales.cant_variantes',
                'generaciones_manuales.estado',
                'generaciones_manuales.archivo_examen',
                'generaciones_manuales.archivo_patron_pdf',
                'generaciones_manuales.archivos_patron_xlsx',
                'generaciones_manuales.created_at',
            ]);

        $this->aplicarFiltrosComunesManual($query, $filtros);

        return $query->get()->map(function ($row) {
            $estado = $this->normalizarEstadoManual($row->estado);

            return [
                'origen' => 'manual',
                'id' => $row->id,
                'gestion' => $row->gestion,
                'sede_id' => $row->sede_id,
                'sede' => $row->sede ?: 'Sin sede',
                'carrera_id' => $row->carrera_id,
                'carrera' => $row->carrera ?: 'Sin carrera',
                'asignatura_id' => $row->asignatura_id,
                'materia_codigo' => null,
                'materia_nombre' => $row->asignatura_nombre,
                'docente' => $row->docente_nombre,
                'parcial' => $row->parcial,
                'grupo' => $row->grupo,
                'fecha' => $this->formatearFecha($row->fecha_examen),
                'hora' => $row->hora,
                'variantes' => (int) ($row->cant_variantes ?? 0),
                'estado' => $estado,
                'estado_label' => self::ESTADOS_OPERATIVOS[$estado] ?? $estado,
                'tiene_examen' => ! empty($row->archivo_examen),
                'tiene_patron' => ! empty($row->archivo_patron_pdf) || ! empty($row->archivos_patron_xlsx),
            ];
        });
    }

    private function aplicarFiltrosComunesRol($query, array $filtros): void
    {
        if ($filtros['gestion']) {
            $query->where('rol_examenes.gestion', $filtros['gestion']);
        }
        if ($filtros['sede_id']) {
            $query->where('rol_examenes.sede_id', $filtros['sede_id']);
        }
        if ($filtros['carrera_id']) {
            $query->where('rol_examenes.carrera_id', $filtros['carrera_id']);
        }
        if ($filtros['parcial']) {
            $query->where('rol_examenes.tipo_examen', $filtros['parcial']);
        }
        if ($filtros['fecha_inicio']) {
            $query->whereDate('rol_examenes.fecha', '>=', $filtros['fecha_inicio']);
        }
        if ($filtros['fecha_fin']) {
            $query->whereDate('rol_examenes.fecha', '<=', $filtros['fecha_fin']);
        }
        if (! empty($filtros['estados'])) {
            $rolEstados = collect($filtros['estados'])
                ->map(fn ($estado) => strtolower($estado))
                ->flatMap(fn ($estado) => array_keys(self::ESTADO_ROL_MAP, strtoupper($estado), true))
                ->filter()
                ->values();

            if ($rolEstados->isNotEmpty()) {
                $query->whereIn('rol_examenes.estado', $rolEstados);
            }
        }
    }

    private function aplicarFiltrosComunesManual($query, array $filtros): void
    {
        if ($filtros['gestion']) {
            $query->where(function ($subQuery) use ($filtros) {
                $subQuery
                    ->where('generaciones_manuales.gestion', $filtros['gestion'])
                    ->orWhereNull('generaciones_manuales.gestion')
                    ->orWhere('generaciones_manuales.gestion', '');
            });
        }
        if ($filtros['sede_id']) {
            $query->where('generaciones_manuales.sede_id', $filtros['sede_id']);
        }
        if ($filtros['carrera_id']) {
            $query->where('generaciones_manuales.carrera_id', $filtros['carrera_id']);
        }
        if ($filtros['parcial']) {
            $query->where('generaciones_manuales.parcial', $filtros['parcial']);
        }
        if ($filtros['fecha_inicio']) {
            $query->whereDate('generaciones_manuales.fecha_examen', '>=', $filtros['fecha_inicio']);
        }
        if ($filtros['fecha_fin']) {
            $query->whereDate('generaciones_manuales.fecha_examen', '<=', $filtros['fecha_fin']);
        }
        if (! empty($filtros['estados'])) {
            $query->whereIn('generaciones_manuales.estado', $filtros['estados']);
        }
    }

    private function construirResumen(Collection $rol, Collection $manuales): array
    {
        $totalRol = $rol->count();
        $todos = $rol->concat($manuales);
        $operativos = $todos->filter(fn ($row) => $this->cuentaComoOperativo($row))->count();
        $generados = $todos->filter(fn ($row) => $this->cuentaComoGenerado($row))->count();
        $finalizados = $todos->filter(fn ($row) => $this->cuentaComoFinalizado($row))->count();
        $devueltos = $todos->whereIn('estado', ['DEVUELTO', 'REVISADO', 'SUBIDO'])->count();

        return [
            'programados_rol' => $totalRol,
            'registros_operativos' => $operativos,
            'generados' => $generados,
            'devueltos_o_mas' => $devueltos,
            'finalizados' => $finalizados,
            'pendientes' => max($totalRol - $finalizados, 0),
            'cobertura_generacion' => $totalRol > 0 ? round(($generados / $totalRol) * 100, 2) : 0,
            'avance_finalizacion' => $totalRol > 0 ? round(($finalizados / $totalRol) * 100, 2) : 0,
        ];
    }

    private function agruparPorEstado(Collection $rol, Collection $manuales): array
    {
        return collect(self::ESTADOS_OPERATIVOS)->map(function ($label, $estado) use ($rol, $manuales) {
            return [
                'estado' => $estado,
                'label' => $label,
                'programados' => $rol->where('estado', $estado)->count(),
                'operativos' => $manuales->where('estado', $estado)->count(),
                'total' => $rol->where('estado', $estado)->count() + $manuales->where('estado', $estado)->count(),
            ];
        })->values()->all();
    }

    private function agruparPorFecha(Collection $rol, Collection $manuales): array
    {
        return $rol->concat($manuales)
            ->filter(fn ($row) => ! empty($row['fecha']))
            ->groupBy('fecha')
            ->map(fn ($items, $fecha) => [
                'fecha' => $fecha,
                'programados' => $items->where('origen', 'rol')->count(),
                'operativos' => $items->filter(fn ($row) => $this->cuentaComoOperativo($row))->count(),
                'generados' => $items->filter(fn ($row) => $this->cuentaComoGenerado($row))->count(),
                'finalizados' => $items->filter(fn ($row) => $this->cuentaComoFinalizado($row))->count(),
            ])
            ->sortBy('fecha')
            ->values()
            ->all();
    }

    private function agruparPorCampo(Collection $rol, Collection $manuales, string $idField, string $labelField): array
    {
        return $rol->concat($manuales)
            ->groupBy(fn ($row) => $row[$idField] ?: 'sin_id')
            ->map(function ($items) use ($idField, $labelField) {
                $first = $items->first();
                $programados = $items->where('origen', 'rol')->count();
                $operativos = $items->filter(fn ($row) => $this->cuentaComoOperativo($row))->count();
                $generados = $items->filter(fn ($row) => $this->cuentaComoGenerado($row))->count();
                $finalizados = $items->filter(fn ($row) => $this->cuentaComoFinalizado($row))->count();

                return [
                    'id' => $first[$idField] ?? null,
                    'nombre' => $first[$labelField] ?? 'Sin dato',
                    'programados' => $programados,
                    'operativos' => $operativos,
                    'generados' => $generados,
                    'finalizados' => $finalizados,
                    'avance' => $programados > 0 ? round(($finalizados / $programados) * 100, 2) : 0,
                ];
            })
            ->sortByDesc('programados')
            ->values()
            ->all();
    }

    private function construirDetalle(Collection $rol, Collection $manuales): array
    {
        return $rol->concat($manuales)
            ->sortBy([
                ['fecha', 'desc'],
                ['sede', 'asc'],
                ['carrera', 'asc'],
                ['materia_nombre', 'asc'],
            ])
            ->values()
            ->take(500)
            ->all();
    }

    private function normalizarEstadosFiltro($value): array
    {
        if (empty($value)) {
            return [];
        }

        $items = is_array($value) ? $value : explode(',', (string) $value);

        return collect($items)
            ->map(function ($estado) {
                $key = strtoupper(trim((string) $estado));
                return self::ESTADO_ROL_MAP[strtolower($key)] ?? $key;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizarEstadoRol($estado): string
    {
        $key = strtolower(trim((string) $estado));
        return self::ESTADO_ROL_MAP[$key] ?? strtoupper($key ?: 'PROGRAMADO');
    }

    private function normalizarEstadoManual($estado): string
    {
        $key = strtoupper(trim((string) $estado ?: 'PROGRAMADO'));
        return $key === 'RECIBIDO' ? 'DEVUELTO' : $key;
    }

    private function cuentaComoOperativo(array $row): bool
    {
        return ($row['origen'] ?? null) === 'manual' || ($row['estado'] ?? 'PROGRAMADO') !== 'PROGRAMADO';
    }

    private function cuentaComoGenerado(array $row): bool
    {
        return in_array($row['estado'] ?? null, self::ESTADOS_GENERADOS_O_MAS, true);
    }

    private function cuentaComoFinalizado(array $row): bool
    {
        return ($row['estado'] ?? null) === 'SUBIDO';
    }

    private function normalizarRangoFechas(?string $inicio, ?string $fin): array
    {
        if ($inicio && $fin && $inicio > $fin) {
            return [$fin, $inicio];
        }

        return [$inicio, $fin];
    }

    private function formatearFecha($fecha): ?string
    {
        if (! $fecha) {
            return null;
        }

        return Carbon::parse($fecha)->format('Y-m-d');
    }
}
