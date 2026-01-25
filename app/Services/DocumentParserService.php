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
        // Regex para Básica/Oficial
        // Captura: "BIBLIOGRAFIA" + espacio opcional + "BASICA" ó "OFICIAL"
        $basicaRegex = '/BIBLIOGRAF[ÍI]A\s*(?:B[ÁA]SICA|OFICIAL)/ui';

        // Regex para Complementaria
        $complRegex = '/BIBLIOGRAF[ÍI]A\s*COMPLEMENTARIA/ui';

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
                $entries[] = trim($line);
            }
        }
        return array_values(array_unique($entries));
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
        $structure = [];

        // Regex para Header de Unidad
        // UNIDAD DE APRENDIZAJE N... o UNIDAD I... o UNIDAD 1...
        $unitRegex = '/UNIDAD\s+(?:DE\s+APRENDIZAJE\s+)?(?:N[º°]?\s*)?([IVX0-9]+)[\.\s:](.*?)(?=UNIDAD\s+(?:DE\s+APRENDIZAJE\s+)?(?:N[º°]?\s*)?[IVX0-9]+|BIBLIOGRAF|METODOLOG|$)/usi';

        preg_match_all($unitRegex, $fullText, $unitMatches, PREG_OFFSET_CAPTURE);

        if (empty($unitMatches[0])) {
            return [];
        }

        foreach ($unitMatches[0] as $index => $match) {
            $unitNumStr = $unitMatches[1][$index][0]; // "I", "1", "IV"
            $unitTitleRaw = $unitMatches[2][$index][0]; // Titulo y contenido

            // Convertir 'IV' a 4
            $unitNum = $this->romanToInt($unitNumStr);
            if ($unitNum == 0) $unitNum = intval($unitNumStr);

            // Separar titulo del contenido
            // Asumimos que el título es la primera linea/frase hasta un salto de linea o "TEMA"
            // Ojo: $unitTitleRaw incluye todo el contenido de la unidad.

            // Limpiar:
            $cleanContent = trim($unitTitleRaw);
            $lines = explode("\n", $cleanContent);

            $firstLine = trim($lines[0] ?? '');
            // Si la primera linea es muy larga, puede ser el titulo.
            // Si hay "TEMA", cortamos antes.

            $unitTitle = $firstLine;
            // Si el titulo tiene "ELEMENTO DE COMPETENCIA", lo quitamos?
            // A veces viene "UNIDAD I. TITULO DE LA UNIDAD"

            $contentBody = substr($cleanContent, strlen($firstLine));

            // Extract temas
            $temas = $this->parseThemes($contentBody);

            $structure[$unitNum] = [
                'titulo' => substr($unitTitle, 0, 250),
                'contenido_raw' => substr($contentBody, 0, 1000), // Para debug
                'temas' => $temas
            ];
        }

        return $structure;
    }

    private function parseThemes(string $unitText): array
    {
        $temas = [];
        // Regex Mejorado:
        // 1. TEMA opcionalmente seguido de N, N°, No, Numero
        // 2. Separadores laxos (espacios, puntos, guiones)
        // 3. Captura titulo
        $themeRegex = '/TEMA\s*(?:N[º°o\.]?\s*)?(\d+)\s*[\.\-:\)]*\s*([^\n\r]+)/ui';

        preg_match_all($themeRegex, $unitText, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return [];
        }

        foreach ($matches[0] as $index => $match) {
            $themeVal   = $matches[1][$index][0];
            $themeTitle = trim($matches[2][$index][0]);

            $startPos = $match[1] + strlen($match[0]);
            $endPos = isset($matches[0][$index + 1])
                ? $matches[0][$index + 1][1]
                : strlen($unitText);

            $content = substr($unitText, $startPos, $endPos - $startPos);
            $content = trim($content);

            $temas[] = [
                'numero_global' => intval($themeVal),
                'titulo' => $themeTitle,
                'contenido' => $content
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
}
