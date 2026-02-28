<?php

namespace App\Http\Controllers;

use App\Models\RolExamen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;

class RolExamenController extends Controller
{
    /**
     * Listar exámenes por gestión y carrera
     */
    public function index(Request $request)
    {
        // Start with RolExamen model
        $query = RolExamen::query()->select('rol_examenes.*');

        // Simple conditional clauses
        if ($request->has('gestion')) {
            $query->where('rol_examenes.gestion', $request->gestion);
        }
        if ($request->has('carrera_id')) {
            $carreraId = $request->carrera_id;
            
            // Seguridad: Si es Director, validar que sea su carrera
            $user = auth()->user();
            if ($user && isset($user->rol) && $user->rol->codigo === 'DIRECTOR_CARRERA') {
                $carreraIds = [];
                if ($user->director) {
                    if ($user->director->carrera_id) $carreraIds[] = $user->director->carrera_id;
                    if ($user->director->carreras) $carreraIds = array_merge($carreraIds, $user->director->carreras->pluck('id')->toArray());
                }
                
                if (!in_array($carreraId, array_unique($carreraIds))) {
                    return response()->json(['message' => 'No tiene permiso para ver esta carrera'], 403);
                }
            }
            $query->where('rol_examenes.carrera_id', $carreraId);
        } else {
            // Seguridad: Si es Director, filtrar por sus carreras por defecto
            $user = auth()->user();
            if ($user && isset($user->rol) && $user->rol->codigo === 'DIRECTOR_CARRERA') {
                $carreraIds = [];
                if ($user->director) {
                    if ($user->director->carrera_id) $carreraIds[] = $user->director->carrera_id;
                    if ($user->director->carreras) $carreraIds = array_merge($carreraIds, $user->director->carreras->pluck('id')->toArray());
                }
                
                if (!empty($carreraIds)) {
                    $query->whereIn('rol_examenes.carrera_id', array_unique($carreraIds));
                } else {
                    $query->whereRaw('1 = 0');
                }
            }
        }

        if ($request->has('materia_codigo')) {
            $query->where('rol_examenes.materia_codigo', $request->materia_codigo);
        }

        // Join to get Semestre
        // rol_examenes.materia_codigo -> asignaturas.codigo
        // asignaturas.id -> asignatura_carrera.asignatura_id
        // rol_examenes.carrera_id -> asignatura_carrera.carrera_id
        $query->leftJoin('asignaturas', 'rol_examenes.materia_codigo', '=', 'asignaturas.codigo')
            ->leftJoin('asignatura_carrera', function ($join) {
                $join->on('asignaturas.id', '=', 'asignatura_carrera.asignatura_id')
                    ->on('rol_examenes.carrera_id', '=', 'asignatura_carrera.carrera_id');
            })
            ->addSelect('asignatura_carrera.semestre');

        $examenes = $query->distinct()
            ->orderBy('rol_examenes.semana')
            ->orderBy('rol_examenes.fecha')
            ->orderBy('rol_examenes.hora_inicio')
            ->get();

        return response()->json([
            'data' => $examenes,
            'meta' => [
                'total' => $examenes->count(),
                'gestion' => $request->gestion,
            ]
        ]);
    }

    /**
     * Obtener exámenes de una materia específica
     */
    public function getByMateria(Request $request, $materiaId)
    {
        $gestion = $request->get('gestion', date('Y') . '-I');

        $examenes = RolExamen::where('materia_codigo', $materiaId)
            ->orWhere(function ($q) use ($materiaId) {
                $q->whereRaw('UPPER(materia_codigo) = ?', [strtoupper($materiaId)]);
            })
            ->gestion($gestion)
            ->orderBy('semana')
            ->get();

        return response()->json([
            'data' => $examenes
        ]);
    }

