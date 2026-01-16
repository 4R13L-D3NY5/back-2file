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
        $query = RolExamen::query();

        if ($request->has('gestion')) {
            $query->gestion($request->gestion);
        }

        if ($request->has('carrera_id')) {
            $query->carrera($request->carrera_id);
        }

        if ($request->has('materia_codigo')) {
            $query->materia($request->materia_codigo);
        }

        $examenes = $query->orderBy('semana')
            ->orderBy('fecha')
            ->orderBy('hora_inicio')
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
            ->orWhere(function($q) use ($materiaId) {
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

            DB::beginTransaction();

            foreach ($rows as $index => $row) {
                // Skip empty rows
                if (empty($row[0]) && empty($row[1])) continue;

                $rowNumber = $index + 2; // +2 porque saltamos encabezado y Excel es 1-indexed

                try {
                    // Validar formato de fila
                    // A: Código, B: Nombre, C: Tipo, D: Semana, E: Fecha, F: Hora Inicio, G: Hora Fin
                    $codigo = trim($row[0] ?? '');
                    $nombre = trim($row[1] ?? '');
                    $tipo = trim($row[2] ?? '');
                    $semana = intval($row[3] ?? 0);
                    $fecha = $this->parseDate($row[4] ?? '');
                    $horaInicio = $this->parseTime($row[5] ?? '');
                    $horaFin = $this->parseTime($row[6] ?? '');
                    $aula = trim($row[7] ?? '');

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

                    // Crear o actualizar examen
                    RolExamen::updateOrCreate(
                        [
                            'gestion' => $gestion,
                            'carrera_id' => $carreraId,
                            'materia_codigo' => $codigo,
                            'tipo_examen' => $tipoNormalizado,
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
                'message' => "Se importaron {$imported} exámenes",
                'imported' => $imported,
                'errors' => $errors,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error procesando archivo: ' . $e->getMessage()
            ], 500);
        }
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
     * Descargar plantilla Excel
     */
    public function template()
    {
        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="plantilla_rol_examenes.xlsx"',
        ];

        // En producción, crear un archivo Excel real con PhpSpreadsheet
        // Por ahora, retornamos un mensaje
        return response()->json([
            'message' => 'Descargar plantilla desde la documentación',
            'formato' => [
                'A' => 'Código Materia',
                'B' => 'Nombre Materia',
                'C' => 'Tipo Examen (1er Parcial, 2do Parcial, Final, 2da Instancia)',
                'D' => 'Semana (número)',
                'E' => 'Fecha (YYYY-MM-DD)',
                'F' => 'Hora Inicio (HH:MM)',
                'G' => 'Hora Fin (HH:MM)',
                'H' => 'Aula (opcional)',
            ]
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
