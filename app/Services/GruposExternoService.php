<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GruposExternoService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.grupos_api.url', 'http://181.188.185.211:9098');
    }

    /**
     * Listar grupos desde la API externa
     */
    public function listarGrupos(string $gestion, string $carrera, int $sede): array
    {
        $cacheKey = "grupos_externos_{$gestion}_{$carrera}_{$sede}";

        return Cache::remember($cacheKey, 300, function () use ($gestion, $carrera, $sede) {
            try {
                $response = Http::timeout(30)->get("{$this->baseUrl}/api/Grupos/listar/", [
                    'gestion' => $gestion,
                    'carrera' => $carrera,
                    'sede' => $sede
                ]);

                if ($response->successful()) {
                    $rawData = $response->json();
                    return $this->transformarDatos($rawData);
                }

                Log::warning('GruposExternoService: API response not successful', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return [];
            } catch (\Exception $e) {
                Log::error('GruposExternoService: Error fetching data', [
                    'error' => $e->getMessage()
                ]);
                return [];
            }
        });
    }

    /**
     * Listar materias del Plan N (filtradas y aplanadas)
     */
    public function listarMateriasPlanN(string $gestion, string $carrera, int $sede): array
    {
        // Delegar al método genérico con plan N por defecto (retrocompatibilidad)
        return $this->listarMateriasPlan($gestion, $carrera, $sede, 'N');
    }

    /**
     * Listar materias de un plan específico (N o A)
     */
    public function listarMateriasPlan(string $gestion, string $carrera, int $sede, ?string $plan = null): array
    {
        $planKey = $plan ?? 'todos';
        $cacheKey = "grupos_externos_plan_{$planKey}_{$gestion}_{$carrera}_{$sede}";

        return Cache::remember($cacheKey, 300, function () use ($gestion, $carrera, $sede, $plan) {
            try {
                Log::debug('GruposExternoService: Fetching Plan data', [
                    'gestion' => $gestion,
                    'carrera' => $carrera,
                    'sede' => $sede,
                    'plan' => $plan,
                    'baseUrl' => $this->baseUrl
                ]);

                $response = Http::timeout(30)->get("{$this->baseUrl}/api/Grupos/listar/", [
                    'gestion' => $gestion,
                    'carrera' => $carrera,
                    'sede' => $sede
                ]);

                if ($response->successful()) {
                    $rawData = $response->json();
                    Log::debug('GruposExternoService: Raw data count', ['count' => count($rawData)]);
                    $filteredData = $this->transformarMateriasPlan($rawData, $sede, $plan);
                    Log::debug('GruposExternoService: Filtered data count', ['count' => count($filteredData)]);
                    return $filteredData;
                }

                Log::warning('GruposExternoService: API response not successful', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return [];
            } catch (\Exception $e) {
                Log::error('GruposExternoService: Error fetching data', [
                    'error' => $e->getMessage()
                ]);
                return [];
            }
        });
    }

    /**
     * Transformar datos raw a materias de un plan específico (aplanadas)
     */
    protected function transformarMateriasPlan(array $rawData, int $sede, ?string $plan = null): array
    {
        Log::debug('GruposExternoService: Transforming Plan data', [
            'raw_count' => count($rawData),
            'sede_filter' => $sede,
            'plan_filter' => $plan ?? 'todos'
        ]);

        // Filtrar por sede (y por plan solo si se especificó)
        $filteredData = array_filter($rawData, function ($item) use ($sede, $plan) {
            $planEst = $item['planEst'] ?? 'N';
            $idSede = $item['idSede'] ?? null;
            $passesPlan = $plan ? ($planEst === $plan) : true; // sin plan = todos
            $passes = $passesPlan && $idSede == $sede;
            if (!$passes) {
                Log::debug('GruposExternoService: Item filtered out', [
                    'siglaP' => $item['siglaP'] ?? null,
                    'planEst' => $planEst,
                    'idSede' => $idSede,
                    'sede_filter' => $sede,
                    'plan_filter' => $plan
                ]);
            }
            return $passes;
        });

        Log::debug('GruposExternoService: After filtering', ['filtered_count' => count($filteredData)]);

        // Agrupar por materia (sigla + semestre)
        $materias = [];
        $horariosUnicos = [];

        foreach ($filteredData as $item) {
            // Crear un identificador único para evitar duplicados de horario
            $horarioKey = sprintf(
                '%s-%s-%s-%s-%s-%s',
                trim($item['siglaP']),
                $item['grupo'],
                $item['tipoClase'],
                $item['dia'],
                $item['horaInicio'],
                $item['ci']
            );

            if (isset($horariosUnicos[$horarioKey])) {
                continue;
            }
            $horariosUnicos[$horarioKey] = true;

            $materiaKey = trim($item['siglaP']) . '-' . $item['semestre'];

            if (!isset($materias[$materiaKey])) {
                $materias[$materiaKey] = [
                    'codigo' => trim($item['siglaP']),
                    'nombre' => $item['materia'],
                    'semestre' => $item['semestre'],
                    'carrera' => $item['carrera'],
                    'sede_id' => $item['idSede'],
                    'sede_nombre' => $item['nombreSede'],
                    'gestion' => trim($item['gestion']),
                    'plan_estudios' => $item['planEst'] ?? $plan,
                    'docentes_grupos' => [] // array asociativo docente => grupos[]
                ];
            }

            // Agregar docente con grupo
            $docente = $this->limpiarNombre($item['docente']);
            $grupo = $item['grupo'] ?? '';
            if ($docente && $grupo !== '') {
                if (!isset($materias[$materiaKey]['docentes_grupos'][$docente])) {
                    $materias[$materiaKey]['docentes_grupos'][$docente] = [];
                }
                if (!in_array($grupo, $materias[$materiaKey]['docentes_grupos'][$docente])) {
                    $materias[$materiaKey]['docentes_grupos'][$docente][] = $grupo;
                }
            }
        }

        // Convertir a array plano y ordenar
        $resultado = [];
        foreach ($materias as $materia) {
            // Formatear docentes con grupos
            $docentesFormateados = [];
            foreach ($materia['docentes_grupos'] as $docente => $grupos) {
                if (empty($grupos)) {
                    $docentesFormateados[] = $docente;
                } else {
                    $gruposStr = implode(', ', $grupos);
                    $docentesFormateados[] = $docente . ' (' . $gruposStr . ')';
                }
            }
            
            $resultado[] = [
                'codigo' => $materia['codigo'],
                'nombre' => $materia['nombre'],
                'semestre' => $materia['semestre'],
                'carrera' => $materia['carrera'],
                'sede_id' => $materia['sede_id'],
                'sede_nombre' => $materia['sede_nombre'],
                'gestion' => $materia['gestion'],
                'plan_estudios' => $materia['plan_estudios'],
                'docentes' => $docentesFormateados, // array de strings formateados
                'docentes_string' => implode(', ', $docentesFormateados) // compatibilidad
            ];
        }

        // Ordenar por semestre y código
        usort($resultado, function ($a, $b) {
            if ($a['semestre'] !== $b['semestre']) {
                return $a['semestre'] - $b['semestre'];
            }
            return strcmp($a['codigo'], $b['codigo']);
        });

        Log::debug('GruposExternoService: Final result count', ['result_count' => count($resultado)]);

        return $resultado;
    }

    /**
     * Obtener datos detallados de una asignatura específica del Plan N
     */
    public function obtenerAsignaturaDetalle(string $gestion, string $carrera, int $sede, string $codigoAsignatura): ?array
    {
        $data = $this->listarMateriasPlanN($gestion, $carrera, $sede);
        
        Log::debug('GruposExternoService.obtenerAsignaturaDetalle - Buscando asignatura', [
            'gestion' => $gestion,
            'carrera' => $carrera,
            'sede' => $sede,
            'codigo_buscado' => $codigoAsignatura,
            'total_materias' => count($data),
            'codigos_disponibles' => array_map(function ($m) { return $m['codigo']; }, $data)
        ]);
        
        foreach ($data as $materia) {
            if ($materia['codigo'] === $codigoAsignatura) {
                Log::debug('GruposExternoService.obtenerAsignaturaDetalle - Asignatura encontrada', [
                    'codigo' => $materia['codigo'],
                    'nombre' => $materia['nombre']
                ]);
                return $materia;
            }
        }
        
        Log::debug('GruposExternoService.obtenerAsignaturaDetalle - Asignatura NO encontrada');
        return null;
    }

    /**
     * Limpiar cache de grupos
     */
    public function limpiarCache(string $gestion, string $carrera, int $sede): void
    {
        $cacheKey = "grupos_externos_{$gestion}_{$carrera}_{$sede}";
        Cache::forget($cacheKey);
        $cacheKeyPlanN = "grupos_externos_plan_n_{$gestion}_{$carrera}_{$sede}";
        Cache::forget($cacheKeyPlanN);
    }

    /**
     * Transformar datos raw de la API a formato estructurado
     */
    protected function transformarDatos(array $rawData): array
    {
        // Agrupar por materia (sigla + semestre)
        $materias = [];
        $horariosUnicos = [];

        foreach ($rawData as $item) {
            // Requerimiento: omitir asignaturas donde plan_estudios sea null o vacío
            if (empty($item['planEst'])) {
                continue;
            }

            // Crear un identificador único para evitar duplicados
            $horarioKey = sprintf(
                '%s-%s-%s-%s-%s-%s',
                trim($item['siglaP']),
                $item['grupo'],
                $item['tipoClase'],
                $item['dia'],
                $item['horaInicio'],
                $item['ci']
            );

            // Evitar duplicados
            if (isset($horariosUnicos[$horarioKey])) {
                continue;
            }
            $horariosUnicos[$horarioKey] = true;

            $materiaKey = trim($item['siglaP']) . '-' . $item['semestre'];

            if (!isset($materias[$materiaKey])) {
                $materias[$materiaKey] = [
                    'codigo' => trim($item['siglaP']),
                    'nombre' => $item['materia'],
                    'semestre' => $item['semestre'],
                    'carrera' => $item['carrera'],
                    'sede_id' => $item['idSede'],
                    'sede_nombre' => $item['nombreSede'],
                    'gestion' => trim($item['gestion']),
                    'plan_estudios' => $item['planEst'] ?? 'N',
                    'grupos' => []
                ];
            }

            // Agregar horario/grupo
            $materias[$materiaKey]['grupos'][] = [
                'id_horario' => $item['idHorario'],
                'grupo' => $item['grupo'],
                'tipo_clase' => $item['tipoClase'],
                'docente' => $this->limpiarNombre($item['docente']),
                'docente_ci' => $item['ci'],
                'dia' => $item['dia'],
                'hora_inicio' => $item['horaInicio'],
                'hora_fin' => $item['horaFin'],
                'aula' => $item['nomAulaLab'],
                'bloque' => $item['nomBloque'],
                'capacidad' => (int) $item['capacidadAula'],
                'pupitres' => $item['nroPupitres'] ? (int) $item['nroPupitres'] : null,
                'proyectados' => $item['cantProyec']
            ];
        }

        // Ordenar por semestre y código
        $resultado = array_values($materias);
        usort($resultado, function ($a, $b) {
            if ($a['semestre'] !== $b['semestre']) {
                return $a['semestre'] - $b['semestre'];
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
