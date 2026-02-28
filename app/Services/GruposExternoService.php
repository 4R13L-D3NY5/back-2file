<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Asignatura;
use App\Models\Carrera;
use App\Models\Grupo;
use App\Models\Sede;

class GruposExternoService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.grupos_api.url', 'http://181.188.185.211:9098');
    }

    public function listarGrupos(string $gestion, string $carrera, int $sede): array
    {
        $cacheKey = "grupos_externos_{$gestion}_{$carrera}_{$sede}_local";

        return Cache::remember($cacheKey, 300, function () use ($gestion, $carrera, $sede) {
            try {
                // Find Carrera ID by code (sigla)
                $carreraModel = Carrera::where('sigla', $carrera)->first();
                if (!$carreraModel) {
                    Log::warning("GruposExternoService (Local): Carrera '$carrera' not found");
                    return [];
                }

                $sedeModel = Sede::find($sede);
                $sedeNombre = $sedeModel ? $sedeModel->nombre : 'Desconocida';

                // Query local Database instead of API
                $grupos = Grupo::with([
                    'asignatura',
                    'docente',
                    'horarios.aula.bloque',
                    'carrera',
                    'sede'
                ])
                ->where('gestion', $gestion)
                ->where('carrera_id', $carreraModel->id)
                ->where('sede_id', $sede)
                ->where('estado', 'ACTIVO')
                ->get();

                return $this->transformarDatosLocal($grupos, $carrera, $gestion, $sede, $sedeNombre);

            } catch (\Exception $e) {
                Log::error('GruposExternoService (Local): Error fetching data', [
                    'error' => $e->getMessage()
                ]);
                return [];
            }
        });
    }

    /**
     * Limpiar cache de grupos
     */
    public function limpiarCache(string $gestion, string $carrera, int $sede): void
    {
        $cacheKey = "grupos_externos_{$gestion}_{$carrera}_{$sede}_local";
        Cache::forget($cacheKey);
    }

    /**
     * Transformar datos de DB a formato estructurado idéntico a la API externa
     */
    protected function transformarDatosLocal($grupos, $carreraSigla, $gestion, $sedeId, $sedeNombre): array
    {
        $materias = [];

        foreach ($grupos as $grupo) {
            $asignatura = $grupo->asignatura;

            // Encontrar el semestre desde el pivot si existe, o usar un default si no
            // Como la consulta base es por carrera, buscamos ese pivot
            $semestre = 1;
            if ($asignatura) {
                $pivotData = $asignatura->carreras()->where('carrera_id', $grupo->carrera_id)->first();
                if ($pivotData && $pivotData->pivot) {
                    $semestre = $pivotData->pivot->semestre;
                }
            }

            $materiaKey = ($asignatura ? trim($asignatura->codigo) : 'UNDEF') . '-' . $semestre;

            if (!isset($materias[$materiaKey])) {
                $materias[$materiaKey] = [
                    'codigo' => $asignatura ? trim($asignatura->codigo) : 'UNDEF',
                    'nombre' => $asignatura ? $asignatura->nombre : 'Desconocida',
                    'semestre' => (string)$semestre,
                    'carrera' => strtoupper($carreraSigla),
                    'sede_id' => $sedeId,
                    'sede_nombre' => strtoupper($sedeNombre),
                    'gestion' => trim($gestion),
                    'grupos' => []
                ];
            }

            // Un grupo en la BD local puede tener Múltiples Horarios,
            // mientras que en la API externa cada fila devuelta era un (grupo + 1 horario específico).
            // Para mantener compatibilidad exacta 1 a 1, generaremos una entrada por cada Horario del grupo.
            if ($grupo->horarios && $grupo->horarios->count() > 0) {
                foreach ($grupo->horarios as $horario) {
                    $materias[$materiaKey]['grupos'][] = [
                        'id_horario' => $horario->id_horario_api ?? $horario->id,
                        'grupo' => $grupo->nombre,
                        'tipo_clase' => $grupo->tipo,
                        'docente' => $grupo->docente ? $this->limpiarNombre($grupo->docente->nombre_completo) : 'Sin Asignar',
                        'docente_ci' => $grupo->docente ? $grupo->docente->ci : '',
                        'dia' => $horario->dia,
                        'hora_inicio' => substr($horario->hora_inicio, 0, 5), // '08:00:00' -> '08:00'
                        'hora_fin' => substr($horario->hora_fin, 0, 5),
                        'aula' => $horario->aula ? $horario->aula->nombre : 'No asignada',
                        'bloque' => $horario->aula && $horario->aula->bloque ? $horario->aula->bloque->nombre : '',
                        'capacidad' => $horario->aula ? (int)$horario->aula->capacidad : 0,
                        'pupitres' => $horario->aula && $horario->aula->pupitres ? (int)$horario->aula->pupitres : null,
                        'proyectados' => 0 // Not available in local DB usually
                    ];
                }
            } else {
                // If group has no schedules, still show it but with empty schedule data
                $materias[$materiaKey]['grupos'][] = [
                    'id_horario' => null,
                    'grupo' => $grupo->nombre,
                    'tipo_clase' => $grupo->tipo,
                    'docente' => $grupo->docente ? $this->limpiarNombre($grupo->docente->nombre_completo) : 'Sin Asignar',
                    'docente_ci' => $grupo->docente ? $grupo->docente->ci : '',
                    'dia' => 'POR DEFINIR',
                    'hora_inicio' => '',
                    'hora_fin' => '',
                    'aula' => 'No asignada',
                    'bloque' => '',
                    'capacidad' => 0,
                    'pupitres' => null,
                    'proyectados' => 0
                ];
            }
        }

        // Ordenar por semestre y código
        $resultado = array_values($materias);
        usort($resultado, function ($a, $b) {
            if ($a['semestre'] !== $b['semestre']) {
                return (int)$a['semestre'] - (int)$b['semestre'];
            }
            return strcmp($a['codigo'], $b['codigo']);
        });

        return $resultado;
    }
    private function limpiarNombre($nombre)
    {
        // Lista de prefijos a eliminar (con y sin punto, mayus/minus)
        $prefijos = [
            'Lic.',
            'Ing.',
            'Dr.',
            'Dra.',
            'Msc.',
            'PhD.',
            'Arq.',
            'Abg.',
            'LIC.',
            'ING.',
            'DR.',
            'DRA.',
            'MSC.',
            'PHD.',
            'ARQ.',
            'ABG.',
            'Lic ',
            'Ing ',
            'Dr ',
            'Dra ',
            'Msc ',
            'PhD ',
            'Arq ',
            'Abg '
        ];

        $nombreLimpio = trim($nombre);
        foreach ($prefijos as $prefijo) {
            if (str_starts_with($nombreLimpio, $prefijo)) {
                $nombreLimpio = trim(substr($nombreLimpio, strlen($prefijo)));
            }
        }

        return $nombreLimpio;
    }
}
