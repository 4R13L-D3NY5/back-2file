<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AsignaturaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BancoPreguntaController;
use App\Http\Controllers\BibliografiaController;
use App\Http\Controllers\CarreraController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GrupoController;
use App\Http\Controllers\GruposExternoController;
use App\Http\Controllers\PlanificacionController;
use App\Http\Controllers\PlanificacionSemestralController;
use App\Http\Controllers\GeneracionManualController;

// Public Routes
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login');
Route::post('/register', [AuthController::class, 'register']);

// Programas Analíticos (público con token estático)
// Programas Analíticos (público con token estático)
Route::get('/programas-analiticos', [\App\Http\Controllers\AsignaturaController::class, 'programasAnaliticos']);
Route::get('/export/documentacion-carrera', [\App\Http\Controllers\AsignaturaController::class, 'documentacionCarrera']);
Route::get('/export/documentacion-asignatura', [\App\Http\Controllers\AsignaturaController::class, 'documentacionAsignatura']);
Route::get('/reportes/semanal/print', [\App\Http\Controllers\ReporteController::class, 'exportWeeklyReportHtml']);
Route::get('/asignaturas/{id}/template-personal', [AsignaturaController::class, 'templatePersonal']);
Route::get('/rol-examenes/{id}/archivo-examen/{filename}', [\App\Http\Controllers\RolExamenController::class, 'previewExamen'])
    ->middleware('signed')
    ->name('rol-examenes.preview-examen');
Route::get('/rol-examenes/{id}/archivo-patron/{tipo}/{filename}', [\App\Http\Controllers\RolExamenController::class, 'previewPatron'])
    ->middleware('signed')
    ->name('rol-examenes.preview-patron');

Route::prefix('examen-virtual')->group(function () {
    Route::post('/access', [\App\Http\Controllers\VirtualExamController::class, 'access'])->middleware('throttle:30,1');
    Route::post('/answers', [\App\Http\Controllers\VirtualExamController::class, 'saveAnswers'])->middleware('throttle:120,1');
    Route::post('/finish', [\App\Http\Controllers\VirtualExamController::class, 'finish'])->middleware('throttle:30,1');
    Route::get('/patron/{downloadToken}', [\App\Http\Controllers\VirtualExamController::class, 'downloadStudentPattern'])->middleware('throttle:30,1');
});

// Debug endpoint for checking API data (temporary)
Route::get('/debug/plan-n-data', function (Illuminate\Http\Request $request) {
    $gestion = $request->input('gestion', '1-2026');
    $carrera = $request->input('carrera', 'carenl');
    $sede = (int) $request->input('sede', 1);
    $codigo = $request->input('codigo');
    
    $service = app()->make('App\Services\GruposExternoService');
    $data = $service->listarMateriasPlanN($gestion, $carrera, $sede);
    
    $filtered = array_map(function ($m) {
        return [
            'codigo' => $m['codigo'],
            'nombre' => $m['nombre'],
            'semestre' => $m['semestre'],
            'plan_estudios' => $m['plan_estudios'],
            'docentes' => $m['docentes']
        ];
    }, $data);
    
    return response()->json([
        'success' => true,
        'params' => compact('gestion', 'carrera', 'sede', 'codigo'),
        'total' => count($data),
        'data' => $filtered,
        'search_result' => $codigo ? array_filter($data, fn($m) => $m['codigo'] === $codigo) : null
    ]);
});

