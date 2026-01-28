<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use Illuminate\Support\Str;

class ProgramaAnaliticoParser
{
    /**
     * Parses the Word document and extracts schema data.
     * Returns:
     * [
     *   'unidades' => [
     *      [
     *         'numero' => 1,
     *         'titulo' => 'INTRODUCCION',
     *         'temas' => [
     *            ['numero' => 1, 'titulo' => 'DEFINICIONES', 'contenido' => '...']
     *         ]
     *      ]
     *   ],
     *   'bibliografia' => [ ...String lines... ]
     * ]
     */
    public function parse($filePath)
    {
        $phpWord = IOFactory::load($filePath);
        $fullText = '';

        // Extract all text sequentially
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $fullText .= $element->getText() . "\n";
                } elseif (method_exists($element, 'getElements')) {
                    // Handle TextRuns or Table Cells recursively if needed
                    // For now simple text extraction
                    $fullText .= $this->extractTextFromElement($element) . "\n";
                }
            }
        }

        return $this->processTextStructure($fullText);
    }

    private function extractTextFromElement($element)
    {
        $text = '';
        if (method_exists($element, 'getText')) {
            $text .= $element->getText();
        } elseif (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractTextFromElement($child);
            }
        }
        return $text;
    }

    private function processTextStructure($text)
    {
        $lines = explode("\n", $text);
        $unidades = [];
        $bibliografia = [];

        $currentUnidad = null;
        $currentTema = null;
        $inBibliografia = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // 1. Detect Bibliografia Section
            // Updated to be more flexible: BIBLIOGRAFIA or REFERENCIAS
            $upperLine = strtoupper($line);
            if (Str::contains($upperLine, 'BIBLIOGRAF') || Str::contains($upperLine, 'REFERENCIA')) {
                $inBibliografia = true;
                continue;
            }
            if ($inBibliografia) {
                // Heuristic: If line looks like "UNIDAD...", we might have looped back? likely not.
                $bibliografia[] = $line;
                continue;
            }

            // 2. Detect Unidad
            // Pattern: "UNIDAD DE APRENDIZAJE [ROMAN/NUM]: [TITLE]"
            // Updated to allow optional spaces and flexible separators
            if (preg_match('/^UNIDAD DE APRENDIZAJE\s+([IVXLCDM\d]+)\s*[:\.\-]?\s*(.*)/i', $line, $matches)) {

                // Save previous topic description if exists
                if ($currentTema) {
                    $currentUnidad['temas'][] = $currentTema;
                    $currentTema = null;
                }
                // Save previous unidad
                if ($currentUnidad) {
                    $unidades[] = $currentUnidad;
                }

                $currentUnidad = [
                    'numero' => $matches[1], // e.g. "I"
                    'titulo' => trim($matches[2]), // e.g. "INTRODUCCION"
                    'temas' => []
                ];
                continue;
            }

            // 3. Detect Tema
            // Pattern: "TEMA Nº[NUM].- [TITLE]" or "TEMA Nº [NUM]: [TITLE]"
            // Updated to allow multiple separators like ".-" or ":"
            if (preg_match('/^TEMA\s*Nº\s*(\d+)\s*[:\.\-]+\s*(.*)/i', $line, $matches)) {
                // Save previous topic
                if ($currentTema && $currentUnidad) {
                    $currentUnidad['temas'][] = $currentTema;
                }

                $currentTema = [
                    'numero' => $matches[1],
                    'titulo' => trim($matches[2]),
                    'contenido' => ''
                ];
                continue;
            }

            // 4. Content (Append to current Topic)
            if ($currentTema) {
                $currentTema['contenido'] .= $line . " "; // Append with space
            }
        }

        // Catch leftovers
        if ($currentTema && $currentUnidad) {
            $currentUnidad['temas'][] = $currentTema;
        }
        if ($currentUnidad) {
            $unidades[] = $currentUnidad;
        }

        return [
            'unidades' => $unidades,
            'bibliografia' => $bibliografia
        ];
    }
}
