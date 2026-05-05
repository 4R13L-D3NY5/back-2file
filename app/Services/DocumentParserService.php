<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class DocumentParserService
{
    public function parseWord(UploadedFile $file): array
    {
        $phpWord = IOFactory::load($file->getPathname());
        $data = [
            'justificacion' => null,
            'proposito_general' => null,
            'metodologia_general' => null,
            'sistema_evaluacion' => null,
            'contenido_minimo' => null,
            'requisitos' => null,
            'competencia_global_especifica' => null, // CGE separada de competencia_asignatura
            'competencia_asignatura' => null,
            'elementos_competencia' => null, // Texto completo para compatibilidad
            'elementos_competencia_por_unidad' => [], // Array: [1 => 'E.C.1 texto', 2 => 'E.C.2 texto']
            'reglamento_normativa' => null, // Nuevo campo para reglamento
            'organizacion_calendario' => null, // Nuevo campo
            'bibliografia_basica' => [],
            'bibliografia_complementaria' => [],
            // Nuevos Campos
            'creditos' => 0,
            'carga_horaria_total' => 0,
            'horas_teoricas' => 0,
            'horas_practicas' => 0,
            'modalidad' => null,
            'semestre' => null, // Int or Text
            'tipo_curso' => null,
            'area_desempenio' => null
        ];

        // Primero, extraer TODO el texto del documento para poder buscar secciones
        $allText = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                    $allText .= $this->extractAllTextFromTable($element) . "\n\n";
                } else {
                    // Use generic recursive extraction for TextRun, Text, and unknown types (like Links)
                    // This matches the robust logic from ProgramaAnaliticoParser
                    $allText .= $this->extractTextFromElement($element) . "\n";
                }
            }
        }

        Log::info("=== FULL DOCUMENT TEXT (first 2000 chars) ===");
        Log::info(substr($allText, 0, 2000));

        // Normalizar espacios múltiples, tabs y caracteres especiales
        $normalizedText = preg_replace('/[\t]+/', ' ', $allText); // Tabs a espacios
        $normalizedText = preg_replace('/[ ]{2,}/', ' ', $normalizedText); // Espacios múltiples a uno
        $normalizedText = preg_replace('/\x{00A0}/u', ' ', $normalizedText); // Non-breaking spaces

        Log::info("=== NORMALIZED TEXT START ===");
        Log::info(substr($normalizedText, 0, 5000));
        Log::info("=== NORMALIZED TEXT END ===");

        // Ahora buscar secciones por keywords
        $this->extractSectionByKeyword($normalizedText, 'JUSTIFICACION DE LA ASIGNATURA', 'justificacion', $data);
        $this->extractSectionByKeyword($normalizedText, 'JUSTIFICACION', 'justificacion', $data);
        $this->extractSectionByKeyword($normalizedText, 'JUSTIFICACIÓN', 'justificacion', $data);

        $this->extractSectionByKeyword($normalizedText, 'OBJETIVO GENERAL DE LA UNIDAD', 'proposito_general', $data);
        $this->extractSectionByKeyword($normalizedText, 'OBJETIVO GENERAL', 'proposito_general', $data);
        $this->extractSectionByKeyword($normalizedText, 'PROPOSITO GENERAL', 'proposito_general', $data);
        $this->extractSectionByKeyword($normalizedText, 'PROPÓSITO GENERAL', 'proposito_general', $data);
        $this->extractSectionByKeyword($normalizedText, 'PROPOSITO', 'proposito_general', $data);
        $this->extractSectionByKeyword($normalizedText, 'PROPÓSITO', 'proposito_general', $data);

        // Competencia Global Específica - extraer primero a campo separado
        $this->extractSectionByKeyword($normalizedText, 'COMPETENCIA GLOBAL ESPECIFICA', 'competencia_global_especifica', $data);
        $this->extractSectionByKeyword($normalizedText, 'COMPETENCIA GLOBAL ESPECÍFICA', 'competencia_global_especifica', $data);

        // Competencia de la Asignatura - separada de CGE
        $this->extractSectionByKeyword($normalizedText, 'COMPETENCIA DE LA ASIGNATURA', 'competencia_asignatura', $data);

        $this->extractSectionByKeyword($normalizedText, 'ELEMENTOS DE COMPETENCIA', 'elementos_competencia', $data);

        $this->extractSectionByKeyword($normalizedText, 'METODOLOGIA GENERAL', 'metodologia_general', $data);
        $this->extractSectionByKeyword($normalizedText, 'METODOLOGÍA GENERAL', 'metodologia_general', $data);
        $this->extractSectionByKeyword($normalizedText, 'METODOLOGIA DE ENSEÑANZA', 'metodologia_general', $data);
        $this->extractSectionByKeyword($normalizedText, 'METODOLOGIA', 'metodologia_general', $data);
        $this->extractSectionByKeyword($normalizedText, 'METODOLOGÍA', 'metodologia_general', $data);

        $this->extractSectionByKeyword($normalizedText, 'EVALUACION DE LOS APRENDIZAJES', 'sistema_evaluacion', $data);
        $this->extractSectionByKeyword($normalizedText, 'CRITERIOS DE EVALUACION', 'sistema_evaluacion', $data);
        $this->extractSectionByKeyword($normalizedText, 'CRITERIOS DE EVALUACIÓN', 'sistema_evaluacion', $data);
        $this->extractSectionByKeyword($normalizedText, 'SISTEMA DE EVALUACION', 'sistema_evaluacion', $data);
        $this->extractSectionByKeyword($normalizedText, 'SISTEMA DE EVALUACIÓN', 'sistema_evaluacion', $data);

        $this->extractSectionByKeyword($normalizedText, 'CONTENIDOS MINIMOS', 'contenido_minimo', $data);
        $this->extractSectionByKeyword($normalizedText, 'CONTENIDOS MÍNIMOS', 'contenido_minimo', $data);
        $this->extractSectionByKeyword($normalizedText, 'CONTENIDO MINIMO', 'contenido_minimo', $data);

        $this->extractSectionByKeyword($normalizedText, 'REQUISITOS', 'requisitos', $data);

        // Competencia Global Específica
        $this->extractSectionByKeyword($normalizedText, 'COMPETENCIA GLOBAL ESPECIFICA', 'competencia_global_especifica', $data);
        $this->extractSectionByKeyword($normalizedText, 'COMPETENCIA GLOBAL ESPECÍFICA', 'competencia_global_especifica', $data);
        $this->extractSectionByKeyword($normalizedText, 'CGE', 'competencia_global_especifica', $data);

        // Reglamento y Normativa
        $this->extractSectionByKeyword($normalizedText, 'REGLAMENTO Y NORMATIVA', 'reglamento_normativa', $data);
        $this->extractSectionByKeyword($normalizedText, 'NORMAS DE CONVIVENCIA', 'reglamento_normativa', $data);
        $this->extractSectionByKeyword($normalizedText, 'REGLAMENTO', 'reglamento_normativa', $data);

        // Organización y Calendario
        $this->extractSectionByKeyword($normalizedText, 'ORGANIZACION Y CALENDARIO', 'organizacion_calendario', $data);
        $this->extractSectionByKeyword($normalizedText, 'ORGANIZACIÓN Y CALENDARIO', 'organizacion_calendario', $data);

        // EXTRACTION: DATOS GENERALES (Pattern Matching on Full Text)
        // Creditos
        if (preg_match('/(?:Cr[eé]ditos)\s*[:\.]?\s*(\d+)/ui', $normalizedText, $m)) {
            $data['creditos'] = intval($m[1]);
        }

        // Carga Horaria Total
        if (preg_match('/(?:Carga\s*Horaria(?:\s*Total)?)\s*[:\.]?\s*(\d+)/ui', $normalizedText, $m)) {
            $data['carga_horaria_total'] = intval($m[1]);
        }

        // Modalidad
        if (preg_match('/Modalidad\s*[:\.]?\s*([a-z\s]+)(?:[\.;\n]|$)/ui', $normalizedText, $m)) {
            $data['modalidad'] = trim($m[1]);
        }

        // Tipo de Curso
        if (preg_match('/Tipo\s*de\s*Curso\s*[:\.]?\s*([a-z\s]+)(?:[\.;\n]|$)/ui', $normalizedText, $m)) {
            $data['tipo_curso'] = trim($m[1]);
        }

        // Area de Desempeno
        if (preg_match('/[AÁ]rea\s*de\s*Desempe[nñ]o\s*[:\.]?\s*([a-z\s]+)(?:[\.;\n]|$)/ui', $normalizedText, $m)) {
            $data['area_desempenio'] = trim($m[1]);
        }

        // Horas Teoricas / Practicas
        // Pattern: "4 horas teóricas y 6 horas prácticas" OR "Teóricas: 2 ... Prácticas: 2"
        // Try simple precise patterns first
        if (preg_match('/(\d+)\s*horas\s*te[oó]ricas/ui', $normalizedText, $m)) {
            $data['horas_teoricas'] = intval($m[1]);
        }
        if (preg_match('/(\d+)\s*horas\s*pr[aá]cticas/ui', $normalizedText, $m)) {
            $data['horas_practicas'] = intval($m[1]);
        }
        // Fallback: Looking for tables or "Teóricas: X"
        if (preg_match('/Te[oó]ricas\s*[:\.]?\s*(\d+)/ui', $normalizedText, $m)) {
            // Only overwrite if not found above, or maybe this represents "Sesiones Semanales"?
            // Context implies "No de Sesiones Semanales: Teoricas: 2"
            // Let's assume this maps to "Horas Teoricas" if the previous failed, OR map to Sesiones if we add that field.
            // For now, mapping to horas_teoricas if 0.
            if ($data['horas_teoricas'] == 0) $data['horas_teoricas'] = intval($m[1]);
        }
        if (preg_match('/Pr[aá]cticas\s*[:\.]?\s*(\d+)/ui', $normalizedText, $m)) {
            if ($data['horas_practicas'] == 0) $data['horas_practicas'] = intval($m[1]);
        }

        // Semestre
        if (preg_match('/Semestre\s*[:\.]?\s*([a-z\s0-9º°]+)(?:[\.;\n]|$)/ui', $normalizedText, $m)) {
            $semTxt = trim($m[1]);
            $data['semestre'] = $this->parseSemestre($semTxt);
        }

        // Requisitos / Pre-Requisitos
        if (preg_match('/(?:Pre-?requisito[s]?|Requisito[s]?)\s*[:\.]?\s*(.*?)(?:[\n]|$)/ui', $normalizedText, $m)) {
            $data['requisitos'] = trim($m[1]);
        }

        // EXTRA: Intentar capturar secciones de Unidades si están etiquetadas como "PROGRAMA ANALITICO" o "CONTENIDOS ANALITICOS"
        // (DESHABILITADO TEMPORALMENTE: Causaba crash por consumo de memoria/regex en documentos grandes)
        // $this->extractSectionByKeyword($normalizedText, 'PROGRAMA ANALITICO', 'elementos_competencia', $data);
        // ... se confía en parseFullStructure más abajo.

        // NO extraer "Saberes Previos" desde el documento porque puede causar confusión
        // Los saberes previos normalmente son ingresados manualmente

        // Bibliografía - buscar y extraer listas
        $this->extractBibliography($normalizedText, $data);

        // Parsear elementos de competencia individuales por unidad
        $this->parseElementosCompetenciaPorUnidad($data);

        // NUEVO: Parseo Estructural Profundo (Unidades + Temas)
        // Usamos normalizedText (que retiene mayúsculas/minúsculas pero normaliza espacios)
        Log::info("Starting Structural Parse...");
        try {
            $data['estructura_unidades'] = $this->parseFullStructure($normalizedText);
            Log::info("Structural Parse Done. Found " . count($data['estructura_unidades']) . " units.");
        } catch (\Throwable $e) {
            Log::error("Structure Parse Failed: " . $e->getMessage());
            Log::error($e->getTraceAsString());
            $data['estructura_unidades'] = [];
        }

        // Log de lo que se extrajo

        // Log de lo que se extrajo
        Log::info("=== EXTRACTED DATA ===");
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                Log::info("$key: " . count($value) . " items");
            } else {
                Log::info("$key: " . ($value ? substr($value, 0, 100) : 'NULL'));
            }
        }

        return $data;
    }

    private function extractAllTextFromTable($table): string
    {
        $text = '';
        foreach ($table->getRows() as $row) {
            foreach ($row->getCells() as $cell) {
                $cellText = $this->extractTextFromCell($cell);
                if (stripos($cellText, 'Competencia') !== false) {
                    Log::info("Cell with 'Competencia': " . substr($cellText, 0, 100));
                }
                $text .= $cellText . "\n";
            }
        }
        return $text;
    }

    private function extractTextFromCell($cell): string
    {
        $text = '';
        foreach ($cell->getElements() as $element) {
            if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                $text .= $this->extractTextFromElement($element) . "\n";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                $text .= $element->getText() . "\n";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\ListItem) {
                $text .= "• " . $this->extractTextFromElement($element->getTextObject()) . "\n";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                // Tabla anidada
                $text .= $this->extractAllTextFromTable($element) . "\n";
            }
        }
        return trim($text);
    }

    private function extractTextFromElement($element): string
    {
        $text = '';
        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractTextFromElement($child) . ' ';
            }
        } elseif (method_exists($element, 'getText')) {
            $value = $element->getText();
            if (is_object($value)) {
                $text .= $this->extractTextFromElement($value);
            } else {
                $text .= (string) $value;
            }
        }

        return trim($text);
    }

    // extractTextFromTextRun removed/replaced by extractTextFromElement


    protected function extractSectionByKeyword(string $fullText, string $keyword, string $dataKey, array &$data): void
    {
        // Si ya tenemos datos para este key, no sobrescribir
        if (!empty($data[$dataKey])) {
            return;
        }

        $keywordUpper = strtoupper($keyword);
        $textUpper = strtoupper($fullText);

        // Crear patrón regex para espacios flexibles
        $words = preg_split('/\s+/', $keywordUpper);
        $regexWords = array_map(function ($w) {
            if (strpos($w, 'ESPEC') === 0) return 'ESPEC\S*';
            return preg_quote($w, '/');
        }, $words);
        $pattern = '/' . implode('\s+', $regexWords) . '/i';

        if (!preg_match($pattern, $textUpper, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $pos = $matches[0][1];
        $matchedKeyword = $matches[0][0];

        Log::info("Found keyword '$keyword' at position $pos");

        $startPos = $pos + strlen($matchedKeyword);

        // Saltar puntuación inicial
        while ($startPos < strlen($fullText) && in_array($fullText[$startPos], [':', ' ', "\n", "\r", "\t"])) {
            $startPos++;
        }

        // Keywords que terminan sección
        $sectionEndKeywords = [
            'JUSTIFICACION',
            'JUSTIFICACIÓN',
            'PROPOSITO',
            'PROPÓSITO',
            'OBJETIVO GENERAL',
            'COMPETENCIA DE LA ASIGNATURA',
            'COMPETENCIA GLOBAL',
            'ELEMENTOS DE COMPETENCIA',
            'METODOLOGIA',
            'METODOLOGÍA',
            'SISTEMA DE EVALUACION',
            'SISTEMA DE EVALUACIÓN',
            'CONTENIDOS MINIMOS',
            'CONTENIDOS MÍNIMOS',
            'REQUISITOS',
            'BIBLIOGRAFIA',
            'BIBLIOGRAFÍA',
            'UNIDAD 1',
            'UNIDAD 2',
            'UNIDAD 3',
            'UNIDAD 4',
            'UNIDAD 5',
            'SEMANAS',
            'SESIONES',
            'TEMAS',
            'REGLAMENTO',
            'NORMATIVA',
            'ESTRUCTURA DE UNIDADES'
        ];

        $sectionEndKeywords = array_filter($sectionEndKeywords, fn($k) => strtoupper($k) !== $keywordUpper);

        $regexParts = array_map(function ($k) {
            return preg_quote($k, '/');
        }, $sectionEndKeywords);
        $regex = '/\b(' . implode('|', $regexParts) . ')\b/i';

        $endPos = strlen($fullText);
        if (preg_match($regex, $fullText, $matches, PREG_OFFSET_CAPTURE, $startPos + 10)) {
            $endPos = $matches[0][1];
        }

        $content = substr($fullText, $startPos, $endPos - $startPos);
        $content = trim($content);
        $content = $this->cleanExtractedContent($content);

        if ($dataKey === 'requisitos') {
            // Limpieza extra para Requisitos
            $content = preg_replace('/(Créditos|Carga Horaria|Vencido|Horas).*$/m', '', $content);
        }

        if (!empty($content) && strlen($content) > 5) {
            $data[$dataKey] = $content;
            Log::info("Extracted '$dataKey'");
        }
    }

    private function cleanExtractedContent(string $content): string
    {
        // Remover líneas que son solo números o muy cortas
        $lines = explode("\n", $content);
        $cleanedLines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Ignorar líneas vacías, solo números, o muy cortas
            if (empty($line)) continue;
            if (preg_match('/^[\d\.\s:-]+$/', $line)) continue;
            if (strlen($line) < 3) continue;

            // Ignorar líneas que parecen títulos de sección numerados (ej: "12. - ORGANIZACIÓN Y CALENDARIO")
            if (preg_match('/^\d+\.\s*-?\s*(ORGAN|REGLA|METOD|COMP|SISTE|BIBLI|CONTE|JUSTI|OBJET)/i', $line)) continue;

            // Ignorar sufijos de títulos comunes que quedan colgados (ej: "DE LA ASIGNATURA", "DE LA UNIDAD...")
            // Usamos \s* en lugar de solo ^ y $ para ser más permisivos
            if (preg_match('/^\s*(DE LA ASIGNATURA|DE LA UNIDAD DE FORMACIÓN|DE LA UNIDAD DE FORMACION|DE LA COMPETENCIA|DE LA MATERIA)[\.\s]*$/i', $line)) continue;

            $cleanedLines[] = $line;
        }

        return implode("\n", $cleanedLines);
    }

    /**
     * Finds bibliography sections using regex to handle variations like "BIBLIOGRAFÍAOFICIAL" (merged)
     */
    private function extractBibliography(string $fullText, array &$data): void
    {
        // Regex para Básica/Oficial (Más flexible)
        // Captura: "BIBLIOGRAFIA" + espacio opcional + "BASICA" ó "OFICIAL" o simplemente "BIBLIOGRAFIA" si luego se detecta Complementaria
        $basicaRegex = '/BIBLIOGR[ÁA]F[ÍI]A(?:\s+(?:B[ÁA]SICA|OFICIAL))?/ui';

        // Regex para Complementaria
        $complRegex = '/(?:BIBLIOGR[ÁA]F[ÍI]A\s+)?COMPLEMENTARIA/ui';

        // Buscar Básica
        if (preg_match($basicaRegex, $fullText, $matches, PREG_OFFSET_CAPTURE)) {
            $startPos = $matches[0][1] + strlen($matches[0][0]);

            // Buscar fin: Puede ser Compl, o keywords de fin de sección
            $endKeywords = ['BIBLIOGRAFIA COMPLEMENTARIA', 'BIBLIOGRAFÍA COMPLEMENTARIA', 'UNIDAD', 'METODOLOGIA'];

            // Usamos extractBibliographySection lógico
            // Pero primero detectamos si hay "COMPLEMENTARIA" después
            if (preg_match($complRegex, $fullText, $complMatches, PREG_OFFSET_CAPTURE, $startPos)) {
                $endPos = $complMatches[0][1];
            } else {
                $endPos = strlen($fullText);
                // O buscar otros keywords...
            }

            // Extraer bloque
            // Nota: extractBibliographySection necesita lógica custom si pasamos endPos directo.
            // Reutilizaremos la lógica existente pero pasando un subset de texto?
            // Mejor reimplementamos simple aquí

            $content = substr($fullText, $startPos, $endPos - $startPos);
            $items = $this->parseBibliographyItems($content);
            $data['bibliografia_basica'] = array_merge($data['bibliografia_basica'], $items);
            Log::info("Extracted " . count($items) . " basic bibliography items (Regex)");
        }

        // Buscar Complementaria
        if (preg_match($complRegex, $fullText, $matches, PREG_OFFSET_CAPTURE)) {
            $startPos = $matches[0][1] + strlen($matches[0][0]);
            $endKeywords = ['UNIDAD', 'METODOLOGIA', 'SEMANAS', 'ANEXOS'];

            $content = $this->extractSectionUntilKeywords($fullText, $startPos, $endKeywords);
            $items = $this->parseBibliographyItems($content);
            $data['bibliografia_complementaria'] = array_merge($data['bibliografia_complementaria'], $items);
            Log::info("Extracted " . count($items) . " complementary bibliography items (Regex)");
        }
    }

    private function extractSectionUntilKeywords($text, $startPos, $keywords)
    {
        $endPos = strlen($text);
        foreach ($keywords as $k) {
            if (preg_match('/' . preg_quote($k, '/') . '/ui', $text, $m, PREG_OFFSET_CAPTURE, $startPos)) {
                if ($m[0][1] < $endPos) $endPos = $m[0][1];
            }
        }
        return substr($text, $startPos, $endPos - $startPos);
    }

    private function parseBibliographyItems($content)
    {
        $lines = explode("\n", $content);
        $entries = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) > 10 && !preg_match('/^[\d\.\s:-]+$/', $line)) {
                $line = preg_replace('/^[\d\.\)\-•✔]+\s*/u', '', $line); // Added ✔ check
                $line = preg_replace('/\s+([.,;:])/u', '$1', $line);
                $line = preg_replace('/\s{2,}/u', ' ', $line);
                $entries[] = trim($line);
            }
        }
        return array_values($entries);
    }

    private function parseElementosCompetenciaPorUnidad(array &$data): void
    {
        $normalizedText = strtoupper($data['elementos_competencia'] ?? ''); // Fallback

        // Si no hay texto general, usar todo el documento
        // Pero mejor usar lo que ya tenemos.
        // Simularemos busqueda básica

        // Pattern para buscar "UNIDAD X ... ELEMENTO DE COMPETENCIA: ..."
        // Esto es complejo sin el texto completo.
        // Asumiremos que ya se extrajo en 'elementos_competencia_por_unidad' si existiera lógica previa.

        // REIMPLEMENTACION BASICA:
        // Si 'elementos_competencia' tiene "UNIDAD 1: ..."

        // Mejor: parseFullStructure hace el trabajo pesado.
        // Este metodo puede quedar vacio o simple.

        // Dejaremos este metodo como helper para limpiar si fuera necesario,
        // pero la logica principal estará en parseFullStructure.
    }

    public function parseFullStructure(string $fullText): array
    {
        // Usar lógica robusta línea por línea (Portada desde ProgramaAnaliticoParser)
        $lines = explode("\n", $fullText);
        $structure = [];

        $currentUnidad = null;
        $currentTema = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // 1. Detect Bibliografia Section (Ignorar si entra aquí, manejado por extractBibliography separado)
            $upperLine = strtoupper($line);
            if (str_contains($upperLine, 'BIBLIOGRAF') || str_contains($upperLine, 'REFERENCIA')) {
                // Stop parsing structure if we hit bibliography?
                // Usually structure comes before. Let's strictly matching Units/Themes.
            }

            // 2. Detect Unidad
            // Pattern: "UNIDAD DE APRENDIZAJE [ROMAN/NUM]: [TITLE]"
            if (preg_match('/^UNIDAD(?:.*APRENDIZAJE)?\s*(?:N[º°]?\s*)?([IVXLCDM\d]+)\s*[:\.\-]?\s*(.*)/i', $line, $matches)) {

                // Save previous topic
                if ($currentTema && $currentUnidad) {
                    $currentUnidad['temas'][] = $currentTema;
                    $currentTema = null; // Reset tema
                }
                // Save previous unidad
                if ($currentUnidad) {
                    // Use Unit Number as key if numeric/roman?
                    // The Controller expects explicit keys? No, just iterates.
                    // But DocumentParser expects index-based or roman-based keys?
                    // Previous implementation: $structure[$unitNum] = ...

                    // We need to convert roman to int using existing helper
                    $uNumStr = $matches[1];
                    $uNum = $this->romanToInt($uNumStr);
                    if ($uNum == 0) $uNum = intval($uNumStr);

                    $structure[$uNum] = $currentUnidad;
                }

                $uNumStr = $matches[1];
                $uNum = $this->romanToInt($uNumStr);
                if ($uNum == 0) $uNum = intval($uNumStr);

                $currentUnidad = [
                    'titulo' => trim($matches[2]),
                    'contenido_raw' => '',
                    'temas' => []
                ];
                continue;
            }

            // 3. Detect Tema
            // Pattern: "TEMA Nº[NUM].- [TITLE]" or "TEMA Nº [NUM]: [TITLE]"
            if (preg_match('/^TEMA\s*(?:N[º°]?\s*)?(\d+)\s*[:\.\-]+\s*(.*)/i', $line, $matches)) {
                // Save previous topic
                if ($currentTema && $currentUnidad) {
                    $currentUnidad['temas'][] = $currentTema;
                }

                $currentTema = [
                    'numero_global' => intval($matches[1]), // Field expected by Controller/Parser logic
                    'titulo' => trim($matches[2]),
                    'contenido' => ''
                ];
                continue;
            }

            // 4. Content (Append to current Topic OR current Unit)
            if ($currentTema) {
                $currentTema['contenido'] .= $line . " ";
            } else if ($currentUnidad) {
                // Si hay contenido antes del primer tema de la unidad (ej: Elemento de competencia)
                $currentUnidad['contenido_raw'] .= $line . " ";
            }
        }

        // Catch leftovers
        if ($currentTema && $currentUnidad) {
            $currentUnidad['temas'][] = $currentTema;
        }
        if ($currentUnidad) {
            // Need to recover unit number for key?
            // Logic above saves "previous", so we need to save "current" (last one)
            // But we lost the unit number in the loop variable scope if not careful.
            // Let's refactor to save unitNum in currentUnidad struct temporarily or track it.

            // Quick fix: Add 'numero' to $currentUnidad in detection block
        }

        // RE-IMPLEMENTATION TO BE SAFE WITH KEYS:
        return $this->parseFullStructureLineByLine($lines);
    }

    private function parseFullStructureLineByLine(array $lines): array
    {
        $structure = [];
        $currentUnidad = null;
        $currentUnidadNum = 0;
        $currentTema = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if ($this->isBibliographyBoundary($line)) {
                if ($currentTema && $currentUnidad) {
                    $this->finalizeTema($currentTema);
                    $currentUnidad['temas'][] = $currentTema;
                    $currentTema = null;
                }
                if ($currentUnidad) {
                    $structure[$currentUnidadNum] = $currentUnidad;
                }
                break;
            }

            // Debug LOG con encoding check
            // Log::info("Line: " . mb_convert_encoding($line, 'UTF-8', 'UTF-8')); 

            // 2. Detect Unidad
            if (preg_match('/^UNIDAD(?:.*APRENDIZAJE)?\s*(?:N[º°]?\s*)?([IVXLCDM\d]+)\s*[:\.\-]?\s*(.*)/i', $line, $matches)) {

                // Close previous
                if ($currentTema && $currentUnidad) {
                    $this->finalizeTema($currentTema);
                    $currentUnidad['temas'][] = $currentTema;
                    $currentTema = null;
                }
                if ($currentUnidad) {
                    $structure[$currentUnidadNum] = $currentUnidad;
                }

                $uNumStr = $matches[1];
                $currentUnidadNum = $this->romanToInt($uNumStr);
                if ($currentUnidadNum == 0) $currentUnidadNum = intval($uNumStr);

                $currentUnidad = [
                    'titulo' => trim($matches[2]),
                    'contenido_raw' => '',
                    'temas' => []
                ];
                Log::info("MATCH UNIDAD: $line");
                continue;
            }

            // 3. Detect Tema - REGEX REFINADO V3
            // Soporta bullets y division titulo/contenido
            if (preg_match('/^[\•\-\*]?\s*TEMA\s*(?:N(?:[\.º°]|\s)*)?(\d+)\s*[:\.\-\)\s]*\s*(.*)/ui', $line, $matches)) {
                if ($currentTema && $currentUnidad) {
                    $this->finalizeTema($currentTema);
                    $currentUnidad['temas'][] = $currentTema;
                }
                
                $fullTitleLine = trim($matches[2]);
                $realTitle = $fullTitleLine;
                $initialContent = '';

                // Intentar separar Titulo de Contenido si están en la misma línea
                // Heurística: Buscar el primer punto seguido de espacio o fin de linea.
                // Ej: "CONCEPTOS. Concepto y objeto..." -> Titulo: CONCEPTOS, Contenido: Concepto y objeto...
                $dotPos = strpos($fullTitleLine, '.');
                if ($dotPos !== false) {
                    // Validar si vale la pena cortar (que no sea "N." o "Dr." muy corto)
                    $possibleTitle = substr($fullTitleLine, 0, $dotPos);
                    if (strlen($possibleTitle) > 3) {
                         $realTitle = trim($possibleTitle);
                         $initialContent = trim(substr($fullTitleLine, $dotPos + 1));
                    }
                }

                $currentTema = [
                    'numero_global' => intval($matches[1]),
                    'titulo' => $realTitle,
                    'contenido' => $initialContent,
                    'contenido_items' => []
                ];
                Log::info("MATCH TEMA: " . $matches[1] . " - Title: $realTitle");
                continue;
            }

            if ($currentTema) {
                $currentTema['contenido'] .= $line . " ";
            } elseif ($currentUnidad) {
                $currentUnidad['contenido_raw'] .= $line . " ";
            }
        }

        if ($currentTema && $currentUnidad) {
            $this->finalizeTema($currentTema);
            $currentUnidad['temas'][] = $currentTema;
        }
        if ($currentUnidad) {
            $structure[$currentUnidadNum] = $currentUnidad;
        }

        return $structure;
    }

    private function isBibliographyBoundary(string $line): bool
    {
        return preg_match(
            '/^(?:REFERENCIAS?\s+BIBLIOGR[ÁA]FICAS?|BIBLIOGR[ÁA]F[ÍI]A(?:\s+(?:OFICIAL|B[ÁA]SICA|COMPLEMENTARIA))?)\b/ui',
            trim($line)
        ) === 1;
    }

    private function finalizeTema(array &$tema)
    {
        $tema['contenido'] = trim($tema['contenido']);
        // Parsear contenido a items
        $tema['contenido_items'] = $this->parseContentToItems($tema['contenido']);
    }

    private function parseContentToItems(string $content): array
    {
        if (empty($content)) return [];

        // Normalizar separadores a pipe '|'
        // Separadores: saltos de linea, bullets, guiones al inicio, comas, puntos finales (ojo con abreviaciones)
        
        // 1. Reemplazar bullets comunes
        $text = preg_replace('/[•\-\*]\s+/u', '|', $content);

        // 2. Reemplazar saltos de línea reales (si el parser los mantuvo)
        $text = str_replace(["\r\n", "\r", "\n"], '|', $text);

        // 3. Reemplazar comas (la captura 3 muestra uso intensivo de comas para separar)
        // PRECAUCIÓN: No separar números decimales "1,5"
        $text = preg_replace('/,(?!\d)/', '|', $text);

        // 4. Reemplazar puntos, intentando evitar abreviaciones comunes
        $text = preg_replace('/\.\s+/u', '|', $text);
        
        // Explode
        $items = explode('|', $text);
        
        // Limpiar
        $finalItems = [];
        foreach ($items as $item) {
            $cleaned = trim($item);
            // Quitar puntos finales sobrantes
            $cleaned = rtrim($cleaned, '.');
            
            if (mb_strlen($cleaned) > 2) { 
                $finalItems[] = $cleaned;
            }
        }

        return array_values($finalItems);
    }

    private function parseThemes(string $unitText): array
    {
        $temas = [];
        // Regex Mejorado V3 consistente
        $themeRegex = '/[\•\-\*]?\s*TEMA\s*(?:N(?:[\.º°]|\s)*)?(\d+)\s*[:\.\-\)\s]*\s*([^\n\r]+)/ui';

        preg_match_all($themeRegex, $unitText, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return [];
        }

        foreach ($matches[0] as $index => $match) {
            $themeVal   = $matches[1][$index][0];
            $fullTitleLine = trim($matches[2][$index][0]);

            $startPos = $match[1] + strlen($match[0]);
            $endPos = isset($matches[0][$index + 1])
                ? $matches[0][$index + 1][1]
                : strlen($unitText);

            $content = substr($unitText, $startPos, $endPos - $startPos);
            $content = trim($content);

            // Logic to split title/content on same line?
            // parseThemes is fallback mostly. Let's apply similar splitting.
            $realTitle = $fullTitleLine;
            $dotPos = strpos($fullTitleLine, '.');
            if ($dotPos !== false) {
                 $possibleTitle = substr($fullTitleLine, 0, $dotPos);
                 if (strlen($possibleTitle) > 3) {
                      $realTitle = trim($possibleTitle);
                      $extraContent = trim(substr($fullTitleLine, $dotPos + 1));
                      $content = $extraContent . "\n" . $content;
                 }
            }

            $items = $this->parseContentToItems($content);

            $temas[] = [
                'numero_global' => intval($themeVal),
                'titulo' => $realTitle,
                'contenido' => $content,
                'contenido_items' => $items
            ];
        }

        return $temas;
    }

    private function romanToInt($roman)
    {
        $roman = strtoupper($roman);
        $romans = [
            'M' => 1000,
            'CM' => 900,
            'D' => 500,
            'CD' => 400,
            'C' => 100,
            'XC' => 90,
            'L' => 50,
            'XL' => 40,
            'X' => 10,
            'IX' => 9,
            'V' => 5,
            'IV' => 4,
            'I' => 1
        ];

        if (is_numeric($roman)) return intval($roman);

        $result = 0;
        foreach ($romans as $key => $value) {
            while (strpos($roman, $key) === 0) {
                $result += $value;
                $roman = substr($roman, strlen($key));
            }
        }
        return $result;
    }

    private function parseSemestre($text)
    {
        // "Primer semestre", "1er", "6to", "Sexto"
        $text = mb_strtolower($text);
        if (preg_match('/(\d+)/', $text, $m)) return intval($m[1]);

        $map = [
            'primer' => 1,
            'segundo' => 2,
            'tercer' => 3,
            'cuarto' => 4,
            'quinto' => 5,
            'sexto' => 6,
            'septimo' => 7,
            'séptimo' => 7,
            'octavo' => 8,
            'noveno' => 9,
            'decimo' => 10,
            'décimo' => 10
        ];

        foreach ($map as $word => $val) {
            if (str_contains($text, $word)) return $val;
        }
        return 1; // Default fallback
    }
}
