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
            $query->where('rol_examenes.carrera_id', $request->carrera_id);
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

        $examenes = $query->orderBy('rol_examenes.semana')
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
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // Saltar encabezado
            array_shift($rows);

            $imported = 0;
            $errors = [];
            $warnings = [];

            DB::beginTransaction();

            foreach ($rows as $index => $row) {
                // Skip empty rows
                if (empty($row[0]) && empty($row[1])) continue;

                $rowNumber = $index + 2; // +2 porque saltamos encabezado y Excel es 1-indexed

                try {
                    // Validar formato de fila
                    // A: Código, B: Nombre, C: Tipo, D: Grupo, E: Semana, F: Fecha, G: Hora Inicio, H: Hora Fin, I: Aula
                    $codigo = trim($row[0] ?? '');
                    $nombre = trim($row[1] ?? '');
                    $tipo = trim($row[2] ?? '');
                    $grupo = trim($row[3] ?? '');
                    $semana = intval($row[4] ?? 0);
                    $fecha = $this->parseDate($row[5] ?? ''); // Shifted
                    $horaInicio = $this->parseTime($row[6] ?? ''); // Shifted
                    $horaFin = $this->parseTime($row[7] ?? ''); // Shifted
                    $aula = trim($row[8] ?? ''); // Shifted

                    if (empty($codigo) || empty($tipo) || $semana <= 0) {
                        $errors[] = "Fila {$rowNumber}: Datos incompletos";
                        continue;
                    }

                    // Normalizar tipo de examen
                    $tipoNormalizado = $this->normalizarTipoExamen($tipo);
                    if (!$tipoNormalizado) {
                        $errors[] = "Fila {$rowNumber}: Tipo de examen inválido '{$tipo}'";
                        continue;
                    }

                    // VALIDAR REGLAS DE NEGOCIO
                    $validation = $this->validateExamRules($carreraId, $codigo, $grupo, $semana, $fecha, $tipoNormalizado);

                    if (!empty($validation['error'])) {
                        $errors[] = "Fila {$rowNumber}: " . $validation['error'];
                        continue; // Block row
                    }

                    if (!empty($validation['warning'])) {
                        $warnings[] = "Fila {$rowNumber}: " . $validation['warning'];
                        // Proceed anyway
                    }

                    // Crear o actualizar examen
                    RolExamen::updateOrCreate(
                        [
                            'gestion' => $gestion,
                            'carrera_id' => $carreraId,
                            'materia_codigo' => $codigo,
                            'tipo_examen' => $tipoNormalizado,
                            'grupo' => $grupo ?: null, // Include group in unique key if needed? Maybe not strictly unique for CREATE but for UPDATE yes?
                            // WARNING: unique key logic might need 'grupo' if we want to differentiate exams for different groups of same subject.
                            // If 'grupo' is null, we treat as general exam?
                            // Let's assume unique key includes grupo if present.
                        ],
                        [
                            'materia_nombre' => $nombre,
                            'semana' => $semana,
                            'fecha' => $fecha,
                            'hora_inicio' => $horaInicio,
                            'hora_fin' => $horaFin,
                            'aula' => $aula,
                            'created_by' => auth()->id(),
                        ]
                    );

                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = "Fila {$rowNumber}: " . $e->getMessage();
                }
            }

            DB::commit();

            return response()->json([
                'message' => "Se procesaron {$imported} registros",
                'imported' => $imported,
                'errors' => $errors,
                'warnings' => $warnings,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error procesando archivo: ' . $e->getMessage()
            ], 500);
        }
    }

    private function validateExamRules($carreraId, $codigo, $grupo, $semana, $fecha, $tipo)
    {
        $result = ['error' => null, 'warning' => null];

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
                $result['error'] = "El {$tipo} debe ser entre semana {$min} y {$max} (Actual: {$semana})";
                return $result;
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
                        $diaNombre = $nombresDias[$diaExamen] ?? $diaExamen;
                        $result['error'] = "El examen es el {$diaNombre}, pero el grupo {$grupo} (Teórico) no tiene clases ese día.";
                        return $result;
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
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin' => 'required|date_format:H:i',
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
            'hora_inicio' => 'sometimes|date_format:H:i',
            'hora_fin' => 'sometimes|date_format:H:i',
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
        $headers = ['Código Materia', 'Nombre Materia', 'Tipo Examen', 'Grupo (Teórico)', 'Semana', 'Fecha', 'Hora Inicio', 'Hora Fin', 'Aula'];
        $sheet->fromArray($headers, NULL, 'A1');

        // 2. Add Formatting
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']], // Indigo
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle('A1:I1')->applyFromArray($headerStyle);

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // 3. Add Sample Data (Different types)
        $samples = [
            ['FIS101', 'FÍSICA I', '1er Parcial', '1', '7', date('Y-m-d'), '08:00', '10:00', 'Aula 101'],
            ['MAT101', 'CALCULO I', '2do Parcial', '1', '14', date('Y-m-d', strtotime('+7 days')), '10:00', '12:00', 'Aula 102'],
            ['QMC101', 'QUÍMICA I', 'Final', '2', '20', date('Y-m-d', strtotime('+14 days')), '14:00', '16:00', 'Aula 201'],
            ['INF101', 'INTRODUCCIÓN', '2da Instancia', '1', '22', date('Y-m-d', strtotime('+30 days')), '08:00', '10:00', 'Aula 101'],
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

    private function parseDate($value)
    {
        if (empty($value)) return null;

        // Si es número (Excel date serial)
        if (is_numeric($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
        }

        // Si es string, intentar parsear
        try {
            return date('Y-m-d', strtotime($value));
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
