<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Log;

class PlanClaseParserService
{
    public function parse($file)
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        $data = [
            'unidades' => []
        ];
        
        // Default Unit container
        $currentUnidadNum = 1;
        $currentUnidad = [
            'titulo' => "UNIDAD 1", 
            'temas' => []
        ];

        foreach ($rows as $index => $row) {
            // Check Col B (Index 1) for "TEMA X"
            $valB = isset($row[1]) ? trim($row[1]) : '';
            
            if (empty($valB)) continue;

            // Optional: Basic Unit Switch based on Col B "UNIDAD X"
            // Use stricter regex for unit to avoid false positives (e.g. "Unidad de aprendizaje")
            if (stripos($valB, 'UNIDAD') !== false && preg_match('/UNIDAD\s.*?(\d+)/i', $valB, $matches)) {
                 $newNum = intval($matches[1]);
                 if ($newNum !== $currentUnidadNum) { // Only switch if different Number
                     if (!empty($currentUnidad['temas'])) {
                          $data['unidades'][$currentUnidadNum] = $currentUnidad;
                     }
                     $currentUnidadNum = $newNum;
                     $currentUnidad = ['titulo' => $valB, 'temas' => []];
                 }
                 // Do NOT continue here, in case TEMA is on the SAME line (unlikely but safer)
            }

            // DETECT TEMA
            // Relaxed Regex: "Tema" + anything + Digits
            if (preg_match('/tema.*?(\d+)/i', $valB, $matches)) {
                $temaNum = intval($matches[1]);
                
                // DATA target: Next Row (index + 1), Column C (index 2)
                $targetRowIndex = $index + 1; 
                
                // 1. Resultados de Aprendizaje
                $logroVal = '';
                if (isset($rows[$targetRowIndex][2])) {
                    $logroVal = trim($rows[$targetRowIndex][2]);
                    $logroVal = str_ireplace('Resultados de Aprendizaje:', '', $logroVal);
                    $logroVal = trim($logroVal);
                }

                // 2. Logros Esperados (Offset +2) - Multi-line supported
                $logroEsperadoList = [];
                $leIndex = $index + 2;
                if (isset($rows[$leIndex][2])) {
                     $rawLE = trim($rows[$leIndex][2]);
                     $rawLE = str_ireplace(['Logros Esperados:', 'Logros:'], '', $rawLE);
                     // Split by newline
                     $lines = preg_split('/\r\n|\r|\n/', $rawLE);
                     foreach ($lines as $line) {
                         $l = trim($line);
                         if (!empty($l)) {
                             // Remove bullets if any (optional, e.g. "- ", "* ", "1. ")
                             $l = preg_replace('/^[\-\*\•\d\.]+\s+/', '', $l);
                             if (!empty($l)) $logroEsperadoList[] = $l;
                         }
                     }
                }

                // 3. Indicadores (Offset +3) - Multi-line supported
                $indicadorList = [];
                $indIndex = $index + 3;
                if (isset($rows[$indIndex][2])) {
                     $rawInd = trim($rows[$indIndex][2]);
                     $rawInd = str_ireplace(['Indicadores de Logro:', 'Indicadores:', 'Indicador:'], '', $rawInd);
                     // Split by newline
                     $lines = preg_split('/\r\n|\r|\n/', $rawInd);
                     foreach ($lines as $line) {
                         $l = trim($line);
                         if (!empty($l)) {
                             $l = preg_replace('/^[\-\*\•\d\.]+\s+/', '', $l);
                             if (!empty($l)) $indicadorList[] = $l;
                         }
                     }
                }

                // 4. Contenidos - Saber Conceptual (Offset +4, Col D=3)
                $contConceptual = [];
                $idx = $index + 4;
                if (isset($rows[$idx][3])) {
                    $val = trim($rows[$idx][3]);
                    $val = str_ireplace(['Saber Conceptual:', 'Conceptual:'], '', $val);
                    $lines = preg_split('/\r\n|\r|\n/', $val);
                    foreach($lines as $l) if(!empty(trim($l))) $contConceptual[] = trim($l);
                }

                // 5. Contenidos - Saber Actitudinal (Offset +5, Col D=3)
                $contActitudinal = [];
                $idx = $index + 5;
                if (isset($rows[$idx][3])) {
                    $val = trim($rows[$idx][3]);
                    $val = str_ireplace(['Saber Actitudinal:', 'Actitudinal:'], '', $val);
                    $lines = preg_split('/\r\n|\r|\n/', $val);
                    foreach($lines as $l) if(!empty(trim($l))) $contActitudinal[] = trim($l);
                }

                // 6. Estrategias (Offset +8)
                // Metodológicas: Col B=1, Aprendizaje: Col D=3, Recursos: Col G=6
                $estMetodologicas = isset($rows[$index+8][1]) ? trim($rows[$index+8][1]) : '';
                $estAprendizaje   = isset($rows[$index+8][3]) ? trim($rows[$index+8][3]) : '';
                
                $estRecursos = [];
                if (isset($rows[$index+8][6])) {
                    $lines = preg_split('/\r\n|\r|\n/', trim($rows[$index+8][6]));
                    foreach($lines as $l) if(!empty(trim($l))) $estRecursos[] = trim($l);
                }

                // 7. Evaluación Formativa (Offset +11)
                // Actividades: Col C=2, Instrumentos: Col E=4, Evidencias: Col H=7
                $evalFormAct = []; $evalFormInst = []; $evalFormEvid = [];
                $rF = $index + 11;
                
                if (isset($rows[$rF][2])) $evalFormAct  = array_filter(preg_split('/\r\n|\r|\n/', trim($rows[$rF][2])), 'trim');
                if (isset($rows[$rF][4])) $evalFormInst = array_filter(preg_split('/\r\n|\r|\n/', trim($rows[$rF][4])), 'trim');
                if (isset($rows[$rF][7])) $evalFormEvid = array_filter(preg_split('/\r\n|\r|\n/', trim($rows[$rF][7])), 'trim');

                // 8. Evaluación Sumativa (Offset +12)
                $evalSumAct = []; $evalSumInst = []; $evalSumEvid = [];
                $rS = $index + 12;

                if (isset($rows[$rS][2])) $evalSumAct  = array_filter(preg_split('/\r\n|\r|\n/', trim($rows[$rS][2])), 'trim');
                if (isset($rows[$rS][4])) $evalSumInst = array_filter(preg_split('/\r\n|\r|\n/', trim($rows[$rS][4])), 'trim');
                if (isset($rows[$rS][7])) $evalSumEvid = array_filter(preg_split('/\r\n|\r|\n/', trim($rows[$rS][7])), 'trim');

                // 9. Secuencia Didáctica (Offset +15 to +19)
                // Activity: Col C=2, Duracion: Col H=7
                $secuencia = [];
                $momentosDef = [
                    ['offset' => 15, 'nombre' => 'INTRODUCCION'],
                    ['offset' => 16, 'nombre' => 'RESULTADOS DE APRENDIZAJE/LOGROS ESPERADOS'],
                    ['offset' => 17, 'nombre' => 'CONTENIDOS DE LA CLASE'],
                    ['offset' => 18, 'nombre' => 'CUERPO DE CONTENIDOS'],
                    ['offset' => 19, 'nombre' => 'CONCLUSION O CIERRE'],
                ];

                foreach ($momentosDef as $mItem) {
                    $rSeq = $index + $mItem['offset'];
                    $actividad = isset($rows[$rSeq][2]) ? trim($rows[$rSeq][2]) : '';
                    $duracion  = isset($rows[$rSeq][7]) ? intval(trim($rows[$rSeq][7])) : 10;
                    
                    $secuencia[] = [
                        'momento' => $mItem['nombre'],
                        'actividad' => $actividad,
                        'duracion' => $duracion
                    ];
                }

                if ($temaNum > 0) {
                    $currentUnidad['temas'][$temaNum] = [
                        'orden' => $temaNum,
                        'titulo' => "TEMA $temaNum",
                        'logros' => $logroVal,
                        'logros_esperados_list' => $logroEsperadoList,
                        'indicadores_list' => $indicadorList,
                        // NEW FIELDS
                        'contenido_conceptual' => $contConceptual,
                        'contenido_actitudinal' => $contActitudinal,
                        'contenido_procedimental' => [], // Empty by request
                        'estrategias_metodologicas' => $estMetodologicas,
                        'estrategias_aprendizaje' => $estAprendizaje,
                        'estrategias_recursos' => $estRecursos,
                        'evaluacion_formativa' => [
                            'actividades' => array_values($evalFormAct),
                            'instrumentos' => array_values($evalFormInst),
                            'evidencias' => array_values($evalFormEvid)
                        ],
                        'evaluacion_sumativa' => [
                            'actividades' => array_values($evalSumAct),
                            'instrumentos' => array_values($evalSumInst),
                            'evidencias' => array_values($evalSumEvid)
                        ],
                        'secuencia_didactica' => $secuencia,
                        'contenidos' => [],
                        'estrategias' => '',
                        'evaluacion' => '',
                        'contenido_items' => [] 
                    ];
                }
            }
        }
        
        // Save last unit
        if (!empty($currentUnidad['temas'])) {
            $data['unidades'][$currentUnidadNum] = $currentUnidad;
        }

        return $data;
    }
}
