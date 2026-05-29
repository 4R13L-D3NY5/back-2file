<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateRolExamenPackageJob;
use App\Models\RolExamen;
use App\Models\VirtualExamAnswer;
use App\Models\VirtualExamAttempt;
use App\Models\VirtualExamRoster;
use App\Models\VirtualExamSession;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class VirtualExamController extends Controller
{
    private const EVALUACION_ROLES = ['EVALUACIONES', 'RESPONSABLE_EVALUACIONES', 'ADMIN', 'SUPER_ADMIN'];
    private const DOCENTE_ROLES = ['DOCENTE', 'ADMIN', 'SUPER_ADMIN'];
    private const SESSION_STATES = ['PROGRAMADO', 'GENERADO', 'EJECUCION', 'REALIZADO', 'SUBIDO'];
    private const ATTEMPT_STATES = ['INGRESADO', 'FINALIZADO', 'AUTO_CERRADO', 'RECHAZADO'];

    public function index(Request $request)
    {
        $query = VirtualExamSession::query()
            ->with(['rolExamen.sede', 'rolExamen.carrera'])
            ->withCount(['roster', 'attempts'])
            ->latest();

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('gestion')) {
            $query->whereHas('rolExamen', fn ($q) => $q->where('gestion', $request->gestion));
        }

        if ($request->filled('sede_id')) {
            $query->whereHas('rolExamen', fn ($q) => $q->where('sede_id', $request->sede_id));
        }

        if ($request->filled('carrera_id')) {
            $query->whereHas('rolExamen', fn ($q) => $q->where('carrera_id', $request->carrera_id));
        }

        $this->applyUserScope($query, $request->user());

        $sessions = $query->limit(250)->get()->map(fn ($session) => $this->sessionPayload($session));

        return response()->json(['data' => $sessions]);
    }

    public function show(Request $request, VirtualExamSession $session)
    {
        $this->authorizeSessionView($session, $request->user());
        $this->refreshExpiredSession($session);
        $session->load(['rolExamen.sede', 'rolExamen.carrera', 'roster.attempt', 'attempts.answers']);

        return response()->json($this->sessionPayload($session, true));
    }

    public function generate(Request $request, $rolExamenId)
    {
        $this->requireRole($request, self::EVALUACION_ROLES);

        $validated = $request->validate([
            'cantidad_variantes' => 'required|integer|min:1|max:5',
            'duracion_minutos' => 'nullable|integer|min:10|max:240',
            'facil' => 'nullable|integer|min:0',
            'medio' => 'nullable|integer|min:0',
            'dificil' => 'nullable|integer|min:0',
        ]);

        $rolExamen = RolExamen::findOrFail($rolExamenId);
        $existingSession = VirtualExamSession::where('rol_examen_id', $rolExamen->id)->first();

        if ($existingSession && in_array($existingSession->estado, ['EJECUCION', 'REALIZADO', 'SUBIDO'], true)) {
            throw ValidationException::withMessages([
                'estado' => 'No se puede regenerar una sesion virtual que ya fue iniciada o cerrada.',
            ]);
        }

        $configActual = $rolExamen->config_generacion ?? [];
        $cantidadVariantes = (int) $validated['cantidad_variantes'];
        $config = array_merge($configActual, [
            'cantVariantes' => $cantidadVariantes,
            'facil' => (int) ($validated['facil'] ?? $configActual['facil'] ?? 7),
            'medio' => (int) ($validated['medio'] ?? $configActual['medio'] ?? 16),
            'dificil' => (int) ($validated['dificil'] ?? $configActual['dificil'] ?? 7),
            'formatoHoja' => $configActual['formatoHoja'] ?? 'Oficio (8.5" x 13")',
            'modalidad' => 'VIRTUAL',
        ]);

        $needsGeneration = empty($configActual['pattern_audit'])
            || (int) ($configActual['cantVariantes'] ?? 0) !== $cantidadVariantes
            || ($configActual['modalidad'] ?? null) !== 'VIRTUAL';

        if ($needsGeneration) {
            GenerateRolExamenPackageJob::dispatchSync($rolExamen->id, $config, $request->user()?->id);
            $rolExamen->refresh();
        } else {
            $rolExamen->update(['config_generacion' => array_merge($configActual, $config)]);
        }

        if (empty($rolExamen->config_generacion['pattern_audit'])) {
            throw ValidationException::withMessages([
                'examen' => 'No se pudo generar el paquete virtual con auditoria de variantes.',
            ]);
        }

        $rolExamen->update(['modalidad' => 'VIRTUAL']);

        $session = VirtualExamSession::updateOrCreate(
            ['rol_examen_id' => $rolExamen->id],
            [
                'public_token' => $existingSession?->public_token ?: $this->buildUniqueToken(),
                'estado' => 'GENERADO',
                'cantidad_variantes' => $cantidadVariantes,
                'duracion_minutos' => (int) ($validated['duracion_minutos'] ?? 45),
                'generado_en' => now(),
                'generado_por' => $request->user()?->id,
                'configuracion' => [
                    'facil' => $config['facil'],
                    'medio' => $config['medio'],
                    'dificil' => $config['dificil'],
                    'modalidad' => 'VIRTUAL',
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Examen virtual generado.',
            'session' => $this->sessionPayload($session->load(['rolExamen.sede', 'rolExamen.carrera'])),
        ]);
    }

    public function uploadRoster(Request $request, VirtualExamSession $session)
    {
        $this->authorizeTeacherAction($session, $request->user(), true);

        $validated = $request->validate([
            'estudiantes' => 'required|array|min:1',
            'estudiantes.*.codigo' => 'required|string|max:60',
            'estudiantes.*.nombre' => 'required|string|max:255',
        ]);

        $count = 0;
        foreach ($validated['estudiantes'] as $student) {
            VirtualExamRoster::updateOrCreate(
                [
                    'virtual_exam_session_id' => $session->id,
                    'codigo_estudiante' => $this->normalizeStudentCode($student['codigo']),
                ],
                [
                    'nombre_estudiante' => trim($student['nombre']),
                    'metadata' => ['cargado_por' => $request->user()?->id],
                ]
            );
            $count++;
        }

        return response()->json(['success' => true, 'importados' => $count]);
    }

    public function updateRosterStatus(Request $request, VirtualExamSession $session, VirtualExamRoster $roster)
    {
        $this->authorizeTeacherAction($session, $request->user(), true);

        if ((int) $roster->virtual_exam_session_id !== (int) $session->id) {
            abort(404);
        }

        $validated = $request->validate([
            'estado' => 'required|string|in:ACEPTADO,RECHAZADO,PENDIENTE',
        ]);

        $metadata = $roster->metadata ?: [];
        $metadata['validacion'] = $validated['estado'];
        $metadata['validado_en'] = now()->toDateTimeString();
        $metadata['validado_por'] = $request->user()?->id;

        $roster->update(['metadata' => $metadata]);

        if ($validated['estado'] === 'RECHAZADO') {
            $attempt = $roster->attempt()->where('estado', 'INGRESADO')->first();
            if ($attempt) {
                $this->finalizeAttempt($attempt, 'RECHAZADO');
            }
        }

        return response()->json([
            'success' => true,
            'roster' => $this->rosterPayload($roster->fresh('attempt')),
        ]);
    }

    public function start(Request $request, VirtualExamSession $session)
    {
        $this->authorizeTeacherAction($session, $request->user(), false);
        $session->load('rolExamen');

        if ($session->estado !== 'GENERADO') {
            throw ValidationException::withMessages(['estado' => 'El examen virtual debe estar generado antes de iniciar.']);
        }

        $session->update([
            'estado' => 'EJECUCION',
            'iniciado_en' => now(),
            'finaliza_en' => now()->addMinutes($session->duracion_minutos),
            'iniciado_por' => $request->user()?->id,
        ]);

        return response()->json(['success' => true, 'session' => $this->sessionPayload($session->fresh())]);
    }

    public function close(Request $request, VirtualExamSession $session)
    {
        $this->authorizeTeacherAction($session, $request->user(), true);
        $this->closeSession($session, 'AUTO_CERRADO');

        return response()->json(['success' => true, 'session' => $this->sessionPayload($session->fresh())]);
    }

    public function markUploaded(Request $request, VirtualExamSession $session)
    {
        $this->requireRole($request, self::EVALUACION_ROLES);

        if ($session->estado !== 'REALIZADO') {
            throw ValidationException::withMessages([
                'estado' => 'Solo se puede marcar como subido un examen virtual realizado.',
            ]);
        }

        $session->update(['estado' => 'SUBIDO']);

        return response()->json(['success' => true]);
    }

    public function reset(Request $request, VirtualExamSession $session)
    {
        $this->requireRole($request, ['ADMIN', 'SUPER_ADMIN']);

        DB::transaction(function () use ($session) {
            $session->attempts()->with('answers')->get()->each(function (VirtualExamAttempt $attempt) {
                $attempt->answers()->delete();
                $attempt->delete();
            });

            $session->roster()->delete();
            $session->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Proceso virtual reiniciado a programado.',
        ]);
    }

    public function exportRemark(Request $request, VirtualExamSession $session)
    {
        $this->requireRole($request, self::EVALUACION_ROLES);
        $this->refreshExpiredSession($session);
        $session->loadMissing('rolExamen');

        $attempts = $session->attempts()->with('answers')->orderBy('codigo_estudiante')->get();
        $variants = $this->getAuditVariants($session);
        $questionCount = max(
            1,
            collect($variants)->max(fn ($variant) => count($variant['patron_respuestas'] ?? [])) ?: 0,
            $attempts->max(fn ($attempt) => $attempt->answers->max('numero_pregunta') ?: 0) ?: 0,
        );
        $filename = 'remark_virtual_'.$session->id.'_'.now()->format('Ymd_His').'.xlsx';

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Remark');

        $headers = array_merge(
            ['Codigo', 'Codigo Estudiante', 'Nombre', 'Variante', 'ID_Pregunta', 'Estado'],
            array_map(fn ($n) => 'P'.$n, range(1, $questionCount))
        );
        $rows = [$headers];
        $subjectCode = $session->rolExamen?->materia_codigo ?? '';

        foreach ($variants as $variant) {
            $patternAnswers = collect($variant['patron_respuestas'] ?? [])
                ->map(fn ($value) => is_scalar($value) ? (string) $value : $this->answerToString($value))
                ->pad($questionCount, '')
                ->take($questionCount)
                ->values()
                ->all();

            $rows[] = array_merge(
                [$subjectCode, '', '', $variant['letra'] ?? '', 'Respuesta', 'PATRON'],
                $patternAnswers
            );
        }

        foreach ($attempts as $attempt) {
            $answers = $attempt->answers->keyBy('numero_pregunta');
            $row = [
                $subjectCode,
                $attempt->codigo_estudiante,
                $attempt->nombre_estudiante,
                $attempt->variante,
                'Respuesta',
                $attempt->estado,
            ];

            for ($i = 1; $i <= $questionCount; $i++) {
                $row[] = $this->answerToString($answers->get($i)?->respuesta_marcada ?? []);
            }

            $rows[] = $row;
        }

        $sheet->fromArray($rows, null, 'A1');
        $lastColumn = $sheet->getHighestColumn();
        $lastRow = $sheet->getHighestRow();
        $sheet->freezePane('G2');
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle("A2:{$lastColumn}".(count($variants) + 1))->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDE9FE']],
        ]);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function access(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string|max:20',
            'codigo' => 'required|string|max:60',
            'nombre' => 'required|string|max:255',
        ]);

        $session = VirtualExamSession::with('rolExamen')
            ->where('public_token', strtoupper(trim($validated['token'])))
            ->first();

        if (! $session) {
            throw ValidationException::withMessages(['token' => 'Codigo de examen invalido.']);
        }

        $codigo = $this->normalizeStudentCode($validated['codigo']);
        $roster = VirtualExamRoster::firstOrNew([
            'virtual_exam_session_id' => $session->id,
            'codigo_estudiante' => $codigo,
        ]);
        $metadata = $roster->metadata ?: [];

        if (($metadata['validacion'] ?? null) === 'RECHAZADO') {
            throw ValidationException::withMessages([
                'codigo' => 'Tu ingreso fue rechazado por el docente responsable.',
            ]);
        }

        $metadata['origen'] = $metadata['origen'] ?? 'portal_publico';
        $metadata['registrado_en'] = $metadata['registrado_en'] ?? now()->toDateTimeString();
        $metadata['ultimo_ingreso_en'] = now()->toDateTimeString();
        $metadata['ip'] = $request->ip();

        $roster->fill([
            'nombre_estudiante' => trim($validated['nombre']),
            'metadata' => $metadata,
        ]);
        $roster->save();

        if (($metadata['validacion'] ?? 'PENDIENTE') !== 'ACEPTADO') {
            return response()->json($this->approvalPendingPayload($session, $roster));
        }

        $this->refreshExpiredSession($session);
        $session->refresh();
        $scheduledAt = $this->scheduledStartAt($session->rolExamen);

        if (in_array($session->estado, ['PROGRAMADO', 'GENERADO'], true)) {
            return response()->json($this->waitingPayload($session, $scheduledAt));
        }

        if ($session->estado !== 'EJECUCION' || ! $session->finaliza_en || now()->gte($session->finaliza_en)) {
            throw ValidationException::withMessages(['token' => 'El examen no esta en ejecucion o ya finalizo.']);
        }

        $attempt = VirtualExamAttempt::where('virtual_exam_session_id', $session->id)
            ->where('codigo_estudiante', $codigo)
            ->first();

        if ($attempt && in_array($attempt->estado, ['FINALIZADO', 'AUTO_CERRADO', 'RECHAZADO'], true)) {
            return response()->json([
                'finished' => true,
                'message' => 'Este codigo ya finalizo el examen.',
                'download_token' => $attempt->download_token,
            ], 409);
        }

        if (! $attempt) {
            $attempt = VirtualExamAttempt::create([
                'virtual_exam_session_id' => $session->id,
                'virtual_exam_roster_id' => $roster->id,
                'codigo_estudiante' => $codigo,
                'nombre_estudiante' => trim($validated['nombre']),
                'variante' => $this->assignVariant($session, $codigo),
                'estado' => 'INGRESADO',
                'access_token' => Str::random(60),
                'download_token' => Str::random(60),
                'ingresado_en' => now(),
                'expira_en' => $session->finaliza_en,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json($this->attemptExamPayload($attempt->fresh(), $session));
    }

    public function saveAnswers(Request $request)
    {
        $validated = $request->validate([
            'access_token' => 'required|string',
            'respuestas' => 'required|array',
            'respuestas.*.numero' => 'required|integer|min:1|max:300',
            'respuestas.*.respuesta' => 'nullable',
        ]);

        $attempt = VirtualExamAttempt::with('session.rolExamen')
            ->where('access_token', $validated['access_token'])
            ->firstOrFail();

        $this->ensureAttemptOpen($attempt);
        $variant = $this->variantByLetter($attempt->session, $attempt->variante);
        $correctByNumber = $this->correctAnswersByNumber($variant);

        foreach ($validated['respuestas'] as $answer) {
            $numero = (int) $answer['numero'];
            $marked = $this->normalizeAnswerValue($answer['respuesta'] ?? []);
            $correct = $this->normalizeAnswerValue($correctByNumber[$numero]['answer'] ?? []);

            VirtualExamAnswer::updateOrCreate(
                [
                    'virtual_exam_attempt_id' => $attempt->id,
                    'numero_pregunta' => $numero,
                ],
                [
                    'banco_pregunta_id' => $correctByNumber[$numero]['id'] ?? null,
                    'respuesta_marcada' => $marked,
                    'respuesta_correcta' => $correct,
                    'es_correcta' => $this->answersMatch($marked, $correct),
                ]
            );
        }

        $this->recalculateAttempt($attempt->fresh());

        return response()->json(['success' => true]);
    }

    public function finish(Request $request)
    {
        $validated = $request->validate(['access_token' => 'required|string']);
        $attempt = VirtualExamAttempt::with('session.rolExamen')
            ->where('access_token', $validated['access_token'])
            ->firstOrFail();

        if (! $this->ensureAttemptOpen($attempt, false)) {
            return response()->json([
                'success' => true,
                'download_token' => $attempt->fresh()->download_token,
            ]);
        }

        $this->finalizeAttempt($attempt, 'FINALIZADO');

        return response()->json([
            'success' => true,
            'download_token' => $attempt->fresh()->download_token,
        ]);
    }

    public function downloadStudentPattern($downloadToken)
    {
        $attempt = VirtualExamAttempt::with(['session.rolExamen.sede', 'session.rolExamen.carrera', 'answers'])
            ->where('download_token', $downloadToken)
            ->firstOrFail();

        $filename = 'patron_'.$attempt->codigo_estudiante.'_'.$attempt->variante.'.pdf';
        $pdf = $this->buildStudentPatternPdf($attempt);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function requireRole(Request $request, array $roles): void
    {
        $role = $request->user()?->rol?->codigo;
        if (! in_array($role, $roles, true)) {
            abort(403, 'Forbidden: insufficient permissions');
        }
    }

    private function authorizeSessionView(VirtualExamSession $session, $user): void
    {
        $role = $user?->rol?->codigo;
        if (in_array($role, self::EVALUACION_ROLES, true)) {
            return;
        }

        if ($role === 'DOCENTE' && $this->teacherOwnsSession($session, $user)) {
            return;
        }

        abort(403, 'Forbidden: insufficient permissions');
    }

    private function authorizeTeacherAction(VirtualExamSession $session, $user, bool $allowEvaluaciones): void
    {
        $role = $user?->rol?->codigo;
        if ($allowEvaluaciones && in_array($role, self::EVALUACION_ROLES, true)) {
            return;
        }

        if (in_array($role, self::DOCENTE_ROLES, true) && ($role !== 'DOCENTE' || $this->teacherOwnsSession($session, $user))) {
            return;
        }

        abort(403, 'Forbidden: insufficient permissions');
    }

    private function applyUserScope($query, $user): void
    {
        $role = $user?->rol?->codigo;
        if ($role !== 'DOCENTE') {
            return;
        }

        $docenteId = DB::table('docentes')->where('user_id', $user->id)->value('id');
        if (! $docenteId) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereHas('rolExamen', function ($rolQuery) use ($docenteId) {
            $rolQuery->whereExists(function ($sub) use ($docenteId) {
                $sub->selectRaw(1)
                    ->from('grupos')
                    ->join('asignaturas', 'grupos.asignatura_id', '=', 'asignaturas.id')
                    ->whereColumn('grupos.sede_id', 'rol_examenes.sede_id')
                    ->whereColumn('grupos.carrera_id', 'rol_examenes.carrera_id')
                    ->whereColumn('asignaturas.codigo', 'rol_examenes.materia_codigo')
                    ->whereColumn('grupos.nombre', 'rol_examenes.grupo')
                    ->where('grupos.docente_id', $docenteId)
                    ->where('grupos.estado', 'ACTIVO')
                    ->whereNull('grupos.deleted_at');
            });
        });
    }

    private function teacherOwnsSession(VirtualExamSession $session, $user): bool
    {
        $docenteId = DB::table('docentes')->where('user_id', $user->id)->value('id');
        if (! $docenteId) {
            return false;
        }

        $rol = $session->rolExamen ?: RolExamen::find($session->rol_examen_id);
        if (! $rol) {
            return false;
        }

        return DB::table('grupos')
            ->join('asignaturas', 'grupos.asignatura_id', '=', 'asignaturas.id')
            ->where('grupos.sede_id', $rol->sede_id)
            ->where('grupos.carrera_id', $rol->carrera_id)
            ->where('asignaturas.codigo', $rol->materia_codigo)
            ->where('grupos.nombre', $rol->grupo)
            ->where('grupos.docente_id', $docenteId)
            ->where('grupos.estado', 'ACTIVO')
            ->whereNull('grupos.deleted_at')
            ->exists();
    }

    private function sessionPayload(VirtualExamSession $session, bool $withDetails = false): array
    {
        $session->loadMissing(['rolExamen.sede', 'rolExamen.carrera']);
        $rol = $session->rolExamen;
        $payload = [
            'id' => $session->id,
            'rol_examen_id' => $session->rol_examen_id,
            'token' => $session->public_token,
            'estado' => $session->estado,
            'cantidad_variantes' => $session->cantidad_variantes,
            'duracion_minutos' => $session->duracion_minutos,
            'generado_en' => optional($session->generado_en)?->toDateTimeString(),
            'iniciado_en' => optional($session->iniciado_en)?->toDateTimeString(),
            'finaliza_en' => optional($session->finaliza_en)?->toDateTimeString(),
            'cerrado_en' => optional($session->cerrado_en)?->toDateTimeString(),
            'roster_count' => $session->roster_count ?? $session->roster()->count(),
            'attempts_count' => $session->attempts_count ?? $session->attempts()->count(),
            'rol' => [
                'materia_codigo' => $rol?->materia_codigo,
                'materia_nombre' => $rol?->materia_nombre,
                'parcial' => $rol?->tipo_examen,
                'grupo' => $rol?->grupo,
                'fecha' => optional($rol?->fecha)?->format('Y-m-d'),
                'hora_inicio' => $rol?->hora_inicio,
                'hora_fin' => $rol?->hora_fin,
                'gestion' => $rol?->gestion,
                'sede' => $rol?->sede?->nombre,
                'carrera' => $rol?->carrera?->nombre,
            ],
        ];

        if ($withDetails) {
            $payload['roster'] = $session->roster
                ->sortBy(fn ($row) => $this->normalizeStudentCode($row->codigo_estudiante), SORT_NATURAL)
                ->map(fn ($row) => $this->rosterPayload($row))
                ->values();
        }

        return $payload;
    }

    private function rosterPayload(VirtualExamRoster $row): array
    {
        $metadata = $row->metadata ?: [];

        return [
            'id' => $row->id,
            'codigo' => $row->codigo_estudiante,
            'nombre' => $row->nombre_estudiante,
            'validacion' => $metadata['validacion'] ?? 'PENDIENTE',
            'validado_en' => $metadata['validado_en'] ?? null,
            'registrado_en' => $metadata['registrado_en'] ?? null,
            'ultimo_ingreso_en' => $metadata['ultimo_ingreso_en'] ?? null,
            'intento' => $row->attempt ? [
                'estado' => $row->attempt->estado,
                'variante' => $row->attempt->variante,
                'ingresado_en' => optional($row->attempt->ingresado_en)?->toDateTimeString(),
                'finalizado_en' => optional($row->attempt->finalizado_en)?->toDateTimeString(),
                'respuestas_total' => $row->attempt->respuestas_total,
                'correctas_total' => $row->attempt->correctas_total,
                'download_token' => $row->attempt->download_token,
            ] : null,
        ];
    }

    private function attemptExamPayload(VirtualExamAttempt $attempt, VirtualExamSession $session): array
    {
        $variant = $this->variantByLetter($session, $attempt->variante);

        return [
            'access_token' => $attempt->access_token,
            'attempt' => [
                'student_code' => $attempt->codigo_estudiante,
                'student_name' => $attempt->nombre_estudiante,
                'variant' => $attempt->variante,
                'expires_at' => optional($session->finaliza_en)?->toDateTimeString(),
                'remaining_seconds' => (int) floor(max(0, now()->diffInSeconds($session->finaliza_en, false))),
            ],
            'exam' => [
                'materia' => $session->rolExamen?->materia_nombre,
                'codigo' => $session->rolExamen?->materia_codigo,
                'parcial' => $session->rolExamen?->tipo_examen,
                'grupo' => $session->rolExamen?->grupo,
                'questions' => $this->publicQuestions($variant),
            ],
        ];
    }

    private function getAuditVariants(VirtualExamSession $session): array
    {
        $session->loadMissing('rolExamen');
        $variants = $session->rolExamen?->config_generacion['pattern_audit'] ?? [];

        if (! is_array($variants) || empty($variants)) {
            throw ValidationException::withMessages(['examen' => 'El examen virtual no tiene variantes generadas.']);
        }

        return array_values(array_filter($variants, fn ($item) => is_array($item) && ! empty($item['letra'])));
    }

    private function variantByLetter(VirtualExamSession $session, string $letter): array
    {
        foreach ($this->getAuditVariants($session) as $variant) {
            if (($variant['letra'] ?? '') === $letter) {
                return $variant;
            }
        }

        throw ValidationException::withMessages(['variante' => 'La variante asignada no existe.']);
    }

    private function publicQuestions(array $variant): array
    {
        $matchingOptionsByGroup = collect($variant['preguntas'] ?? [])
            ->filter(fn ($question) => is_array($question) && $this->normalizeQuestionType($question['tipo'] ?? '') === 'EMPAREJAMIENTO')
            ->mapWithKeys(fn ($question) => [
                $this->normalizeQuestionGroup($question['grupo'] ?? '') => array_values($question['opciones'] ?? []),
            ]);

        return collect($variant['preguntas'] ?? [])
            ->filter(fn ($question) => is_array($question) && ! $this->isMacroHeader($question['tipo'] ?? null))
            ->values()
            ->map(function ($question, $index) use ($matchingOptionsByGroup) {
                $type = $this->normalizeQuestionType($question['tipo'] ?? '');
                $group = $this->normalizeQuestionGroup($question['grupo'] ?? '');

                return [
                    'number' => $index + 1,
                    'id' => $question['id'] ?? null,
                    'tipo' => $question['tipo'] ?? '',
                    'tipo_normalizado' => $type,
                    'seccion' => $this->sectionForQuestionType($type),
                    'seccion_orden' => $this->sectionOrderForQuestionType($type),
                    'enunciado' => $question['enunciado'] ?? '',
                    'opciones' => $this->publicQuestionOptions(
                        $type,
                        $question,
                        $matchingOptionsByGroup->get($group, [])
                    ),
                ];
            })
            ->all();
    }

    private function correctAnswersByNumber(array $variant): array
    {
        return collect($variant['preguntas'] ?? [])
            ->filter(fn ($question) => is_array($question) && ! $this->isMacroHeader($question['tipo'] ?? null))
            ->values()
            ->mapWithKeys(fn ($question, $index) => [
                $index + 1 => [
                    'id' => $question['id'] ?? null,
                    'answer' => $question['respuesta_correcta'] ?? [],
                ],
            ])
            ->all();
    }

    private function isMacroHeader(?string $type): bool
    {
        return in_array($this->normalizeQuestionType($type), ['PROBLEMA', 'EMPAREJAMIENTO'], true);
    }

    private function normalizeQuestionType(?string $type): string
    {
        $value = mb_strtoupper(trim((string) $type));

        return match ($value) {
            'FV', 'FALSO_VERDADERO', 'FALSO O VERDADERO', 'VERDADERO O FALSO', 'VERDADERO O FALSO SIMPLE' => 'FALSO_VERDADERO',
            'SELECCION_UNICA', 'SELECCION_SIMPLE', 'SELECCION DE LA MEJOR RESPUESTA' => 'SELECCION_SIMPLE',
            'RESPUESTA_COMPUESTA', 'SELECCION_MULTIPLE', 'RESPUESTA A/B/AMBAS/NINGUNA' => 'RESPUESTA_COMPUESTA',
            'PREGUNTA_CON_CLAVE', 'PREGUNTA CON CLAVE' => 'PREGUNTA_CON_CLAVE',
            'PROBLEMA', 'PROBLEMA O CASO' => 'PROBLEMA',
            'SUBPROBLEMA', 'SUBPREGUNTA', 'SUB PROBLEMA' => 'SUBPROBLEMA',
            'EMPAREJAMIENTO', 'EMPAREJAMIENTO AMPLIADO' => 'EMPAREJAMIENTO',
            'OPCION_EMPAREJAMIENTO', 'OPCION EMPAREJAMIENTO', 'OPCION DE EMPAREJAMIENTO' => 'OPCION_EMPAREJAMIENTO',
            default => $value,
        };
    }

    private function normalizeQuestionGroup(?string $group): string
    {
        return mb_strtoupper(trim((string) $group));
    }

    private function sectionForQuestionType(string $type): string
    {
        return match ($type) {
            'FALSO_VERDADERO' => 'VERDADERO O FALSO SIMPLE',
            'PREGUNTA_CON_CLAVE' => 'PREGUNTA CON CLAVE',
            'RESPUESTA_COMPUESTA' => 'RESPUESTA A/B/AMBAS/NINGUNA',
            'SELECCION_SIMPLE' => 'SELECCION DE LA MEJOR RESPUESTA',
            'SUBPROBLEMA' => 'CASOS O PROBLEMAS',
            'OPCION_EMPAREJAMIENTO' => 'EMPAREJAMIENTO AMPLIADO',
            default => 'PREGUNTAS',
        };
    }

    private function sectionOrderForQuestionType(string $type): int
    {
        return match ($type) {
            'FALSO_VERDADERO' => 10,
            'PREGUNTA_CON_CLAVE' => 20,
            'RESPUESTA_COMPUESTA' => 30,
            'SELECCION_SIMPLE' => 40,
            'SUBPROBLEMA' => 50,
            'OPCION_EMPAREJAMIENTO' => 60,
            default => 99,
        };
    }

    private function publicQuestionOptions(string $type, array $question, array $matchingOptions = []): array
    {
        $options = array_values($question['opciones'] ?? []);

        if ($type === 'FALSO_VERDADERO' && empty($options)) {
            return [
                ['id' => 'A', 'text' => 'Verdadero'],
                ['id' => 'B', 'text' => 'Falso'],
            ];
        }

        if ($type === 'RESPUESTA_COMPUESTA' && empty($options)) {
            return [
                ['id' => 'A', 'text' => 'Si la primera es verdadera'],
                ['id' => 'B', 'text' => 'Si la segunda es verdadera'],
                ['id' => 'C', 'text' => 'Si ambas son verdaderas'],
                ['id' => 'D', 'text' => 'Si ninguna es verdadera'],
            ];
        }

        if ($type === 'PREGUNTA_CON_CLAVE' && empty($options)) {
            return [
                ['id' => 'A', 'text' => '1, 2 y 3 son verdaderas'],
                ['id' => 'B', 'text' => '1 y 3 son verdaderas'],
                ['id' => 'C', 'text' => '2 y 4 son verdaderas'],
                ['id' => 'D', 'text' => 'Solo 4 es verdadera'],
                ['id' => 'E', 'text' => 'Todas son verdaderas'],
            ];
        }

        if ($type === 'OPCION_EMPAREJAMIENTO' && empty($options)) {
            return $matchingOptions;
        }

        return $options;
    }

    private function buildUniqueToken(): string
    {
        do {
            $token = strtoupper(Str::random(6));
            $token = str_replace(['0', 'O', 'I', '1'], ['2', 'A', 'X', '9'], $token);
        } while (VirtualExamSession::where('public_token', $token)->exists());

        return $token;
    }

    private function assignVariant(VirtualExamSession $session, string $studentCode): string
    {
        $letters = collect($this->getAuditVariants($session))->pluck('letra')->values()->all();
        $letters = array_slice($letters, 0, max(1, $session->cantidad_variantes));

        if (count($letters) <= 1) {
            return $letters[0] ?? 'A';
        }

        $assignedCounts = VirtualExamAttempt::query()
            ->where('virtual_exam_session_id', $session->id)
            ->select('variante', DB::raw('COUNT(*) as total'))
            ->groupBy('variante')
            ->pluck('total', 'variante')
            ->all();

        $seed = abs(crc32($session->id.'|'.$studentCode));

        return collect($letters)
            ->map(fn ($letter, $position) => [
                'letter' => $letter,
                'total' => (int) ($assignedCounts[$letter] ?? 0),
                'tie_breaker' => ($seed + $position) % count($letters),
            ])
            ->sortBy([
                ['total', 'asc'],
                ['tie_breaker', 'asc'],
            ])
            ->first()['letter'] ?? 'A';
    }

    private function normalizeStudentCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function normalizeAnswerValue($value): array
    {
        if (is_array($value)) {
            return collect($value)
                ->flatten()
                ->map(fn ($item) => strtoupper(trim((string) $item)))
                ->filter()
                ->values()
                ->all();
        }

        $string = strtoupper(trim((string) $value));
        return $string === '' ? [] : [$string];
    }

    private function answersMatch(array $marked, array $correct): bool
    {
        sort($marked);
        sort($correct);

        return $marked === $correct && ! empty($correct);
    }

    private function answerToString($value): string
    {
        return implode(',', $this->normalizeAnswerValue($value));
    }

    private function buildStudentPatternPdf(VirtualExamAttempt $attempt): string
    {
        $rol = $attempt->session?->rolExamen;
        $answers = $attempt->answers->keyBy('numero_pregunta');
        $questionCount = max(
            1,
            $answers->keys()->max() ?: 0,
            count($this->variantByLetter($attempt->session, $attempt->variante)['patron_respuestas'] ?? [])
        );
        $pages = [];
        $currentPage = $this->studentPatternPageHeader($attempt);
        $y = 642;
        $columnWidth = 130;
        $rowHeight = 18;
        $columns = 4;
        $rowsPerColumn = 28;

        for ($i = 1; $i <= $questionCount; $i++) {
            $position = ($i - 1) % ($columns * $rowsPerColumn);

            if ($i > 1 && $position === 0) {
                $pages[] = $currentPage;
                $currentPage = $this->studentPatternPageHeader($attempt);
            }

            $column = intdiv($position, $rowsPerColumn);
            $row = $position % $rowsPerColumn;
            $x = 42 + ($column * $columnWidth);
            $currentY = $y - ($row * $rowHeight);
            $marked = $this->answerToString($answers->get($i)?->respuesta_marcada ?? []);
            $currentPage .= $this->pdfText($x, $currentY, 9, str_pad((string) $i, 3, ' ', STR_PAD_LEFT).'.');
            $currentPage .= $this->pdfText($x + 24, $currentY, 9, $marked !== '' ? $marked : '-');
        }

        $pages[] = $currentPage;

        return $this->makePdfDocument($pages);
    }

    private function studentPatternPageHeader(VirtualExamAttempt $attempt): string
    {
        $rol = $attempt->session?->rolExamen;
        $content = '';
        $content .= $this->pdfText(165, 802, 12, 'UNIVERSIDAD TECNICA PRIVADA COSMOS');
        $content .= $this->pdfText(202, 784, 10, 'PATRON DE RESPUESTAS DEL ESTUDIANTE');
        $content .= $this->pdfText(52, 748, 9, 'Carrera: '.$this->pdfValue($rol?->carrera?->nombre));
        $content .= $this->pdfText(52, 730, 9, 'Materia: '.$this->pdfValue($rol?->materia_nombre));
        $content .= $this->pdfText(52, 712, 9, 'Docente: '.$this->pdfValue($this->resolveRolDocenteName($rol)));
        $content .= $this->pdfText(52, 694, 9, 'Examen: '.$this->pdfValue($rol?->tipo_examen).' / Grupo '.$this->pdfValue($rol?->grupo));
        $content .= $this->pdfText(330, 748, 9, 'Codigo estudiante: '.$attempt->codigo_estudiante);
        $content .= $this->pdfText(330, 730, 9, 'Nombre: '.$attempt->nombre_estudiante);
        $content .= $this->pdfText(330, 712, 9, 'Variante: '.$attempt->variante);
        $content .= $this->pdfText(330, 694, 9, 'Finalizado: '.$this->pdfValue(optional($attempt->finalizado_en)?->format('d/m/Y H:i')));
        $content .= $this->pdfText(52, 666, 8, 'Este documento registra solo las respuestas marcadas por el estudiante. No incluye respuestas correctas.');
        $content .= $this->pdfLine(42, 656, 553, 656);
        $content .= $this->pdfText(42, 626, 9, 'Nro.');
        $content .= $this->pdfText(66, 626, 9, 'Marcada');
        $content .= $this->pdfText(172, 626, 9, 'Nro.');
        $content .= $this->pdfText(196, 626, 9, 'Marcada');
        $content .= $this->pdfText(302, 626, 9, 'Nro.');
        $content .= $this->pdfText(326, 626, 9, 'Marcada');
        $content .= $this->pdfText(432, 626, 9, 'Nro.');
        $content .= $this->pdfText(456, 626, 9, 'Marcada');

        return $content;
    }

    private function makePdfDocument(array $pages): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($i) => (($i * 2) + 3).' 0 R', array_keys($pages))).'] /Count '.count($pages).' >>',
        ];

        foreach ($pages as $index => $content) {
            $pageObject = (count($objects) + 1);
            $contentObject = $pageObject + 1;
            $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /Contents {$contentObject} 0 R >>";
            $objects[] = "<< /Length ".strlen($content)." >>\nstream\n{$content}\nendstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $number => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($number + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private function pdfText(float $x, float $y, int $size, string $text): string
    {
        return "BT /F1 {$size} Tf {$x} {$y} Td (".$this->pdfEscape($text).") Tj ET\n";
    }

    private function pdfLine(float $x1, float $y1, float $x2, float $y2): string
    {
        return "{$x1} {$y1} m {$x2} {$y2} l S\n";
    }

    private function pdfEscape(string $text): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($encoded !== false) {
            $text = $encoded;
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function pdfValue($value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : '-';
    }

    private function resolveRolDocenteName(?RolExamen $rol): ?string
    {
        if (! $rol) {
            return null;
        }

        $docente = DB::table('grupos')
            ->join('asignaturas', 'grupos.asignatura_id', '=', 'asignaturas.id')
            ->join('docentes', 'grupos.docente_id', '=', 'docentes.id')
            ->where('asignaturas.codigo', $rol->materia_codigo)
            ->where('grupos.sede_id', $rol->sede_id)
            ->where('grupos.carrera_id', $rol->carrera_id)
            ->where('grupos.nombre', $rol->grupo)
            ->where('grupos.estado', 'ACTIVO')
            ->whereNull('grupos.deleted_at')
            ->first(['docentes.nombre', 'docentes.apellido']);

        return $docente ? trim(($docente->nombre ?? '').' '.($docente->apellido ?? '')) : null;
    }

    private function ensureAttemptOpen(VirtualExamAttempt $attempt, bool $throw = true): bool
    {
        $this->refreshExpiredSession($attempt->session);
        $attempt->refresh();

        if (! in_array($attempt->estado, ['INGRESADO'], true)) {
            if ($throw) {
                throw ValidationException::withMessages(['attempt' => 'El intento ya fue finalizado.']);
            }
            return false;
        }

        if ($attempt->session->estado !== 'EJECUCION' || now()->gte($attempt->session->finaliza_en)) {
            $this->finalizeAttempt($attempt, 'AUTO_CERRADO');
            if ($throw) {
                throw ValidationException::withMessages(['tiempo' => 'El tiempo del examen finalizo.']);
            }
            return false;
        }

        return true;
    }

    private function recalculateAttempt(VirtualExamAttempt $attempt): void
    {
        $answers = $attempt->answers()->get();
        $attempt->update([
            'respuestas_total' => $answers->count(),
            'correctas_total' => $answers->where('es_correcta', true)->count(),
        ]);
    }

    private function finalizeAttempt(VirtualExamAttempt $attempt, string $state): void
    {
        $this->recalculateAttempt($attempt);
        $attempt->refresh();
        $pattern = $attempt->answers()
            ->orderBy('numero_pregunta')
            ->get()
            ->map(fn ($answer) => [
                'numero' => $answer->numero_pregunta,
                'marcada' => $answer->respuesta_marcada,
                'correcta' => $answer->respuesta_correcta,
                'es_correcta' => $answer->es_correcta,
            ])
            ->values()
            ->all();

        $attempt->update([
            'estado' => in_array($state, self::ATTEMPT_STATES, true) ? $state : 'FINALIZADO',
            'finalizado_en' => now(),
            'patron_estudiante' => $pattern,
        ]);
    }

    private function closeSession(VirtualExamSession $session, string $attemptState): void
    {
        $session->attempts()->where('estado', 'INGRESADO')->get()->each(function ($attempt) use ($attemptState) {
            $this->finalizeAttempt($attempt, $attemptState);
        });

        $session->update([
            'estado' => 'REALIZADO',
            'cerrado_en' => now(),
        ]);
    }

    private function refreshExpiredSession(?VirtualExamSession $session): void
    {
        if (! $session || $session->estado !== 'EJECUCION' || ! $session->finaliza_en) {
            return;
        }

        if (now()->gte($session->finaliza_en)) {
            $this->closeSession($session, 'AUTO_CERRADO');
            $session->refresh();
        }
    }

    private function scheduledStartAt(RolExamen $rolExamen): ?Carbon
    {
        if (! $rolExamen->fecha || ! $rolExamen->hora_inicio) {
            return null;
        }

        return Carbon::parse($rolExamen->fecha->format('Y-m-d').' '.$rolExamen->hora_inicio);
    }

    private function waitingPayload(VirtualExamSession $session, ?Carbon $scheduledAt): array
    {
        return [
            'waiting' => true,
            'approval_pending' => false,
            'message' => 'El examen aun no fue iniciado por el docente.',
            'server_time' => now()->toDateTimeString(),
            'starts_at' => optional($scheduledAt)?->toDateTimeString(),
            'starts_at_human' => $scheduledAt ? $scheduledAt->format('d/m/Y H:i') : null,
            'remaining_seconds_to_start' => $scheduledAt
                ? max(0, now()->diffInSeconds($scheduledAt, false))
                : null,
            'exam' => [
                'materia' => $session->rolExamen?->materia_nombre,
                'codigo' => $session->rolExamen?->materia_codigo,
                'parcial' => $session->rolExamen?->tipo_examen,
                'grupo' => $session->rolExamen?->grupo,
            ],
        ];
    }

    private function approvalPendingPayload(VirtualExamSession $session, VirtualExamRoster $roster): array
    {
        $session->loadMissing('rolExamen');
        $scheduledAt = $this->scheduledStartAt($session->rolExamen);

        return [
            'waiting' => true,
            'approval_pending' => true,
            'message' => 'Tu registro fue recibido. Espera la aceptacion del docente para acceder al examen.',
            'server_time' => now()->toDateTimeString(),
            'starts_at' => optional($scheduledAt)?->toDateTimeString(),
            'starts_at_human' => $scheduledAt ? $scheduledAt->format('d/m/Y H:i') : null,
            'remaining_seconds_to_start' => $scheduledAt ? max(0, now()->diffInSeconds($scheduledAt, false)) : null,
            'student' => [
                'codigo' => $roster->codigo_estudiante,
                'nombre' => $roster->nombre_estudiante,
                'validacion' => $roster->metadata['validacion'] ?? 'PENDIENTE',
            ],
            'exam' => [
                'materia' => $session->rolExamen?->materia_nombre,
                'codigo' => $session->rolExamen?->materia_codigo,
                'parcial' => $session->rolExamen?->tipo_examen,
                'grupo' => $session->rolExamen?->grupo,
            ],
        ];
    }

}
