<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class CronogramaParserService
{
    /**
     * Busca la celda que contiene "SEMANAS" en la columna B
     * Retorna la posición de la celda (ej: "B12") o null si no se encuentra.
     */
    public function findSemanasCell(UploadedFile $file): ?string
    {
        try {
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            
            // Iterar sobre la columna B
            // Asumimos una búsqueda razonable hasta la fila 100 para no hacer loop infinito
            foreach ($sheet->getRowIterator() as $row) {
                $rowIndex = $row->getRowIndex();
                $cellValue = $sheet->getCell("B{$rowIndex}")->getValue();
                
                if ($cellValue && stripos(trim((string)$cellValue), 'SEMANAS') !== false) {
                    return "B{$rowIndex}";
                }
            }
            
            return null;

        } catch (\Exception $e) {
            Log::error("Error parsing Cronograma Excel: " . $e->getMessage());
            return null;
        }
    }
}
