<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class CronogramaParserService
{
    public function parseCronograma(UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            
            $startRow = null;
            $startColIndex = 2; // Columna B = 2

            // 1. Encontrar la fila de inicio
            foreach ($sheet->getRowIterator() as $row) {
                $rowIndex = $row->getRowIndex();
                $colString = Coordinate::stringFromColumnIndex($startColIndex);
                $cellB = $sheet->getCell("{$colString}{$rowIndex}")->getValue();
                
                if ($cellB && stripos(trim((string)$cellB), 'SEMANAS') !== false) {
                    // Verificar si la celda "SEMANAS" es parte de un merge (ej: cabecera doble)
                    // Si es así, el inicio de datos debe ser DESPUÉS del merge
                    $headerMergeRange = null;
                    foreach ($sheet->getMergeCells() as $range) {
                        if ($sheet->getCell("{$colString}{$rowIndex}")->isInRange($range)) {
                            $headerMergeRange = $range;
                            break;
                        }
                    }

                    if ($headerMergeRange) {
                        $rangeBounds = Coordinate::rangeBoundaries($headerMergeRange);
                        // [[minCol, minRow], [maxCol, maxRow]]
                        // maxRow es [1][1]
                        $startRow = $rangeBounds[1][1] + 1;
                    } else {
                        $startRow = $rowIndex + 1;
                    }
                    break;
                }
            }

            if (!$startRow) {
                throw new \Exception("No se encontró la etiqueta 'SEMANAS' en la columna B.");
            }

            $sesiones = [];
            $maxRows = 200; 
            $currentRow = $startRow;
            $emptyConsecutive = 0;

            // Obtener todos los rangos fusionados
            $mergeRanges = $sheet->getMergeCells();

            while ($currentRow < ($startRow + $maxRows)) {
                $colBString = Coordinate::stringFromColumnIndex($startColIndex);
                $cellB = $sheet->getCell("{$colBString}{$currentRow}");
                $semanaVal = $cellB->getValue();

                // 2. Control d e cabeceras "UNIDAD"
                if ($semanaVal && stripos(trim((string)$semanaVal), 'UNIDAD') !== false) {
                    $currentRow++;
                    continue; // Saltar fila de unidad
                }

                // Verificar si la celda B está en un rango fusionado
                $isInMerge = false;
                $rangeRows = 1;

                foreach ($mergeRanges as $range) {
                    if ($cellB->isInRange($range)) {
                        $isInMerge = true;
                        $rangeBounds = Coordinate::rangeBoundaries($range);
                        // Estructura detectada: [[minCol, minRow], [maxCol, maxRow]]
                        // minRow está en [0][1], maxRow está en [1][1]
                        $minRow = $rangeBounds[0][1];
                        $maxRow = $rangeBounds[1][1];
                        
                        $rangeRows = ($maxRow - $minRow) + 1;
                        
                        // Fix: Asegurar que leemos el valor de la celda maestra del merge
                        $semanaVal = $sheet->getCell("{$colBString}{$minRow}")->getValue();
                        break;
                    }
                }

                // Limpieza del valor de semana
                // Fix: Extraer solo el número de semana (primeros digitos antes de cualquier otro caracter como / o salto de linea)
                $semanaNum = -1;
                $trimmedVal = trim((string)$semanaVal);
                if (preg_match('/^(\d+)/', $trimmedVal, $matches)) {
                    $semanaNum = (int)$matches[1];
                }
                
                // Loguear para depuración
                if ($trimmedVal !== '') {
                    Log::debug("CronogramaParser: Fila $currentRow valor B='$trimmedVal' -> Semana Detectada: $semanaNum");
                }

                // 3. Limite de Semanas (Removido para permitir Cronograma Total)
                /*
                if ($semanaNum > 6) {
                    break; // Detener parsing despues de la semana 6
                }
                */

                if ($semanaNum > 0) {
                     // Iterar sobre las filas que componen esta "Semana"
                    for ($i = 0; $i < $rangeRows; $i++) {
                        $r = $currentRow + $i;
                        
                        // Col C (Start + 1): Sesiones + Fechas
                        $colCString = Coordinate::stringFromColumnIndex($startColIndex + 1);
                        $sesionRaw = $sheet->getCell("{$colCString}{$r}")->getCalculatedValue(); 
                        
                        // Col E (Start + 3): Titulo Tema
                        $colEString = Coordinate::stringFromColumnIndex($startColIndex + 3);
                        $temaTitulo = $sheet->getCell("{$colEString}{$r}")->getValue();
                        
                        // New Columns Mapping
                        // StartCol = B (2)
                        // F (Conceptual) = Start + 4
                        $colFString = Coordinate::stringFromColumnIndex($startColIndex + 4);
                        $conceptual = $sheet->getCell("{$colFString}{$r}")->getValue();

                        // G (Procedimental) = Start + 5
                        $colGString = Coordinate::stringFromColumnIndex($startColIndex + 5);
                        $procedimental = $sheet->getCell("{$colGString}{$r}")->getValue();

                        // H (Actitudinal) = Start + 6
                        $colHString = Coordinate::stringFromColumnIndex($startColIndex + 6);
                        $actitudinal = $sheet->getCell("{$colHString}{$r}")->getValue();

                        // I (Criterios) = Start + 7
                        $colIString = Coordinate::stringFromColumnIndex($startColIndex + 7);
                        $criterios = $sheet->getCell("{$colIString}{$r}")->getValue();

                         // J (Instrumentos) = Start + 8
                        $colJString = Coordinate::stringFromColumnIndex($startColIndex + 8);
                        $instrumentos = $sheet->getCell("{$colJString}{$r}")->getValue();


                        $fechaStr = null;
                        if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($sheet->getCell("{$colCString}{$r}"))) {
                             $fechaStr = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($sesionRaw)->format('Y-m-d');
                        } else {
                            if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/', (string)$sesionRaw, $matches)) {
                                $dia = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                                $mes = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                                $anio = $matches[3];
                                if (strlen($anio) == 2) $anio = "20" . $anio;
                                $fechaStr = "$anio-$mes-$dia";
                            }
                        }

                        // Contenido Principal = Titulo del Tema
                        $contenidoFull = trim((string)$temaTitulo);

                        if (empty($sesionRaw) && empty($contenidoFull)) {
                             continue;
                        }

                        $sesiones[] = [
                            'semana' => $semanaNum,
                            'fecha' => $fechaStr,
                            'contenido' => $contenidoFull,
                            'contenido_conceptual' => trim((string)$conceptual),
                            'contenido_procedimental' => trim((string)$procedimental),
                            'contenido_actitudinal' => trim((string)$actitudinal),
                            'criterios_desempeno' => trim((string)$criterios),
                            'instrumentos_evaluacion' => trim((string)$instrumentos),
                            'fila_excel' => $r,
                            'raw_sesion' => $sesionRaw
                        ];

                        // Detección de Fila de Examen (Combinada o con texto de examen)
                        // Si el título o el contenido conceptual contienen palabras clave, limpiamos los detalles
                        $keywords = ['EXAMEN', 'PARCIAL', 'FINAL', 'INSTANCIA'];
                        $isExamRow = false;
                        foreach ($keywords as $kw) {
                            if (stripos($contenidoFull, $kw) !== false || stripos((string)$conceptual, $kw) !== false) {
                                $isExamRow = true;
                                break;
                            }
                        }

                        if ($isExamRow) {
                            $lastIdx = count($sesiones) - 1;
                            $sesiones[$lastIdx]['contenido_conceptual'] = '';
                            $sesiones[$lastIdx]['contenido_procedimental'] = '';
                            $sesiones[$lastIdx]['contenido_actitudinal'] = '';
                            $sesiones[$lastIdx]['criterios_desempeno'] = '';
                            // Mantenemos instrumentos_evaluacion o el contenido si es "EXAMEN ..."
                        }
                    }
                }

                $currentRow += $rangeRows;

                if (empty($semanaVal)) {
                     $emptyConsecutive++;
                     if ($emptyConsecutive > 20) break; // Aumentado de 5 a 20 para saltar bloques vacios mas grandes
                } else {
                    $emptyConsecutive = 0;
                }
            }

            // Calcular frecuencia de sesiones por semana
            $sessionsPerWeek = [];
            foreach ($sesiones as $sesion) {
                $week = $sesion['semana'];
                if (!isset($sessionsPerWeek[$week])) {
                    $sessionsPerWeek[$week] = 0;
                }
                $sessionsPerWeek[$week]++;
            }

            // Calcular el modo (la frecuencia más común)
            $maxFrequency = 0;
            if (count($sessionsPerWeek) > 0) {
                $counts = array_count_values($sessionsPerWeek);
                arsort($counts);
                $maxFrequency = array_key_first($counts); 
            }

            return [
                'sesiones' => $sesiones,
                'metadata' => [
                    'start_cell' => "B" . ($startRow - 1),
                    'total_weeks' => count($sessionsPerWeek),
                    'sessions_per_week_mode' => $maxFrequency, 
                    'sessions_counts' => $sessionsPerWeek
                ]
            ];

        } catch (\Exception $e) {
            Log::error("Error parsing Cronograma Excel: " . $e->getMessage());
            throw $e;
        }
    }
}
