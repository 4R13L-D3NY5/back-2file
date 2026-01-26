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
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/register', [AuthController::class, 'register']);
Route::get('/test-publico', [AsignaturaController::class, 'index']); // RUTA TEMPORAL DE PRUEBA

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // Carreras
    Route::apiResource('carreras', CarreraController::class)->only(['index', 'update']);

    // Asignaturas
    Route::post('/asignaturas', [AsignaturaController::class, 'store']);
    Route::get('/asignaturas/{id}', [AsignaturaController::class, 'show']);
    Route::put('/asignaturas/{id}', [AsignaturaController::class, 'update']);
    Route::put('/asignaturas/{id}/estado', [AsignaturaController::class, 'cambiarEstado']);
    Route::delete('/asignaturas/{id}', [AsignaturaController::class, 'destroy']);
    Route::post('/asignaturas/{id}/docentes', [AsignaturaController::class, 'assignDocentes']);
    Route::post('/asignaturas/{id}/import-word', [AsignaturaController::class, 'importWord']);

    // Docentes
    Route::get('/docentes', [App\Http\Controllers\DocenteController::class, 'index']);

    // Grupos
    Route::get('grupos', [GrupoController::class, 'index']);

    // Grupos Externos (API externa)
    Route::get('grupos-externo', [GruposExternoController::class, 'index']);
    Route::post('grupos-externo/refresh', [GruposExternoController::class, 'refresh']);

    // Stats
    Route::get('admin/stats', [DashboardController::class, 'index']);

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
    Route::apiResource('sedes', \App\Http\Controllers\SedeController::class);
    Route::apiResource('docentes', \App\Http\Controllers\DocenteController::class);

    // Cascading Filter Endpoints
    Route::get('sedes/{id}/carreras', [\App\Http\Controllers\SedeController::class, 'carreras']);
    Route::get('carreras/{id}/asignaturas', [\App\Http\Controllers\CarreraController::class, 'asignaturas']);
    Route::get('carreras/{id}/semestres', [\App\Http\Controllers\CarreraController::class, 'semestres']);

    // Module 7b: Vista Patrón de Examen
    Route::get('/examenes-generados/{id}/patron', [\App\Http\Controllers\EvaluacionController::class, 'patron']);

    // Rol de Exámenes (Director de Carrera)
    Route::prefix('rol-examenes')->group(function () {
        Route::get('/', [\App\Http\Controllers\RolExamenController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\RolExamenController::class, 'store']);
        Route::post('/upload', [\App\Http\Controllers\RolExamenController::class, 'upload']);
        Route::get('/template', [\App\Http\Controllers\RolExamenController::class, 'template']);
        Route::get('/materia/{materiaId}', [\App\Http\Controllers\RolExamenController::class, 'getByMateria']);
        Route::put('/{id}', [\App\Http\Controllers\RolExamenController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\RolExamenController::class, 'destroy']);
    });
});
