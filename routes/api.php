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

// Public Routes
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login');
Route::post('/register', [AuthController::class, 'register']);

// Programas Analíticos (público con token estático)
// Programas Analíticos (público con token estático)
Route::get('/programas-analiticos', [\App\Http\Controllers\AsignaturaController::class, 'programasAnaliticos']);
Route::get('/reportes/semanal/print', [\App\Http\Controllers\ReporteController::class, 'exportWeeklyReportHtml']);
Route::get('/asignaturas/{id}/template-personal', [AsignaturaController::class, 'templatePersonal']);
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/update-profile', [AuthController::class, 'updateProfile']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // Carreras
    Route::apiResource('carreras', CarreraController::class)->only(['index', 'update']);

    // Asignaturas
    Route::get('/asignaturas', [AsignaturaController::class, 'index']);
    Route::post('/asignaturas', [AsignaturaController::class, 'store']);
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
    Route::get('/docentes', [App\Http\Controllers\DocenteController::class, 'index']);

    // Grupos
    Route::get('grupos', [GrupoController::class, 'index']);
    Route::get('grupos/{id}', [GrupoController::class, 'show']);

    // Grupos Externos (API externa)
    Route::get('grupos-externo', [GruposExternoController::class, 'index']);
    Route::post('grupos-externo/refresh', [GruposExternoController::class, 'refresh']);

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
        Route::post('/', [BancoPreguntaController::class, 'store']);
        Route::post('/import', [BancoPreguntaController::class, 'import']);
        Route::delete('/{id}', [BancoPreguntaController::class, 'destroy']);
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
    });

    // Roles & Users
    Route::apiResource('roles', \App\Http\Controllers\RolController::class);
    Route::apiResource('usuarios', \App\Http\Controllers\UserController::class);
    Route::post('/usuarios/{id}/reset-password', [\App\Http\Controllers\UserController::class, 'resetPassword']);
    Route::apiResource('sedes', \App\Http\Controllers\SedeController::class);
    Route::apiResource('docentes', \App\Http\Controllers\DocenteController::class);
    Route::get('/my-subjects', [\App\Http\Controllers\DocenteController::class, 'mySubjects']);

    // Cascading Filter Endpoints
    Route::get('sedes/{id}/carreras', [\App\Http\Controllers\SedeController::class, 'carreras']);
    Route::get('carreras/{id}/asignaturas', [\App\Http\Controllers\CarreraController::class, 'asignaturas']);
    Route::get('carreras/{id}/semestres', [\App\Http\Controllers\CarreraController::class, 'semestres']);
    Route::get('carreras/{id}', [\App\Http\Controllers\CarreraController::class, 'show']);
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
        Route::put('/{id}', [\App\Http\Controllers\RolExamenController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\RolExamenController::class, 'destroy']);
    });

    // Materias Comunes
    Route::prefix('materias-comunes')->group(function () {
        Route::get('/', [\App\Http\Controllers\MateriaComunController::class, 'index']);
        Route::get('/mis-asignaturas', [\App\Http\Controllers\MateriaComunController::class, 'misAsignaturas']);
        Route::get('/candidates', [\App\Http\Controllers\MateriaComunController::class, 'candidates']);
        Route::post('/link', [\App\Http\Controllers\MateriaComunController::class, 'link']);
        Route::post('/unlink/{id}', [\App\Http\Controllers\MateriaComunController::class, 'unlink']);
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
    
    Route::get('/reportes/semanal/draft', [\App\Http\Controllers\ReporteController::class, 'getWeeklyReportDraft']);
    Route::post('/reportes/semanal', [\App\Http\Controllers\ReporteController::class, 'storeWeeklyReport']);

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
    Route::post('/backups/compare', [\App\Http\Controllers\BackupComparisonController::class, 'compareSubject']);

});
