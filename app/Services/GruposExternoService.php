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
     * Limpiar cache de grupos
     */
    public function limpiarCache(string $gestion, string $carrera, int $sede): void
    {
        $cacheKey = "grupos_externos_{$gestion}_{$carrera}_{$sede}";
        Cache::forget($cacheKey);
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
                    'grupos' => []
                ];
            }

            // Agregar horario/grupo
            $materias[$materiaKey]['grupos'][] = [
                'id_horario' => $item['idHorario'],
                'grupo' => $item['grupo'],
                'tipo_clase' => $item['tipoClase'],
                'docente' => $item['docente'],
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
}
