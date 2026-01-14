<?php

namespace App\Services;

use App\Models\Asignatura;
use App\Services\University\UniversityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AsignaturaSyncService
{
    protected $universityService;

    public function __construct(UniversityService $universityService)
    {
        $this->universityService = $universityService;
    }

    /**
     * Sincroniza el programa analítico de una asignatura desde la API.
     *
     * @param Asignatura $asignatura
     * @param string $branchCode
     * @param string $careerCode
     * @param bool $force Si es true, sincroniza aunque ya existan datos (sobrescribe/actualiza).
     * @return bool True si se sincronizó correctamente, False si falló.
     */
    public function syncAnalyticalProgram(Asignatura $asignatura, $branchCode, $careerCode, $force = false)
    {
        // Si no forzamos y ya tiene unidades, no hacemos nada (lógica original)
        // Nota: Para sincronización masiva periódica, probablemente querremos usar $force = true
        // o comprobar fechas de actualización. Por ahora mantenemos lógica simple.
        if (!$force && $asignatura->unidades()->count() > 0) {
            return false;
        }

        Log::info("Sync: Iniciando sincronización para {$asignatura->codigo} ($branchCode - $careerCode)");

        $apiData = null;
        try {
            $apiData = $this->universityService->getAnalyticalProgram($asignatura->codigo, $branchCode, $careerCode);
        } catch (\Exception $e) {
            Log::error("Sync: Falló API para {$asignatura->codigo}: " . $e->getMessage());
            return false;
        }

        if (!$apiData) {
            Log::warning("Sync: API retornó datos vacíos para {$asignatura->codigo}");
            return false;
        }

        Log::info("Sync: Datos recibidos para {$asignatura->codigo}", [
            'sections' => count($apiData['sections'] ?? []),
            'biblio' => count($apiData['bibliography'] ?? [])
        ]);

        DB::transaction(function () use ($asignatura, $apiData) {
            // Limpiar datos existentes si es actualización forzada o re-sync
            // Ojo: Si ya tenía notas asociadas a temas, esto sería destructivo.
            // Por ahora asumimos que es seguro borrar para regenerar estructura académica.
            $asignatura->bibliografias()->delete();
            // Para unidades, si borramos cascada los temas.
            $asignatura->unidades()->delete();

            // 1. Unidades y Temas
            if (isset($apiData['sections']) && is_array($apiData['sections'])) {
                foreach ($apiData['sections'] as $section) {
                    $unidad = $asignatura->unidades()->create([
                        'numero' => $section['number'] ?? 0,
                        'titulo' => $section['title'] ?? 'Sin Título',
                        'objetivo' => '',
                        'contenido_minimo' => ''
                    ]);

                    if (isset($section['topics']) && is_array($section['topics'])) {
                        foreach ($section['topics'] as $topic) {
                            $unidad->temas()->create([
                                'titulo' => $topic['title'] ?? 'Tema',
                                'unidad_id' => $unidad->id,
                                'contenido_conceptual' => [
                                    'descripcion' => $topic['description'] ?? ''
                                ],
                                'horas_teoricas' => 0,
                                'horas_practicas' => 0,
                                'estrategias_metodologicas' => '',
                                'estrategias_aprendizaje' => '',
                                'estrategias_recursos' => [],
                                'evaluacion_formativa' => [],
                                'evaluacion_sumativa' => []
                            ]);
                        }
                    }
                }
            }

            // 2. Bibliografía
            if (isset($apiData['bibliography']) && is_array($apiData['bibliography'])) {
                foreach ($apiData['bibliography'] as $bib) {
                    $asignatura->bibliografias()->create([
                        'titulo' => substr($bib['description'] ?? 'Referencia', 0, 250),
                        'tipo' => $bib['type'] ?? 'COMPLEMENTARY',
                        'autor' => 'Ver descripción',
                        'anio' => 0,
                        'editorial' => '',
                        'edicion' => '',
                        'isbn' => '',
                        'paginas' => ''
                    ]);
                }
            }
        });

        return true;
    }
}