    /**
     * Subir Excel con rol de exámenes (bulk import)
     */
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls|max:5120',
            'carrera_id' => 'required|exists:carreras,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Archivo inválido', 'errors' => $validator->errors()], 422);
        }

        $gestion = $request->get('gestion', date('Y') . '-I');
        $carreraId = $request->get('carrera_id');

        try {
            $file = $request->file('file');
            $spreadsheet = IOFactory::load($file->getPathname());
            
            $sheet = $spreadsheet->getSheetByName('ROL GENERAL');
            if (!$sheet) {
                $sheet = $spreadsheet->getActiveSheet();
            }

            // Obtener el año de la gestión desde la celda B7 (Fila 7, Columna B)
            $gestionAño = 2026; // Default fallback
            $celdaB7 = $sheet->getCell('B7')->getValue();
            if ($celdaB7 && preg_match('/\d{4}/', $celdaB7, $matches)) {
                $gestionAño = $matches[0];
            }

            $rows = $sheet->toArray();

            // Los registros inician en el registro 10 (indice 9)
            $rowsProcessed = array_slice($rows, 9);

            $imported = 0;
            $errors = [];
            $warnings = [];

            DB::beginTransaction();

            foreach ($rowsProcessed as $index => $row) {
                $rowNumber = $index + 10;

                // C: Código Materia (indice 2)
                $codigo = trim($row[2] ?? '');
                
                // E: Grupo (indice 4)
                $grupo = trim($row[4] ?? '');

                if (empty($codigo) || trim(strtoupper($codigo)) === '#REF!') continue;

                // 1. Validar Materia
                $asignatura = \App\Models\Asignatura::where('codigo', $codigo)->first();
                if (!$asignatura) {
                    $errors[] = "Fila {$rowNumber}: No se encontró la materia con código '{$codigo}'";
                    continue;
                }

                // 2. Definir bloques de exámenes a procesar: [Tipo, FechaCol, HoraCol]
                $bloques = [
                    ['1er Parcial', 6, 7],   // G, H
                    ['2do Parcial', 8, 9],   // I, J
                    ['Final', 10, 11]        // K, L
                ];

                foreach ($bloques as $bloque) {
                    [$tipo, $fechaIdx, $horaIdx] = $bloque;
                    
                    $fechaRaw = $row[$fechaIdx] ?? '';
                    $horaRaw = $row[$horaIdx] ?? '';

                    if (empty($fechaRaw) || $fechaRaw === 'A') continue;

                    try {
                        $fecha = $this->parseDate($fechaRaw, $gestionAño);
                        $horaInicio = $this->parseTime($horaRaw);
                        $horaFin = date('H:i', strtotime($horaInicio . ' +90 minutes'));

                        if (!$fecha) continue;

                        // Automatización de semana por defecto
                        $semanasDefault = [
                            '1er Parcial' => 8,
                            '2do Parcial' => 15,
                            'Final' => 19,
                            '2da Instancia' => 22,
                        ];
                        $semana = $semanasDefault[$tipo] ?? 1;

                        // Validar reglas
                        $validation = $this->validateExamRules($carreraId, $codigo, $grupo, $semana, $fecha, $tipo);

                        if (!empty($validation['errors'])) {
                            $errors[] = "Fila {$rowNumber} - Materia {$codigo} ({$tipo}): " . implode(', ', $validation['errors']);
                            continue;
                        }

                        $conflictos = $validation['warnings'] ?? [];
                        $conflictosData = [];
                        foreach ($conflictos as $w) {
                            if (str_contains(strtolower($w), 'semana')) $conflictosData['semana'] = $w;
                            if (str_contains(strtolower($w), 'clase') || str_contains(strtolower($w), 'dia')) $conflictosData['horario'] = $w;
                        }

                        if (!empty($conflictos)) {
                            $warnings[] = "Fila {$rowNumber} - Materia {$codigo} ({$tipo}): " . implode(', ', $conflictos);
                        }

                        // Crear o actualizar
                        RolExamen::updateOrCreate(
                            [
                                'gestion' => $gestion,
                                'carrera_id' => $carreraId,
                                'materia_codigo' => $codigo,
                                'tipo_examen' => $tipo,
                                'grupo' => $grupo ?: null,
                            ],
                            [
                                'materia_nombre' => $asignatura->nombre,
                                'semana' => $semana,
                                'fecha' => $fecha,
                                'hora_inicio' => $horaInicio,
                                'hora_fin' => $horaFin,
                                'created_by' => auth()->id(),
                                'conflictos' => !empty($conflictosData) ? $conflictosData : null,
                            ]
                        );

                        $imported++;

                    } catch (\Exception $e) {
                        $errors[] = "Fila {$rowNumber} ({$tipo}): " . $e->getMessage();
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => "Se procesaron {$imported} registros de exámenes",
                'imported' => $imported,
                'errors' => $errors,
                'warnings' => $warnings,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error crítico procesando archivo: ' . $e->getMessage()
            ], 500);
        }
    }

    private function validateExamRules($carreraId, $codigo, $grupo, $semana, $fecha, $tipo)
    {
        $result = ['errors' => [], 'warnings' => []];

        // 1. Validar Semana vs Tipo (Error Blocking)
        $ranges = [
            '1er Parcial' => [7, 9],
            '2do Parcial' => [14, 16],
            'Final' => [18, 20],
            '2da Instancia' => [21, 25],
        ];

        if (isset($ranges[$tipo])) {
            [$min, $max] = $ranges[$tipo];
            if ($semana < $min || $semana > $max) {
                $result['warnings'][] = "Fuera de semana sugerida (Semanas {$min}-{$max})";
            }
        }

        // 2. Validar Dia de Clase (Warning Non-Blocking)
        // Solo si tenemos fecha y grupo
        if ($fecha && $grupo) {
            $asignatura = \App\Models\Asignatura::where('codigo', $codigo)->first();
            if ($asignatura) {
                // Buscar grupo por nombre vinculado a la asignatura
                // VALIDACION: Solo buscar en grupos TEORICOS (numerales)
                $grupoModel = $asignatura->grupos()
                    ->where('nombre', $grupo)
                    ->where('tipo', 'TEORICO')
                    ->first();

                if ($grupoModel) {
                    $diaExamen = date('N', strtotime($fecha)); // 1 (Mon) - 7 (Sun)

                    // Asumiendo que Horario tiene 'dia' (1-7 o string)
                    // Necesitamos verificar como se guarda 'dia' en Horario.
                    // Generalmente es 1-7 o 'LUNES', etc.
                    // Vamos a asumir 1-7 por ahora o verificar.

                    $diasClaseRaw = $grupoModel->horarios()->pluck('dia')->toArray(); // array of strings e.g. "Lunes", "Miercoles"

                    // Map keys to standard date('N') 1-7
                    $dayMap = [
                        'lunes' => 1,
                        'lun' => 1,
                        'martes' => 2,
                        'mar' => 2,
                        'miercoles' => 3,
                        'miércoles' => 3,
                        'mie' => 3,
                        'mié' => 3,
                        'jueves' => 4,
                        'jue' => 4,
                        'viernes' => 5,
                        'vie' => 5,
                        'sabado' => 6,
                        'sábado' => 6,
                        'sab' => 6,
                        'domingo' => 7,
                        'dom' => 7
                    ];

                    $diasClase = [];
                    foreach ($diasClaseRaw as $dia) {
                        // Simple normalization
                        $key = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], strtolower($dia));
                        if (isset($dayMap[$key])) {
                            $diasClase[] = $dayMap[$key];
                        } elseif (is_numeric($dia)) {
                            $diasClase[] = (int)$dia;
                        }
                    }

                    if (!empty($diasClase) && !in_array($diaExamen, $diasClase)) {
                        $nombresDias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
                        $diaNombre = $nombresDias[$diaExamen] ?? $diaExamen;
                        $result['warnings'][] = "El examen es el {$diaNombre}, pero el grupo no tiene horario teórico ese día.";
                    }
                }
            }
        }

        // 3. Validar Colisión de Exámenes (Mismo Semestre, Misma Carrera, Mismo Día)
        if ($fecha) {
            $asignatura = \App\Models\Asignatura::where('codigo', $codigo)->first();
            if ($asignatura) {
                // Obtener semestre via pivot table
                $pivot = \Illuminate\Support\Facades\DB::table('asignatura_carrera')
                    ->where('asignatura_id', $asignatura->id)
                    ->where('carrera_id', $carreraId)
                    ->first();

                $semestre = $pivot ? $pivot->semestre : null;

                if ($semestre) {
                    $collision = \App\Models\RolExamen::where('carrera_id', $carreraId)
                        ->whereDate('fecha', $fecha)
                        ->where('materia_codigo', '!=', $codigo) // Diferente materia
                        ->whereHas('asignatura', function ($q) use ($carreraId, $semestre) {
                            $q->whereHas('carreras', function ($cq) use ($carreraId, $semestre) {
                                $cq->where('carrera_id', $carreraId)
                                    ->where('semestre', $semestre);
                            });
                        })
                        ->exists();

                    if ($collision) {
                        $result['error'] = "Ya existe otro examen programado para el semestre {$semestre} en la fecha {$fecha}. (Restricción: Máx 1 examen por día para el mismo semestre)";
                        return $result;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Crear examen manualmente
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gestion' => 'required|string|max:20',
            'carrera_id' => 'required|exists:carreras,id',
            'materia_codigo' => 'required|string|max:50',
            'materia_nombre' => 'required|string|max:255',
            'tipo_examen' => 'required|in:1er Parcial,2do Parcial,Final,2da Instancia',
            'semana' => 'required|integer|min:1|max:25',
            'fecha' => 'required|date',
            'hora_inicio' => 'required',
            'hora_fin' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
        }

        $examen = RolExamen::create([
            ...$request->all(),
            'created_by' => auth()->id(),
        ]);

        return response()->json($examen, 201);
    }

    /**
     * Actualizar examen
     */
    public function update(Request $request, $id)
    {
        $examen = RolExamen::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'tipo_examen' => 'sometimes|in:1er Parcial,2do Parcial,Final,2da Instancia',
            'semana' => 'sometimes|integer|min:1|max:25',
            'fecha' => 'sometimes|date',
            'hora_inicio' => 'sometimes',
            'hora_fin' => 'sometimes',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
        }

        $examen->update($request->all());

        return response()->json($examen);
    }

    /**
     * Eliminar examen
     */
    public function destroy($id)
    {
        $examen = RolExamen::findOrFail($id);
        $examen->delete();

        return response()->json(['message' => 'Examen eliminado']);
    }

    /**
     * Eliminar todos los exámenes de una gestión y carrera
     */
    public function destroyAll(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gestion' => 'required|string|max:20',
            'carrera_id' => 'required|exists:carreras,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
        }

        $count = RolExamen::where('gestion', $request->gestion)
            ->where('carrera_id', $request->carrera_id)
            ->delete();

        return response()->json(['message' => "Se eliminaron {$count} exámenes correctamente.", 'count' => $count]);
    }

    /**
     * Descargar plantilla Excel
     */
    public function template()
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // 1. Set Headers
        $headers = ['Código Materia', 'Tipo Examen', 'Grupo (Teórico)', 'Fecha', 'Hora Inicio'];
        $sheet->fromArray($headers, NULL, 'A1');

        // 2. Add Formatting
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']], // Indigo
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // 3. Add Sample Data
        $samples = [
            ['FIS101', '1er Parcial', '1', date('Y-m-d'), '08:00'],
            ['MAT101', '2do Parcial', '1', date('Y-m-d', strtotime('+7 days')), '10:00'],
            ['QMC101', 'Final', '2', date('Y-m-d', strtotime('+14 days')), '14:00'],
            ['INF101', '2da Instancia', '1', date('Y-m-d', strtotime('+30 days')), '08:00'],
        ];

        $row = 2;
        foreach ($samples as $sample) {
            $sheet->fromArray($sample, NULL, 'A' . $row);
            $row++;
        }

        // 4. Add Validation/Comments (Optional but helpful)
        $sheet->getComment('C1')->getText()->createTextRun('Opciones: 1er Parcial, 2do Parcial, Final, 2da Instancia');
        $sheet->getComment('D1')->getText()->createTextRun('Número del Grupo Teórico (ej: 1, 2)');

        // 5. Stream Download
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'plantilla_rol_examenes.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function parseDate($value, $añoDefault = 2025)
    {
        if (empty($value)) return null;

        // Si es número (Excel date serial)
        if (is_numeric($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
        }

        // Si es string, limpiar y normalizar
        try {
            $value = strtolower(trim($value));
            
            // Eliminar conectores comunes en español
            $value = str_replace([' de ', ' del '], ' ', $value);
            
            // Mapeo extendido de meses (incluyendo variaciones)
            $meses = [
                'ene' => 'Jan', 'feb' => 'Feb', 'mar' => 'Mar',
                'abr' => 'Apr', 'may' => 'May', 'jun' => 'Jun',
                'jul' => 'Jul', 'ago' => 'Aug', 'sep' => 'Sep', 'set' => 'Sep',
                'oct' => 'Oct', 'nov' => 'Nov', 'dic' => 'Dec'
            ];
            
            foreach ($meses as $es => $en) {
                if (str_contains($value, $es)) {
                    $value = str_replace($es, $en, $value);
                    break; 
                }
            }
            
            // Asegurar año si no está presente
            if (!preg_match('/\d{4}/', $value)) {
                $value .= ' ' . $añoDefault;
            }

            $timestamp = strtotime($value);
            return $timestamp ? date('Y-m-d', $timestamp) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function parseTime($value)
    {
        if (empty($value)) return '00:00';

        // Si es número decimal (Excel time)
        if (is_numeric($value) && $value < 1) {
            $hours = floor($value * 24);
            $minutes = round(($value * 24 - $hours) * 60);
            return sprintf('%02d:%02d', $hours, $minutes);
        }

        // Si es string, limpiar
        return date('H:i', strtotime($value));
    }

    private function normalizarTipoExamen($tipo)
    {
        $tipo = strtolower(trim($tipo));

        $mapping = [
            '1er parcial' => '1er Parcial',
            'primer parcial' => '1er Parcial',
            '1 parcial' => '1er Parcial',
            '2do parcial' => '2do Parcial',
            'segundo parcial' => '2do Parcial',
            '2 parcial' => '2do Parcial',
            'final' => 'Final',
            'examen final' => 'Final',
            '2da instancia' => '2da Instancia',
            'segunda instancia' => '2da Instancia',
            'segunda' => '2da Instancia',
        ];

        return $mapping[$tipo] ?? null;
    }
}