// Debug endpoint to force refresh cache and get raw data
Route::get('/debug/force-refresh', function (Illuminate\Http\Request $request) {
    $gestion = $request->input('gestion', '1-2026');
    $carrera = $request->input('carrera', 'carenl');
    $sede = (int) $request->input('sede', 1);
    
    $service = app()->make('App\Services\GruposExternoService');
    $service->limpiarCache($gestion, $carrera, $sede);
    
    // Bypass cache and directly fetch from API
    $cacheKey = "grupos_externos_plan_n_{$gestion}_{$carrera}_{$sede}";
    \Illuminate\Support\Facades\Cache::forget($cacheKey);
    
    $data = $service->listarMateriasPlanN($gestion, $carrera, $sede);
    
    return response()->json([
        'success' => true,
        'params' => compact('gestion', 'carrera', 'sede'),
        'total' => count($data),
        'data' => $data
    ]);
});
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/update-profile', [AuthController::class, 'updateProfile']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // Carreras
    Route::apiResource('carreras', CarreraController::class)->only(['store', 'update', 'destroy'])->middleware('role:SUPER_ADMIN');
    Route::get('/carreras/{id}', [CarreraController::class, 'show']); // pública para autenticados

    // Mallas Curriculares
    Route::get('/mallas-curriculares', [\App\Http\Controllers\MallaCurricularController::class, 'getMallas']);

    // Asignaturas
    Route::get('/asignaturas', [AsignaturaController::class, 'index']);
    Route::post('/asignaturas', [AsignaturaController::class, 'store']);
    Route::get('/asignaturas/master/{carrera_id}', [AsignaturaController::class, 'masterPorCarrera']);
    Route::post('/asignaturas/asignar', [AsignaturaController::class, 'asignarMasivo']);
    Route::get('/asignaturas/{id}', [AsignaturaController::class, 'show']);
    Route::put('/asignaturas/{id}', [AsignaturaController::class, 'update']);
    Route::put('/asignaturas/{id}/estado', [AsignaturaController::class, 'cambiarEstado']);
    Route::delete('/asignaturas/{id}', [AsignaturaController::class, 'destroy']);
    Route::post('/asignaturas/{id}/docentes', [AsignaturaController::class, 'assignDocentes']);
    Route::post('/asignaturas/{id}/import-word', [AsignaturaController::class, 'importWord']);
    Route::post('/asignaturas/{id}/import-excel', [AsignaturaController::class, 'importExcel']);
    Route::post('/asignaturas/{id}/import-plan-clase', [AsignaturaController::class, 'importPlanClase']);
    Route::post('/asignaturas/{id}/import-personal', [AsignaturaController::class, 'importPersonal']);
    Route::post('/asignaturas/{id}/import-cronograma', [AsignaturaController::class, 'importCronograma']);


    // Docentes
    Route::post('/docentes/sync', [App\Http\Controllers\DocenteController::class, 'sync']);
    Route::get('/docentes-simple', [App\Http\Controllers\DocenteController::class, 'listSimple']); // Endpoint ligero para selectores
    Route::get('/docentes', [App\Http\Controllers\DocenteController::class, 'index']);

    // Grupos
    Route::get('grupos-flat', [GrupoController::class, 'flatIndex']);  // Lista plana de grupos reales (para CRUD admin)
    Route::get('grupos', [GrupoController::class, 'index']);
    Route::get('grupos/{id}', [GrupoController::class, 'show']);
    Route::post('grupos', [GrupoController::class, 'store'])->middleware('role:SUPER_ADMIN');
    Route::put('grupos/{id}', [GrupoController::class, 'update'])->middleware('role:SUPER_ADMIN');
    Route::delete('grupos/{id}', [GrupoController::class, 'destroy'])->middleware('role:SUPER_ADMIN');

    // Horarios
    Route::get('horarios', [\App\Http\Controllers\HorarioController::class, 'index']);
    Route::get('horarios/{id}', [\App\Http\Controllers\HorarioController::class, 'show']);
    Route::post('horarios', [\App\Http\Controllers\HorarioController::class, 'store'])->middleware('role:SUPER_ADMIN');
    Route::put('horarios/{id}', [\App\Http\Controllers\HorarioController::class, 'update'])->middleware('role:SUPER_ADMIN');
    Route::delete('horarios/{id}', [\App\Http\Controllers\HorarioController::class, 'destroy'])->middleware('role:SUPER_ADMIN');

    // Aulas (CRUD completo)
    Route::get('aulas',        [\App\Http\Controllers\AulaController::class, 'index']);
    Route::post('aulas',       [\App\Http\Controllers\AulaController::class, 'store'])->middleware('role:SUPER_ADMIN');
    Route::put('aulas/{id}',   [\App\Http\Controllers\AulaController::class, 'update'])->middleware('role:SUPER_ADMIN');
    Route::delete('aulas/{id}',[\App\Http\Controllers\AulaController::class, 'destroy'])->middleware('role:SUPER_ADMIN');

    // Bloques (CRUD completo)
    Route::get('bloques',        [\App\Http\Controllers\BloqueController::class, 'index']);
    Route::post('bloques',       [\App\Http\Controllers\BloqueController::class, 'store'])->middleware('role:SUPER_ADMIN');
    Route::put('bloques/{id}',   [\App\Http\Controllers\BloqueController::class, 'update'])->middleware('role:SUPER_ADMIN');
    Route::delete('bloques/{id}',[\App\Http\Controllers\BloqueController::class, 'destroy'])->middleware('role:SUPER_ADMIN');

    // Grupos Externos (API externa)
    Route::get('grupos-externo', [GruposExternoController::class, 'index']);
    Route::get('grupos-externo/plan-n', [GruposExternoController::class, 'planN']);
    Route::get('grupos-externo/comparar-asignatura', [GruposExternoController::class, 'compararAsignatura']);
    Route::get('grupos-externo/buscar-carpeta', [GruposExternoController::class, 'buscarCarpeta']);
    Route::get('grupos-externo/detalle-con-horarios', [GruposExternoController::class, 'detalleConHorarios']);
    Route::post('grupos-externo/refresh', [GruposExternoController::class, 'refresh']);
    // Gestión de grupos locales
    Route::post('grupos-externo/quitar-grupo-docente', [GruposExternoController::class, 'quitarGrupoDocente']);
    Route::post('grupos-externo/asignar-grupo-docente', [GruposExternoController::class, 'asignarGrupoDocente']);
    Route::post('grupos-externo/importar-desde-planning', [GruposExternoController::class, 'importarDesdePlanning']);

    // Stats
    Route::get('admin/stats', [DashboardController::class, 'index']);
    Route::get('admin/dashboard/director', [DashboardController::class, 'director']);

    // Planificación (Temas y Subniveles)
    Route::prefix('planificacion')->group(function () {
        // Unidades
        Route::post('/asignaturas/{id}/unidades', [PlanificacionController::class, 'storeUnidad']);
        Route::put('/unidades/{id}', [PlanificacionController::class, 'updateUnidad']);
        Route::delete('/unidades/{id}', [PlanificacionController::class, 'destroyUnidad']);

        // Temas
        Route::post('/unidades/{id}/temas', [PlanificacionController::class, 'storeTema']);
        Route::post('/temas/{id}/move', [PlanificacionController::class, 'moveTema']);
        Route::delete('/temas/{id}', [PlanificacionController::class, 'destroyTema']);

        // Logros Esperados
        Route::post('/temas/{id}/logros', [PlanificacionController::class, 'storeLogro']);
        Route::put('/logros/{id}', [PlanificacionController::class, 'updateLogro']);
        Route::delete('/logros/{id}', [PlanificacionController::class, 'destroyLogro']);

        // Indicadores
        Route::post('/logros/{id}/indicadores', [PlanificacionController::class, 'storeIndicador']);
        Route::put('/indicadores/{id}', [PlanificacionController::class, 'updateIndicador']);
        Route::delete('/indicadores/{id}', [PlanificacionController::class, 'destroyIndicador']);

        // Contenidos (Saberes) - Corregido updateContenido -> updateTema
        Route::put('/temas/{id}/contenido', [PlanificacionController::class, 'updateTema']);
        Route::get('/temas/{id}/full', [PlanificacionController::class, 'getFullTema']);

        // Estrategias
        Route::post('/temas/{id}/estrategias', [PlanificacionController::class, 'storeEstrategia']);
        Route::delete('/estrategias/{id}', [PlanificacionController::class, 'destroyEstrategia']);

        // Evaluaciones
        Route::post('/temas/{id}/evaluaciones', [PlanificacionController::class, 'storeEvaluacion']);
        Route::delete('/evaluaciones/{id}', [PlanificacionController::class, 'destroyEvaluacion']);

        // Secuencias
        Route::post('/temas/{id}/secuencias', [PlanificacionController::class, 'storeSecuencia']);
        Route::delete('/secuencias/{id}', [PlanificacionController::class, 'destroySecuencia']);

        // Logros
        Route::post('/temas/{id}/logros', [PlanificacionController::class, 'storeLogro']);
        Route::delete('/logros/{id}', [PlanificacionController::class, 'destroyLogro']);

        // Indicadores
        Route::post('/logros/{id}/indicadores', [PlanificacionController::class, 'storeIndicador']);
        Route::delete('/indicadores/{id}', [PlanificacionController::class, 'destroyIndicador']);
    });

    // Banco de Preguntas
    Route::prefix('banco-preguntas')->group(function () {
        Route::get('/', [BancoPreguntaController::class, 'index']); // ?logro_id=X
        Route::get('/stats', [BancoPreguntaController::class, 'getStats']);
        Route::get('/image/{filename}', [BancoPreguntaController::class, 'showImage']);
        Route::get('/logo-unitepc', [BancoPreguntaController::class, 'getLogo']);
        Route::post('/', [BancoPreguntaController::class, 'store']);
        Route::post('/import', [BancoPreguntaController::class, 'import']);
        Route::post('/save-config', [BancoPreguntaController::class, 'saveConfig']);
        Route::post('/bulk-delete', [BancoPreguntaController::class, 'destroyByFiltro']);
        Route::post('/{id}', [BancoPreguntaController::class, 'update']);
        Route::delete('/{id}', [BancoPreguntaController::class, 'destroy']);
    });

    // Generaciones Manuales (Registro de Auditoría)
    Route::prefix('generaciones-manuales')->group(function () {
        Route::get('/', [GeneracionManualController::class, 'index']);
        Route::post('/', [GeneracionManualController::class, 'store'])
            ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::post('/{id}/generate-package', [GeneracionManualController::class, 'generatePackage'])
            ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::put('/{id}/estado', [GeneracionManualController::class, 'updateEstado'])
            ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::post('/{id}/upload-archivos', [GeneracionManualController::class, 'uploadArchivos'])
            ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::get('/{id}/download-examen', [GeneracionManualController::class, 'downloadExamen']);
        Route::get('/{id}/download-patron-pdf', [GeneracionManualController::class, 'downloadPatronPdf']);
        Route::get('/{id}/download-patron-xlsx', [GeneracionManualController::class, 'downloadPatronXlsx']);
        Route::delete('/{id}', [GeneracionManualController::class, 'destroy'])
            ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
    });

    // Planificación Semestral (Nuevo Módulo)
    Route::prefix('planificacion-semestral')->group(function () {
        // Obtener todo (Config + Horarios + Sesiones)
        Route::get('/{asignaturaId}', [PlanificacionSemestralController::class, 'index']);

        // Guardar Configuración (Fechas) + Horarios
        Route::post('/{asignaturaId}/config', [PlanificacionSemestralController::class, 'saveConfig']);

        // Guardar "Grid" de Sesiones (Generado o Editado)
        Route::post('/{asignaturaId}/sesiones', [PlanificacionSemestralController::class, 'savePlanificacion']);

        // Generar Automáticamente (20 semanas)
        Route::post('/{asignaturaId}/generar', [PlanificacionSemestralController::class, 'generarPlanificacion']);

        // Copiar de otra asignatura
        Route::post('/{asignaturaId}/copiar', [PlanificacionSemestralController::class, 'copiarPlanificacion']);

        // Seguimiento de Clase (Control de Clase) - uses 'seguimientos' table
        Route::post('/seguimiento', [PlanificacionSemestralController::class, 'updateSeguimiento']);
    });

    // Bibliografía (General)
    Route::resource('bibliografias', BibliografiaController::class)->except(['create', 'edit', 'show']);

    // Module 7: Evaluaciones
    Route::prefix('evaluaciones')->group(function () {
        Route::get('/', [\App\Http\Controllers\EvaluacionController::class, 'index']); // ?asignatura_id=X
        Route::post('/', [\App\Http\Controllers\EvaluacionController::class, 'store']); // Create & Generate
        
        // Configuraciones de Evaluaciones (Nacional/Sede/Carrera)
        Route::get('/config', [\App\Http\Controllers\EvaluacionConfiguracionController::class, 'obtenerConfiguracion']);
        Route::post('/config', [\App\Http\Controllers\EvaluacionConfiguracionController::class, 'guardarConfiguracion']);

        // Configuración de Tiempos Nacional
        Route::get('/tiempos', [\App\Http\Controllers\EvaluacionTiempoController::class, 'index']);
        Route::post('/tiempos', [\App\Http\Controllers\EvaluacionTiempoController::class, 'store']);
    });

    // Roles & Users
    Route::apiResource('roles', \App\Http\Controllers\RolController::class);
    Route::apiResource('usuarios', \App\Http\Controllers\UserController::class);
    Route::post('/usuarios/{id}/reset-password', [\App\Http\Controllers\UserController::class, 'resetPassword']);
    Route::apiResource('sedes', \App\Http\Controllers\SedeController::class)->only(['index', 'show']);
    Route::post('/sedes', [\App\Http\Controllers\SedeController::class, 'store'])->middleware('role:SUPER_ADMIN');
    Route::put('/sedes/{id}', [\App\Http\Controllers\SedeController::class, 'update'])->middleware('role:SUPER_ADMIN');
    Route::delete('/sedes/{id}', [\App\Http\Controllers\SedeController::class, 'destroy'])->middleware('role:SUPER_ADMIN');
    Route::apiResource('campus', \App\Http\Controllers\CampusController::class)->middleware('national.admin');
    Route::get('/campus/{id}/carreras', [\App\Http\Controllers\CampusController::class, 'obtenerCarreras'])->middleware('national.admin');
    Route::post('/campus/{id}/carreras', [\App\Http\Controllers\CampusController::class, 'asignarCarreras'])->middleware('national.admin');
    Route::delete('/campus/{id}/carreras/{carreraId}', [\App\Http\Controllers\CampusController::class, 'deasignarCarrera'])->middleware('national.admin');
    
    // Evaluadores
    Route::get('/evaluadores', [\App\Http\Controllers\CampusController::class, 'obtenerEvaluadores'])->middleware('national.admin');
    Route::get('/evaluadores/disponibles', [\App\Http\Controllers\CampusController::class, 'evaluadoresDisponibles'])->middleware('national.admin');
    Route::post('/campus/{id}/evaluadores', [\App\Http\Controllers\CampusController::class, 'asignarEvaluador'])->middleware('national.admin');
    Route::delete('/campus/{id}/evaluadores/{userId}', [\App\Http\Controllers\CampusController::class, 'removerEvaluador'])->middleware('national.admin');
    Route::apiResource('docentes', \App\Http\Controllers\DocenteController::class);
    Route::get('/my-subjects', [\App\Http\Controllers\DocenteController::class, 'mySubjects']);

    // Cascading Filter Endpoints
    Route::get('sedes/{id}/carreras', [\App\Http\Controllers\SedeController::class, 'carreras']);
    Route::get('carreras', [\App\Http\Controllers\CarreraController::class, 'index']);
    Route::get('carreras/{id}/asignaturas', [\App\Http\Controllers\CarreraController::class, 'asignaturas']);
    Route::get('carreras/{id}/semestres', [\App\Http\Controllers\CarreraController::class, 'semestres']);
    Route::put('carreras/{id}/contexto', [\App\Http\Controllers\CarreraController::class, 'updateContexto']);

    // Module 7b: Vista Patrón de Examen
    Route::get('/examenes-generados/{id}/patron', [\App\Http\Controllers\EvaluacionController::class, 'patron']);

    // Rol de Exámenes (Director de Carrera)
    Route::prefix('rol-examenes')->group(function () {
        Route::get('/', [\App\Http\Controllers\RolExamenController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\RolExamenController::class, 'store']);
        Route::post('/upload', [\App\Http\Controllers\RolExamenController::class, 'upload']);
        Route::post('/bulk-delete', [\App\Http\Controllers\RolExamenController::class, 'destroyAll']);
        Route::get('/template', [\App\Http\Controllers\RolExamenController::class, 'template']);
        Route::get('/materia/{materiaId}', [\App\Http\Controllers\RolExamenController::class, 'getByMateria']);
          Route::put('/{id}', [\App\Http\Controllers\RolExamenController::class, 'update'])
              ->middleware('role:DIRECTOR_CARRERA,EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
          Route::post('/{id}/restore-generated-package', [\App\Http\Controllers\RolExamenController::class, 'restoreGeneratedPackage'])
              ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
          Route::post('/{id}/generate-package', [\App\Http\Controllers\RolExamenController::class, 'generatePackage'])
              ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
          Route::post('/{id}/pattern-verifier', [\App\Http\Controllers\RolExamenController::class, 'patternVerifier'])
              ->middleware('role:ADMIN,SUPER_ADMIN');
          Route::get('/{id}/download-examen-url', [\App\Http\Controllers\RolExamenController::class, 'signedExamenUrl']);
          Route::get('/{id}/download-patron-url', [\App\Http\Controllers\RolExamenController::class, 'signedPatronUrl']);
          Route::get('/{id}/download-examen', [\App\Http\Controllers\RolExamenController::class, 'downloadExamen']);
          Route::get('/{id}/download-patron', [\App\Http\Controllers\RolExamenController::class, 'downloadPatron']);
          Route::delete('/{id}', [\App\Http\Controllers\RolExamenController::class, 'destroy'])
              ->middleware('role:DIRECTOR_CARRERA,EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::post('/{id}/upload-examen', [\App\Http\Controllers\RolExamenController::class, 'uploadExamen'])
            ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::post('/{id}/upload-patron', [\App\Http\Controllers\RolExamenController::class, 'uploadPatron'])
            ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
    });

    Route::prefix('virtual-exams')->group(function () {
        Route::get('/', [\App\Http\Controllers\VirtualExamController::class, 'index'])
            ->middleware('role:DOCENTE,EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::get('/{session}', [\App\Http\Controllers\VirtualExamController::class, 'show'])
            ->middleware('role:DOCENTE,EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::post('/rol-examenes/{rolExamen}/generate', [\App\Http\Controllers\VirtualExamController::class, 'generate'])
            ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::post('/{session}/roster', [\App\Http\Controllers\VirtualExamController::class, 'uploadRoster'])
            ->middleware('role:DOCENTE,EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::post('/{session}/roster/{roster}/status', [\App\Http\Controllers\VirtualExamController::class, 'updateRosterStatus'])
            ->middleware('role:DOCENTE,EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::post('/{session}/start', [\App\Http\Controllers\VirtualExamController::class, 'start'])
            ->middleware('role:DOCENTE,ADMIN,SUPER_ADMIN');
        Route::post('/{session}/close', [\App\Http\Controllers\VirtualExamController::class, 'close'])
            ->middleware('role:DOCENTE,EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::get('/{session}/export-remark', [\App\Http\Controllers\VirtualExamController::class, 'exportRemark'])
            ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::post('/{session}/mark-uploaded', [\App\Http\Controllers\VirtualExamController::class, 'markUploaded'])
            ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,ADMIN,SUPER_ADMIN');
        Route::post('/{session}/reset', [\App\Http\Controllers\VirtualExamController::class, 'reset'])
            ->middleware('role:ADMIN,SUPER_ADMIN');
    });

    // Materias Comunes
    Route::prefix('materias-comunes')->group(function () {
        Route::get('/', [\App\Http\Controllers\MateriaComunController::class, 'index']);
        Route::get('/mis-asignaturas', [\App\Http\Controllers\MateriaComunController::class, 'misAsignaturas']);
        Route::get('/candidates', [\App\Http\Controllers\MateriaComunController::class, 'candidates']);
        Route::post('/link', [\App\Http\Controllers\MateriaComunController::class, 'link']);
        Route::post('/unlink/{id}', [\App\Http\Controllers\MateriaComunController::class, 'unlink']);
        Route::get('/check-integrity/{token}', [\App\Http\Controllers\MateriaComunController::class, 'checkIntegrity']);
    });
    // Clas Monitoring (Control de Clase)
    Route::post('/cronogramas/find-or-create', [\App\Http\Controllers\CronogramaController::class, 'findOrCreate']);
    Route::put('/cronogramas/{id}/seguimiento', [\App\Http\Controllers\CronogramaController::class, 'updateSeguimiento']);

    // Reportes (Nuevo)
    Route::get('/reportes/auditoria-semanal', [\App\Http\Controllers\ReporteController::class, 'getAuditoriaSemanal']);
    Route::get('/reportes/director', [\App\Http\Controllers\ReporteController::class, 'index']);
    Route::get('/reportes/metrics', [\App\Http\Controllers\ReporteController::class, 'getDashboardMetrics']);
    Route::get('/reportes/director/weekly', [\App\Http\Controllers\ReporteController::class, 'generateWeeklyReport']);
    Route::get('/reportes/avance-general', [\App\Http\Controllers\ReporteController::class, 'avanceGeneral']);
    Route::get('/reportes/matriz-control', [\App\Http\Controllers\ReporteController::class, 'getMatrizControl']);
    Route::get('/reportes/auditoria-25', [\App\Http\Controllers\ReporteController::class, 'getAuditoria25']);
    Route::get('/reportes/evaluaciones', [\App\Http\Controllers\ReporteEvaluacionController::class, 'index'])
        ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,DIRECTOR_CARRERA,DIRECCION_ACADEMICA,VICERRECTOR_SEDE,VICERRECTOR_NACIONAL,ADMIN,SUPER_ADMIN');
    Route::get('/reportes/evaluaciones/cobertura-banco', [\App\Http\Controllers\ReporteEvaluacionController::class, 'coberturaBanco'])
        ->middleware('role:EVALUACIONES,RESPONSABLE_EVALUACIONES,DIRECTOR_CARRERA,DIRECCION_ACADEMICA,VICERRECTOR_SEDE,VICERRECTOR_NACIONAL,ADMIN,SUPER_ADMIN');
    
    Route::get('/reportes/semanal/draft', [\App\Http\Controllers\ReporteController::class, 'getWeeklyReportDraft']);
    Route::post('/reportes/semanal', [\App\Http\Controllers\ReporteController::class, 'storeWeeklyReport']);
    Route::post('/reportes/semanal/bulk-verdes', [\App\Http\Controllers\ReporteController::class, 'bulkStoreVerdes']);

    // Reportes Nivel 2: Director de Carrera
    Route::get('/reportes/docentes-sin-avance', [\App\Http\Controllers\ReporteController::class, 'docentesSinAvance']);
    Route::get('/reportes/docentes-criticos', [\App\Http\Controllers\ReporteController::class, 'docentesCriticos']);
    Route::get('/reportes/ranking-docentes', [\App\Http\Controllers\ReporteController::class, 'rankingDocentes']);
    Route::get('/reportes/asignaturas-sin-cronograma', [\App\Http\Controllers\ReporteController::class, 'asignaturasSinCronograma']);

    // Reportes Nivel 3: Dirección Académica
    Route::get('/reportes/carreras-criticas', [\App\Http\Controllers\ReporteController::class, 'carrerasCriticas']);
    Route::get('/reportes/ranking-carreras', [\App\Http\Controllers\ReporteController::class, 'rankingCarreras']);
    Route::get('/reportes/resumen-sede', [\App\Http\Controllers\ReporteController::class, 'resumenEjecutivoSede']);

    // Reportes Nivel 4: Vicerrector
    Route::get('/reportes/sedes-criticas', [\App\Http\Controllers\ReporteController::class, 'sedesCriticas']);
    Route::get('/reportes/ranking-sedes', [\App\Http\Controllers\ReporteController::class, 'rankingSedes']);
    Route::get('/reportes/director/resumen-carrera', [\App\Http\Controllers\ReporteController::class, 'getResumenCarreraSemanal']);
    Route::get('/reportes/director/reincidentes', [\App\Http\Controllers\ReporteController::class, 'getReincidentesSemanal']);

    Route::get('/reportes/alertas-rojas', [\App\Http\Controllers\ReporteController::class, 'alertasRojas']);
    Route::get('/reportes/auditorias-vicerrector', [\App\Http\Controllers\ReporteController::class, 'auditoriasVicerrector']);

    // Dirección Académica Dashboard
    Route::get('/direccion/stats', [\App\Http\Controllers\ReporteController::class, 'direccionStats']);
    Route::get('/seguimiento-semanal/check-status', [\App\Http\Controllers\SeguimientoSemanalController::class, 'checkStatus']);
    Route::post('/seguimiento-semanal/bulk-generate', [\App\Http\Controllers\SeguimientoSemanalController::class, 'bulkGenerate']);
    Route::apiResource('seguimiento-semanal', \App\Http\Controllers\SeguimientoSemanalController::class);

    // Evaluaciones (Exámenes Programados)
    Route::get('/mis-evaluaciones', [\App\Http\Controllers\EvaluacionController::class, 'misEvaluaciones']);

    // Auditorías
    Route::get('/auditorias', [\App\Http\Controllers\AuditoriaController::class, 'index']);
    // Auditoría de Backups (Comparación)
    Route::get('/backups/list', [\App\Http\Controllers\BackupComparisonController::class, 'listBackups']);
    Route::get('/backups/search', [\App\Http\Controllers\BackupComparisonController::class, 'searchBackupSubjects']);
    Route::get('/backups/search-current', [\App\Http\Controllers\BackupComparisonController::class, 'searchCurrentSubjects']);
    Route::post('/backups/compare', [\App\Http\Controllers\BackupComparisonController::class, 'compareSubject']);
    Route::post('/backups/restore', [\App\Http\Controllers\BackupComparisonController::class, 'restoreSegment']);
 
    // Manual Registration (API Planning)
    Route::get('/manual-registration/fetch', [\App\Http\Controllers\ManualRegistrationController::class, 'fetchFromPlanning']);
    Route::post('/manual-registration/store', [\App\Http\Controllers\ManualRegistrationController::class, 'storeManual']);
    Route::put('/manual-registration/docente', [\App\Http\Controllers\ManualRegistrationController::class, 'updateDocente']);

    // Planning Cache (sincronización y lectura de datos API Planning guardados en BD)
    Route::post('/planning/sincronizar-cochabamba', [\App\Http\Controllers\PlanningCacheController::class, 'sincronizarCochabamba']);
    Route::post('/planning/sincronizar-carrera',    [\App\Http\Controllers\PlanningCacheController::class, 'sincronizarCarrera']);
    Route::get('/planning/cache',                   [\App\Http\Controllers\PlanningCacheController::class, 'getCache']);
    Route::get('/planning/sync-status',             [\App\Http\Controllers\PlanningCacheController::class, 'syncStatus']);

    // Módulo de Sincronización Académica (Solo SUPER_ADMIN)
    Route::prefix('sync')->group(function () {
        Route::post('/carrera',              [\App\Http\Controllers\SyncController::class, 'syncCarrera']);
        Route::post('/sede',                 [\App\Http\Controllers\SyncController::class, 'syncSede']);
        Route::post('/materia',              [\App\Http\Controllers\SyncController::class, 'syncMateria']);
        Route::post('/asignatura',           [\App\Http\Controllers\SyncController::class, 'syncAsignatura']);
        Route::get('/logs',                  [\App\Http\Controllers\SyncController::class, 'getLogs']);
        Route::get('/logs/{id}/diff',        [\App\Http\Controllers\SyncController::class, 'getDiff']);
        Route::post('/resolver-conflictos',  [\App\Http\Controllers\SyncController::class, 'resolverConflicto']);
    });

    // Carga Académica (Gestión Unificada de Docentes, Grupos y Horarios)
    Route::prefix('carga-academica')->group(function () {
        Route::get('/materia', [\App\Http\Controllers\CargaAcademicaController::class, 'getByMateria']);
        Route::get('/carrera', [\App\Http\Controllers\CargaAcademicaController::class, 'getByCarrera']);
        Route::post('/grupo', [\App\Http\Controllers\CargaAcademicaController::class, 'storeGrupo'])->middleware('role:SUPER_ADMIN,ADMIN');
        Route::put('/grupo/{id}', [\App\Http\Controllers\CargaAcademicaController::class, 'updateGrupo'])->middleware('role:SUPER_ADMIN,ADMIN');
        Route::put('/grupo/{id}/docente', [\App\Http\Controllers\CargaAcademicaController::class, 'assignDocente'])->middleware('role:SUPER_ADMIN,ADMIN');
        Route::post('/validar', [\App\Http\Controllers\CargaAcademicaController::class, 'validar']);
        Route::post('/sync', [\App\Http\Controllers\CargaAcademicaController::class, 'syncMateria'])->middleware('role:SUPER_ADMIN,ADMIN');
    });

    // Modulo de Restauracion Academica
    Route::post('/restauracion/extraccion-api', [\App\Http\Controllers\RestauracionAcademicaController::class, 'extraerDesdeApiExterna'])
        ->middleware('role:DIRECTOR_CARRERA,DIRECCION_ACADEMICA,VICERRECTOR_SEDE,VICERRECTOR_NACIONAL,ADMIN,SUPER_ADMIN');
    Route::post('/restauracion/estado-asignaturas', [\App\Http\Controllers\RestauracionAcademicaController::class, 'estadoAsignaturas'])
        ->middleware('role:DIRECTOR_CARRERA,DIRECCION_ACADEMICA,VICERRECTOR_SEDE,VICERRECTOR_NACIONAL,ADMIN,SUPER_ADMIN');
    Route::post('/restauracion/asignatura', [\App\Http\Controllers\RestauracionAcademicaController::class, 'restaurarAsignatura'])
        ->middleware('role:DIRECTOR_CARRERA,DIRECCION_ACADEMICA,VICERRECTOR_SEDE,VICERRECTOR_NACIONAL,ADMIN,SUPER_ADMIN');
    Route::get('/restauracion/exportar-excel', [\App\Http\Controllers\RestauracionAcademicaController::class, 'exportarExcel'])
        ->middleware('role:DIRECTOR_CARRERA,DIRECCION_ACADEMICA,VICERRECTOR_SEDE,VICERRECTOR_NACIONAL,ADMIN,SUPER_ADMIN');
    Route::post('/restauracion/importar-excel', [\App\Http\Controllers\RestauracionAcademicaController::class, 'importarExcel'])
        ->middleware('role:DIRECTOR_CARRERA,DIRECCION_ACADEMICA,VICERRECTOR_SEDE,VICERRECTOR_NACIONAL,ADMIN,SUPER_ADMIN');
    Route::post('/restauracion/exportar-pdf-asignatura', [\App\Http\Controllers\RestauracionAcademicaController::class, 'exportarPdfAsignatura'])
        ->middleware('role:DIRECTOR_CARRERA,DIRECCION_ACADEMICA,VICERRECTOR_SEDE,VICERRECTOR_NACIONAL,ADMIN,SUPER_ADMIN');
    Route::post('/restauracion/exportar-pac-asignatura', [\App\Http\Controllers\RestauracionAcademicaController::class, 'exportarExcelPacAsignatura'])
        ->middleware('role:DIRECTOR_CARRERA,DIRECCION_ACADEMICA,VICERRECTOR_SEDE,VICERRECTOR_NACIONAL,ADMIN,SUPER_ADMIN,DOCENTE');
    Route::post('/restauracion/exportar-plan-clase-asignatura', [\App\Http\Controllers\RestauracionAcademicaController::class, 'exportarExcelPlanClaseAsignatura'])
        ->middleware('role:DIRECTOR_CARRERA,DIRECCION_ACADEMICA,VICERRECTOR_SEDE,VICERRECTOR_NACIONAL,ADMIN,SUPER_ADMIN');

    // Recuperacion de Bancos de Preguntas (Solo SUPER_ADMIN)
    Route::prefix('restauracion/bancos')->middleware('role:SUPER_ADMIN')->group(function () {
        Route::post('/sedes', [\App\Http\Controllers\RestauracionBancosController::class, 'sedes']);
        Route::post('/carreras', [\App\Http\Controllers\RestauracionBancosController::class, 'carreras']);
        Route::post('/materias', [\App\Http\Controllers\RestauracionBancosController::class, 'materias']);
        Route::post('/preview', [\App\Http\Controllers\RestauracionBancosController::class, 'preview']);
        Route::post('/execute', [\App\Http\Controllers\RestauracionBancosController::class, 'execute']);
    });

    Route::prefix('restauracion/bancos-plan')->middleware('role:ADMIN,SUPER_ADMIN')->group(function () {
        Route::post('/sedes', [\App\Http\Controllers\RestauracionBancosController::class, 'sedes']);
        Route::post('/carreras', [\App\Http\Controllers\RestauracionBancosController::class, 'carreras']);
        Route::post('/preview', [\App\Http\Controllers\RestauracionBancosController::class, 'previewPlan']);
        Route::post('/questions', [\App\Http\Controllers\RestauracionBancosController::class, 'questionsPlan']);
        Route::post('/restore', [\App\Http\Controllers\RestauracionBancosController::class, 'restorePlan']);
    });

});
