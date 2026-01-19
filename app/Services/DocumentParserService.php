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
            'bibliografia_complementaria' => []
        ];

        // Primero, extraer TODO el texto del documento para poder buscar secciones
        $allText = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                    $allText .= $this->extractAllTextFromTable($element) . "\n\n";
                } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                    $allText .= $this->extractTextFromTextRun($element) . "\n";
                } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                    $allText .= $element->getText() . "\n";
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

        // NO extraer "Saberes Previos" desde el documento porque puede causar confusión
        // Los saberes previos normalmente son ingresados manualmente

        // Bibliografía - buscar y extraer listas
        $this->extractBibliography($normalizedText, $data);

        // Parsear elementos de competencia individuales por unidad
        $this->parseElementosCompetenciaPorUnidad($data);

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
                $text .= $this->extractTextFromTextRun($element) . "\n";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                $text .= $element->getText() . "\n";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\ListItem) {
                $text .= "• " . $this->extractTextFromTextRun($element->getTextObject()) . "\n";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                // Tabla anidada
                $text .= $this->extractAllTextFromTable($element) . "\n";
            }
        }
        return trim($text);
    }

    private function extractTextFromTextRun($textRun): string
    {
        $text = '';
        if (!$textRun) return $text;

        foreach ($textRun->getElements() as $element) {
            if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                $text .= $element->getText() . ' ';
            }
        }
        return trim($text);
    }

    private function extractSectionByKeyword(string $fullText, string $keyword, string $dataKey, array &$data): void
    {
        // Si ya tenemos datos para este key, no sobrescribir
        if (!empty($data[$dataKey])) {
            return;
        }

        $keywordUpper = strtoupper($keyword);
        $textUpper = strtoupper($fullText);

        // Crear patrón regex que permita múltiples espacios/tabs entre palabras del keyword
        // Ejemplo: "COMPETENCIA GLOBAL ESPECIFICA" => "COMPETENCIA\s+GLOBAL\s+ESPECIFICA"
        $words = preg_split('/\s+/', $keywordUpper);
        $regexWords = array_map(function ($w) {
            // Manejar problemas de encoding/acentos en palabra ESPECIFICA
            if (strpos($w, 'ESPEC') === 0) {
                return 'ESPEC\S*';
            }
            return preg_quote($w);
        }, $words);
        $pattern = '/' . implode('\s+', $regexWords) . '/i';

        if (!preg_match($pattern, $textUpper, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $pos = $matches[0][1];
        $matchedKeyword = $matches[0][0];

        Log::info("Found keyword '$keyword' at position $pos (matched: '$matchedKeyword')");

        // Buscar el contenido después del keyword
        // Avanzar hasta después del keyword y cualquier caracter de puntuación/espacios
        $startPos = $pos + strlen($matchedKeyword);

        // Saltar caracteres de formato como ":", espacios, saltos de línea
        while ($startPos < strlen($fullText) && in_array($fullText[$startPos], [':', ' ', "\n", "\r", "\t"])) {
            $startPos++;
        }

        // Encontrar el final de esta sección (hasta el próximo keyword mayor o fin de párrafo largo)
        // Usamos una lista de keywords que indican una nueva sección
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
            'ESTRUCTURA DE UNIDADES DE APRENDIZAJE',
            'ESTRUCTURA DE UNIDADES'
        ];

        // Quitar el keyword actual de la lista de terminadores
        $sectionEndKeywords = array_filter($sectionEndKeywords, fn($k) => strtoupper($k) !== $keywordUpper);

        // Usar regex para encontrar el final, asegurando que sean palabras completas
        // y ignorando el caso.
        $regexParts = array_map(function ($k) {
            return preg_quote($k, '/');
        }, $sectionEndKeywords);
        $regex = '/\b(' . implode('|', $regexParts) . ')\b/i';

        if (preg_match($regex, $fullText, $matches, PREG_OFFSET_CAPTURE, $startPos + 10)) {
            $endPos = $matches[0][1];
        }

        // Extraer el contenido
        $content = substr($fullText, $startPos, $endPos - $startPos);
        $content = trim($content);

        // Limpiar el contenido
        $content = $this->cleanExtractedContent($content);

        // Validación específica para REQUISITOS: evitar metadatos de cabecera
        if ($dataKey === 'requisitos' && (stripos($content, 'Créditos') !== false || stripos($content, 'Carga Horaria') !== false)) {
            $lines = explode("\n", $content);
            $cleanLines = [];
            foreach ($lines as $line) {
                if (stripos($line, 'Créditos') !== false || stripos($line, 'Carga Horaria') !== false || stripos($line, 'Vencido Colegio') !== false || stripos($line, 'Horas teóricas') !== false) {
                    continue;
                }
                $cleanLines[] = $line;
            }
            $content = implode("\n", $cleanLines);
        }

        if (!empty($content) && strlen($content) > 10) {
            $data[$dataKey] = $content;
            Log::info("Extracted '$dataKey': " . substr($content, 0, 100));
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

    private function extractBibliography(string $fullText, array &$data): void
    {
        $textUpper = strtoupper($fullText);

        // Buscar "BIBLIOGRAFIA BASICA" o "BIBLIOGRAFÍA BÁSICA"
        $basicaKeywords = ['BIBLIOGRAFIA BASICA', 'BIBLIOGRAFÍA BÁSICA', 'BIBLIOGRAFIA BÁSICA'];
        $complementariaKeywords = ['BIBLIOGRAFIA COMPLEMENTARIA', 'BIBLIOGRAFÍA COMPLEMENTARIA'];

        foreach ($basicaKeywords as $keyword) {
            $pos = strpos($textUpper, $keyword);
            if ($pos !== false) {
                $content = $this->extractBibliographySection($fullText, $pos + strlen($keyword), $complementariaKeywords);
                if (!empty($content)) {
                    $data['bibliografia_basica'] = array_merge($data['bibliografia_basica'], $content);
                    Log::info("Extracted " . count($content) . " basic bibliography items");
                }
                break;
            }
        }

        foreach ($complementariaKeywords as $keyword) {
            $pos = strpos($textUpper, $keyword);
            if ($pos !== false) {
                $content = $this->extractBibliographySection($fullText, $pos + strlen($keyword), ['UNIDAD', 'METODOLOGIA', 'SEMANAS']);
                if (!empty($content)) {
                    $data['bibliografia_complementaria'] = array_merge($data['bibliografia_complementaria'], $content);
                    Log::info("Extracted " . count($content) . " complementary bibliography items");
                }
                break;
            }
        }
    }

    private function extractBibliographySection(string $fullText, int $startPos, array $endKeywords): array
    {
        $textUpper = strtoupper($fullText);

        // Encontrar el final
        $endPos = strlen($fullText);
        foreach ($endKeywords as $keyword) {
            $pos = strpos($textUpper, strtoupper($keyword), $startPos + 10);
            if ($pos !== false && $pos < $endPos) {
                $endPos = $pos;
            }
        }

        $content = substr($fullText, $startPos, $endPos - $startPos);

        // Parsear líneas como entradas de bibliografía
        $lines = explode("\n", $content);
        $entries = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Una entrada de bibliografía típicamente tiene un autor o título largo
            if (strlen($line) > 20 && !preg_match('/^[\d\.\s:-]+$/', $line)) {
                // Limpiar bullets y numeración
                $line = preg_replace('/^[\d\.\)\-•]+\s*/', '', $line);
                $line = trim($line);
                if (!empty($line)) {
                    $entries[] = $line;
                }
            }
        }

        return array_values(array_unique($entries));
    }

    /**
     * Parsea el texto de elementos de competencia y los distribuye por número de unidad.
     * Busca patrones como "E.C. 1", "E.C.1", "EC 1", "EC1", "ELEMENTO DE COMPETENCIA 1" etc.
     */
    private function parseElementosCompetenciaPorUnidad(array &$data): void
    {
        $texto = $data['elementos_competencia'] ?? '';
        if (empty($texto)) {
            return;
        }

        $elementosPorUnidad = [];

        // Patrón para detectar "E.C. 1", "E.C.1", "EC 1", "EC1", "ELEMENTO DE COMPETENCIA 1"
        $patron = '/(?:E\.?\s*C\.?\s*|ELEMENTO\s*(?:DE\s*)?COMPETENCIA\s*)(\d+)\s*[:\.\-]?\s*/i';

        // Dividir el texto por los patrones de E.C.
        $partes = preg_split($patron, $texto, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        Log::info("Parseando E.C. - partes encontradas: " . count($partes));

        $currentNumber = null;
        foreach ($partes as $parte) {
            $parte = trim($parte);
            if (empty($parte)) continue;

            // Si es un número, es el identificador del E.C.
            if (is_numeric($parte)) {
                $currentNumber = intval($parte);
            } elseif ($currentNumber !== null) {
                // Es el contenido del E.C. actual
                $elementosPorUnidad[$currentNumber] = $parte;
                Log::info("E.C. $currentNumber: " . substr($parte, 0, 50) . "...");
                $currentNumber = null;
            }
        }

        // Si no se encontraron patrones E.C., intentar dividir por saltos de línea numerados
        if (empty($elementosPorUnidad)) {
            $lineas = explode("\n", $texto);
            $numero = 1;
            foreach ($lineas as $linea) {
                $linea = trim($linea);
                if (!empty($linea) && strlen($linea) > 10) {
                    // Limpiar numeración al inicio
                    $linea = preg_replace('/^\d+[\.\)]\s*/', '', $linea);
                    if (!empty(trim($linea))) {
                        $elementosPorUnidad[$numero] = trim($linea);
                        $numero++;
                    }
                }
            }
        }

        $data['elementos_competencia_por_unidad'] = $elementosPorUnidad;
        Log::info("Total E.C. por unidad: " . count($elementosPorUnidad));
    }
}
