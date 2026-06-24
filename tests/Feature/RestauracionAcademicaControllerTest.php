<?php

namespace Tests\Feature;

use App\Http\Controllers\RestauracionAcademicaController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RestauracionAcademicaControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createMinimalSchema();
    }

    public function test_restauracion_preserva_identidad_local_y_limpia_contenido_previo(): void
    {
        $carreraId = DB::table('carreras')->insertGetId([
            'nombre' => 'Ingenieria Electronica',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sedeId = DB::table('sedes')->insertGetId([
            'nombre' => 'Cochabamba',
            'codigo' => 'cba',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $asignaturaId = DB::table('asignaturas')->insertGetId([
            'codigo' => 'ELC-222',
            'sigla' => 'ELC-222',
            'nombre' => 'ALGEBRA II',
            'plan_estudios' => 'N',
            'descripcion' => 'Descripcion antigua',
            'justificacion' => 'Justificacion antigua',
            'proposito_general' => 'Proposito antiguo',
            'modificado_localmente' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('asignatura_carrera')->insert([
            'asignatura_id' => $asignaturaId,
            'carrera_id' => $carreraId,
            'sede_id' => $sedeId,
            'semestre' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oldUnitId = DB::table('unidades')->insertGetId([
            'asignatura_id' => $asignaturaId,
            'numero' => '1',
            'titulo' => 'Unidad vieja',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oldTemaId = DB::table('temas')->insertGetId([
            'unidad_id' => $oldUnitId,
            'orden' => 1,
            'titulo' => 'Tema viejo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bibliografias')->insert([
            'asignatura_id' => $asignaturaId,
            'titulo' => 'Bibliografia vieja',
            'tipo' => 'BASICA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/api/restauracion/asignatura', 'POST', [
            'asignatura_id' => $asignaturaId,
            'codigo' => 'ELC-222',
            'nombre' => 'ALGEBRA LINEAL SISTEMAS',
            'carrera_id' => $carreraId,
            'sede_id' => $sedeId,
            'plan_estudios' => 'N',
            'justificacion' => 'Justificacion nueva',
            'unidades' => [
                [
                    'numero' => '1',
                    'titulo' => 'Nueva unidad',
                    'temas' => [
                        [
                            'orden' => 1,
                            'titulo' => 'Nuevo tema',
                        ],
                    ],
                ],
            ],
            'bibliografias' => [
                [
                    'titulo' => 'Bibliografia nueva',
                    'autor' => 'Autor nuevo',
                    'tipo' => 'BASICA',
                ],
            ],
        ]);

        $response = app(RestauracionAcademicaController::class)->restaurarAsignatura($request);

        $this->assertSame(200, $response->getStatusCode());

        $payload = $response->getData(true);
        $this->assertSame('success', $payload['status']);

        $asignatura = DB::table('asignaturas')->where('id', $asignaturaId)->first();

        $this->assertSame('ELC-222', $asignatura->codigo);
        $this->assertSame('ALGEBRA II', $asignatura->nombre);
        $this->assertSame('Justificacion nueva', $asignatura->justificacion);
        $this->assertNull($asignatura->descripcion);
        $this->assertNull($asignatura->proposito_general);

        $newUnitIds = DB::table('unidades')->where('asignatura_id', $asignaturaId)->pluck('id');

        $this->assertCount(1, $newUnitIds);
        $this->assertFalse($newUnitIds->contains($oldUnitId));
        $this->assertDatabaseMissing('temas', ['id' => $oldTemaId]);
        $this->assertDatabaseHas('temas', [
            'unidad_id' => $newUnitIds->first(),
            'titulo' => 'Nuevo tema',
        ]);

        $this->assertDatabaseMissing('bibliografias', [
            'asignatura_id' => $asignaturaId,
            'titulo' => 'Bibliografia vieja',
        ]);
        $this->assertDatabaseHas('bibliografias', [
            'asignatura_id' => $asignaturaId,
            'titulo' => 'Bibliografia nueva',
        ]);
    }

    public function test_exportar_pac_usa_layout_de_la_plantilla_y_es_reimportable(): void
    {
        $asignaturaId = DB::table('asignaturas')->insertGetId([
            'codigo' => 'SIS-423',
            'sigla' => 'SIS-423',
            'nombre' => 'TALLER DE INGENIERIA DE SOFTWARE',
            'plan_estudios' => 'N',
            'estado' => 'EN_PROCESO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payloadAsignatura = [
            'carrera' => ['nombre' => 'LICENCIATURA EN INGENIERIA DE SISTEMAS'],
            'codigo' => 'SIS-423',
            'nombre' => 'TALLER DE INGENIERIA DE SOFTWARE',
            'area_desempenio' => 'AREA DE INGENIERIAS',
            'tipo_curso' => 'OBLIGATORIO',
            'modalidad' => 'PRESENCIAL',
            'semestre' => '8VO',
            'requisitos' => 'NINGUNO',
            'sesiones_semanales_teoricas' => '1',
            'sesiones_semanales_practicas' => '2',
            'docente' => [
                'nombre' => 'HAROLD ROJAS',
                'email' => 'hrojas@unitepc.edu.bo',
                'telefono' => '78311416',
            ],
            'justificacion' => 'Justificacion de prueba.',
            'proposito_general' => 'Proposito de prueba.',
            'competencia_global_especifica' => 'Competencia global.',
            'competencia_asignatura' => 'Competencia asignatura.',
            'elementos_competencia' => ['Elemento uno.', 'Elemento dos.'],
            'metodologia_general' => [
                'aula' => 'Metodologia aula.',
                'simulacion' => 'Metodologia simulacion.',
                'hospital' => 'NO CORRESPONDE',
            ],
            'sistema_evaluacion' => [
                'intro' => 'Intro evaluacion.',
                'diagnostica' => 'Diagnostica.',
                'formativa' => 'Formativa.',
                'sumativa' => 'Sumativa.',
                'ponderacion' => 'Ponderacion.',
            ],
            'reglamento_normativa' => [
                'clase' => 'Reglamento clase.',
            ],
            'bibliografias' => [
                ['tipo' => 'BASICA', 'autor' => 'Autor A', 'titulo' => 'Libro A', 'editorial' => 'Edit A', 'anio' => '2020'],
                ['tipo' => 'COMPLEMENTARIA', 'autor' => 'Autor B', 'titulo' => 'Libro B', 'editorial' => 'Edit B', 'anio' => '2021'],
            ],
        ];

        // EXPORT
        $request = Request::create('/api/restauracion/exportar-pac-asignatura', 'POST', [
            'asignatura' => $payloadAsignatura,
        ]);
        $exportResponse = app(RestauracionAcademicaController::class)->exportarExcelPacAsignatura($request);
        $this->assertSame(200, $exportResponse->getStatusCode());

        $exportContent = $exportResponse->getFile()->getContent();
        $this->assertNotEmpty($exportContent);

        // Verificar layout del Excel generado
        $tmpPath = tempnam(sys_get_temp_dir(), 'pac_test_');
        file_put_contents($tmpPath, $exportContent);
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheet = $spreadsheet->getSheetByName('PAC');
        $this->assertNotNull($sheet);

        $this->assertSame('UNIVERSIDAD TÉCNICA PRIVADA COSMOS', $sheet->getCell('B2')->getValue());
        $this->assertSame('PROGRAMA DE ASIGNATURA', $sheet->getCell('B5')->getValue());
        $this->assertSame('1.- Identificación de la Asignatura', $sheet->getCell('B8')->getValue());
        $this->assertSame('CARRERA:', $sheet->getCell('B10')->getValue());
        $this->assertSame('LICENCIATURA EN INGENIERIA DE SISTEMAS', $sheet->getCell('C10')->getValue());
        $this->assertSame('ÁREA DE DESEMPEÑO:', $sheet->getCell('B12')->getValue());
        $this->assertSame('AREA DE INGENIERIAS', $sheet->getCell('C12')->getValue());
        $this->assertSame('TIPO DE CURSO:', $sheet->getCell('G12')->getValue());
        $this->assertSame('OBLIGATORIO', $sheet->getCell('H12')->getValue());
        $this->assertSame('3.- Justificación de la Asignatura', $sheet->getCell('B23')->getValue());
        $this->assertSame('4.- Propósito General de la Unidad de Formación', $sheet->getCell('B27')->getValue());
        $this->assertSame('6.- Elementos de Competencia', $sheet->getCell('B36')->getValue());
        $this->assertSame('Elemento de competencia 1:', $sheet->getCell('B38')->getValue());

        // IMPORT roundtrip
        $uploadedFile = new \Illuminate\Http\UploadedFile(
            $tmpPath,
            'PAC_SIS-423_test.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $importRequest = new Request();
        $importRequest->files->set('file', $uploadedFile);

        $importResponse = app(\App\Http\Controllers\AsignaturaController::class)->importExcel($importRequest, $asignaturaId);
        $this->assertSame(200, $importResponse->getStatusCode());

        $asignatura = DB::table('asignaturas')->where('id', $asignaturaId)->first();
        $this->assertSame('OBLIGATORIO', $asignatura->tipo_curso);
        $this->assertSame('AREA DE INGENIERIAS', $asignatura->area_desempenio);
        $this->assertSame('PRESENCIAL', $asignatura->modalidad);
        $this->assertSame('NINGUNO', $asignatura->requisitos);
        $this->assertSame(1, $asignatura->sesiones_semanales_teoricas);
        $this->assertSame(2, $asignatura->sesiones_semanales_practicas);
        $this->assertSame('hrojas@unitepc.edu.bo', $asignatura->docente_email);
        $this->assertSame('78311416', $asignatura->docente_telefono);
        $this->assertSame('Justificacion de prueba.', $asignatura->justificacion);
        $this->assertSame('Proposito de prueba.', $asignatura->proposito_general);
        $this->assertSame('Competencia global.', $asignatura->competencia_global_especifica);
        $this->assertSame('Competencia asignatura.', $asignatura->competencia_asignatura);

        $elementos = json_decode($asignatura->elementos_competencia, true);
        $this->assertSame(['Elemento uno.', 'Elemento dos.'], $elementos);

        @unlink($tmpPath);
    }

    public function test_exportar_pac_campos_vacios_usan_placeholder_y_no_contaminan_reimportacion(): void
    {
        $asignaturaId = DB::table('asignaturas')->insertGetId([
            'codigo' => 'SIS-424',
            'sigla' => 'SIS-424',
            'nombre' => 'MATERIA DE PRUEBA',
            'plan_estudios' => 'N',
            'estado' => 'EN_PROCESO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payloadAsignatura = [
            'carrera' => ['nombre' => 'LICENCIATURA EN INGENIERIA DE SISTEMAS'],
            'codigo' => 'SIS-424',
            'nombre' => 'MATERIA DE PRUEBA',
            'area_desempenio' => '',
            'tipo_curso' => '',
            'modalidad' => '',
            'semestre' => '8VO',
            'requisitos' => '',
            'sesiones_semanales_teoricas' => '1',
            'sesiones_semanales_practicas' => '2',
            'docente' => [
                'nombre' => 'HAROLD ROJAS',
                'email' => 'hrojas@unitepc.edu.bo',
                'telefono' => '78311416',
            ],
            'justificacion' => 'Esta justificacion es deliberadamente larga para simular el escenario de produccion donde docente_formacion quedaria vacio y el importador greedy levantaria este texto. ' . str_repeat('x', 500),
            'proposito_general' => 'Proposito de prueba.',
            'competencia_global_especifica' => 'Competencia global.',
            'competencia_asignatura' => 'Competencia asignatura.',
            'elementos_competencia' => ['Elemento uno.'],
            'metodologia_general' => [
                'aula' => 'Metodologia aula.',
                'simulacion' => 'NO CORRESPONDE',
                'hospital' => 'NO CORRESPONDE',
            ],
            'sistema_evaluacion' => [
                'intro' => 'Intro evaluacion.',
                'diagnostica' => 'Diagnostica.',
                'formativa' => 'Formativa.',
                'sumativa' => 'Sumativa.',
                'ponderacion' => 'Ponderacion.',
            ],
            'reglamento_normativa' => [
                'clase' => 'Reglamento clase.',
            ],
            'bibliografias' => [],
        ];

        // EXPORT
        $request = Request::create('/api/restauracion/exportar-pac-asignatura', 'POST', [
            'asignatura' => $payloadAsignatura,
        ]);
        $exportResponse = app(RestauracionAcademicaController::class)->exportarExcelPacAsignatura($request);
        $this->assertSame(200, $exportResponse->getStatusCode());

        $exportContent = $exportResponse->getFile()->getContent();
        $tmpPath = tempnam(sys_get_temp_dir(), 'pac_empty_test_');
        file_put_contents($tmpPath, $exportContent);

        // Verificar que los campos vacios se escribieron con placeholder
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheet = $spreadsheet->getSheetByName('PAC');
        $this->assertNotNull($sheet);

        $this->assertSame('NO ESPECIFICADO', $sheet->getCell('C12')->getValue());
        $this->assertSame('NO ESPECIFICADO', $sheet->getCell('H12')->getValue());
        $this->assertSame('NO ESPECIFICADO', $sheet->getCell('C13')->getValue());
        $this->assertSame('NO ESPECIFICADO', $sheet->getCell('C14')->getValue());
        $this->assertSame('NO ESPECIFICADO', $sheet->getCell('C21')->getValue());

        // IMPORT roundtrip: no debe fallar por truncamiento ni contaminar campos
        $uploadedFile = new \Illuminate\Http\UploadedFile(
            $tmpPath,
            'PAC_SIS-424_empty.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $importRequest = new Request();
        $importRequest->files->set('file', $uploadedFile);

        $importResponse = app(\App\Http\Controllers\AsignaturaController::class)->importExcel($importRequest, $asignaturaId);
        $this->assertSame(200, $importResponse->getStatusCode());

        $asignatura = DB::table('asignaturas')->where('id', $asignaturaId)->first();
        $this->assertSame('NO ESPECIFICADO', $asignatura->area_desempenio);
        $this->assertSame('NO ESPECIFICADO', $asignatura->tipo_curso);
        $this->assertSame('NO ESPECIFICADO', $asignatura->modalidad);
        $this->assertSame('NO ESPECIFICADO', $asignatura->requisitos);
        $this->assertSame('NO ESPECIFICADO', $asignatura->docente_formacion);
        $this->assertNotSame($asignatura->justificacion, $asignatura->docente_formacion);

        @unlink($tmpPath);
    }

    public function test_exportar_plan_clase_usa_layout_de_la_plantilla_y_es_reimportable(): void
    {
        $payloadAsignatura = [
            'codigo' => 'FAR-101',
            'nombre' => 'Farmacologia Veterinaria',
            'carrera' => ['nombre' => 'Medicina Veterinaria y Zootecnia'],
            'unidades' => [
                [
                    'numero' => '1',
                    'titulo' => 'ORIGEN DE LOS FARMACOS',
                    'elemento_competencia' => 'Elemento de competencia de la unidad 1',
                    'temas' => [
                        [
                            'orden' => '1',
                            'titulo' => 'Ramas que comprende la farmacologia',
                            'resultado_aprendizaje' => 'Analizar los principios de la farmacocinetica.',
                            'logros_esperados' => [
                                ['descripcion' => 'Farmacocinetica', 'indicadores' => [['descripcion' => 'Describe fases ADME']]],
                            ],
                            'contenido_conceptual' => "Farmacocinetica\n• Concepto\n• Fases ADME",
                            'contenido_actitudinal' => "El estudiante demuestra:\n• Responsabilidad",
                            'estrategias_metodologicas' => "Clase magistral\nEstudio de casos",
                            'estrategias_aprendizaje' => "Resolucion de problemas",
                            'estrategias_recursos' => "Pizarra\nGuias",
                            'evaluacion_formativa' => [
                                'actividades' => "Analisis de casos",
                                'instrumentos' => "Lista de cotejo",
                                'evidencias' => "Casos resueltos",
                            ],
                            'evaluacion_sumativa' => [
                                'actividades' => "Examen teorico-practico",
                                'instrumentos' => "Prueba objetiva",
                                'evidencias' => "Examen aprobado",
                            ],
                            'secuencias' => [
                                ['momento' => 'INTRODUCCION', 'actividad' => 'Presentacion del tema', 'duracion' => 20],
                                ['momento' => 'RESULTADOS DE APRENDIZAJE/LOGROS ESPERADOS', 'actividad' => 'Socializacion', 'duracion' => 15],
                                ['momento' => 'CONTENIDOS DE LA CLASE', 'actividad' => 'Presentacion estructurada', 'duracion' => 10],
                                ['momento' => 'CUERPO DE CONTENIDOS', 'actividad' => 'Exposicion magistral', 'duracion' => 120],
                                ['momento' => 'CONCLUSION O CIERRE', 'actividad' => 'Sintesis colectiva', 'duracion' => 15],
                            ],
                        ],
                        [
                            'orden' => '2',
                            'titulo' => 'Via de administracion',
                        ],
                    ],
                ],
                [
                    'numero' => '2',
                    'titulo' => 'FARMACOCINETICA',
                    'elemento_competencia' => 'Elemento de competencia de la unidad 2',
                    'temas' => [
                        [
                            'orden' => '3',
                            'titulo' => 'Absorcion de farmacos',
                        ],
                    ],
                ],
            ],
        ];

        // EXPORT
        $request = Request::create('/api/restauracion/exportar-plan-clase-asignatura', 'POST', [
            'asignatura' => $payloadAsignatura,
        ]);
        $exportResponse = app(RestauracionAcademicaController::class)->exportarExcelPlanClaseAsignatura($request);
        $this->assertSame(200, $exportResponse->getStatusCode());

        $exportContent = $exportResponse->getFile()->getContent();
        $this->assertNotEmpty($exportContent);

        // Verificar layout del Excel generado
        $tmpPath = tempnam(sys_get_temp_dir(), 'planclase_test_');
        file_put_contents($tmpPath, $exportContent);
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('UNIVERSIDAD TÉCNICA PRIVADA COSMOS', $sheet->getCell('B3')->getValue());
        $this->assertSame('PLAN DE CLASE', $sheet->getCell('B5')->getValue());
        $this->assertSame('Nombre del docente:', $sheet->getCell('B8')->getValue());
        $this->assertSame('Asignatura:', $sheet->getCell('E8')->getValue());
        $this->assertSame('Unidad 1: ORIGEN DE LOS FARMACOS', $sheet->getCell('B11')->getValue());
        $this->assertSame('Elemento de Competencia 1:', $sheet->getCell('B12')->getValue());
        $this->assertSame('TEMA 1: RAMAS QUE COMPRENDE LA FARMACOLOGIA', $sheet->getCell('B13')->getValue());
        $this->assertSame('Resultados de Aprendizaje:', $sheet->getCell('B14')->getValue());
        $this->assertSame('Contenidos:', $sheet->getCell('B17')->getValue());
        $this->assertSame('Saber Conceptual:', $sheet->getCell('C17')->getValue());
        $this->assertSame('ESTRATEGIAS DIDACTICAS', $sheet->getCell('B19')->getValue());
        $this->assertSame('EVALUACION DE LOS APRENDIZAJES', $sheet->getCell('B22')->getValue());
        $this->assertSame('SECUENCIA DIDACTICA', $sheet->getCell('B26')->getValue());
        $this->assertSame('INTRODUCCION', $sheet->getCell('B28')->getValue());
        $this->assertSame('TEMA 2: VIA DE ADMINISTRACION', $sheet->getCell('B33')->getValue());
        $this->assertSame('Unidad 2: FARMACOCINETICA', $sheet->getCell('B54')->getValue());

        // Verificar roundtrip con el parser (sin tocar el importador)
        $parser = new \App\Services\PlanClaseParserService();
        $parsed = $parser->parse(new \Illuminate\Http\UploadedFile($tmpPath, 'planclase.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

        $this->assertCount(2, $parsed['unidades']);
        $this->assertCount(2, $parsed['unidades'][1]['temas']);
        $this->assertCount(1, $parsed['unidades'][2]['temas']);

        $tema1 = $parsed['unidades'][1]['temas'][1];
        $this->assertSame('TEMA 1', $tema1['titulo']);
        $this->assertSame('Analizar los principios de la farmacocinetica.', $tema1['logros']);
        $this->assertSame(['Farmacocinetica'], $tema1['logros_esperados_list']);
        $this->assertSame(['Farmacocinetica:', 'Describe fases ADME'], $tema1['indicadores_list']);
        $this->assertSame(['Farmacocinetica', '• Concepto', '• Fases ADME'], $tema1['contenido_conceptual']);
        $this->assertSame(["El estudiante demuestra:", '• Responsabilidad'], $tema1['contenido_actitudinal']);
        $this->assertStringContainsString('Clase magistral', $tema1['estrategias_metodologicas']);
        $this->assertSame(['Pizarra', 'Guias'], $tema1['estrategias_recursos']);
        $this->assertSame(['Analisis de casos'], $tema1['evaluacion_formativa']['actividades']);
        $this->assertSame(['Examen teorico-practico'], $tema1['evaluacion_sumativa']['actividades']);
        $this->assertCount(5, $tema1['secuencia_didactica']);
        $this->assertSame('Presentacion del tema', $tema1['secuencia_didactica'][0]['actividad']);
        $this->assertSame(20, $tema1['secuencia_didactica'][0]['duracion']);

        @unlink($tmpPath);
    }

    public function test_exportar_pac_mapea_elementos_de_competencia_por_unidad(): void
    {
        // Reproduce el caso del usuario: la UI muestra elementos por unidad,
        // pero elementos_competencia llega desfasado (index 0 vacio).
        $payloadAsignatura = [
            'carrera' => ['nombre' => 'INGENIERIA'],
            'codigo' => 'MAT-101',
            'nombre' => 'MATEMATICAS',
            'area_desempenio' => 'CIENCIAS BASICAS',
            'tipo_curso' => 'OBLIGATORIO',
            'modalidad' => 'PRESENCIAL',
            'semestre' => '1RO',
            'requisitos' => 'NINGUNO',
            'sesiones_semanales_teoricas' => '2',
            'sesiones_semanales_practicas' => '2',
            'docente' => [
                'nombre' => 'DOCENTE',
                'email' => 'docente@unitepc.edu.bo',
                'telefono' => '12345678',
                'formacion' => 'LIC. MATEMATICAS',
            ],
            'justificacion' => 'Justificacion.',
            'proposito_general' => 'Proposito.',
            'competencia_global_especifica' => 'Competencia global.',
            'competencia_asignatura' => 'Competencia asignatura.',
            // Array desfasado: indice 0 vacio (como si viniera 1-indexado o con hueco)
            'elementos_competencia' => ['', 'Texto erroneo EC1', 'Texto erroneo EC2'],
            'unidades' => [
                ['numero' => '1', 'titulo' => 'Unidad 1', 'elemento_competencia' => 'Modelar fenomenos fisicos.'],
                ['numero' => '2', 'titulo' => 'Unidad 2', 'elemento_competencia' => 'Optimizar procesos.'],
                ['numero' => '3', 'titulo' => 'Unidad 3', 'elemento_competencia' => 'Resolver problemas.'],
            ],
            'metodologia_general' => ['aula' => 'Aula.', 'simulacion' => 'NO CORRESPONDE', 'hospital' => 'NO CORRESPONDE'],
            'sistema_evaluacion' => ['intro' => 'Intro.', 'diagnostica' => 'D.', 'formativa' => 'F.', 'sumativa' => 'S.', 'ponderacion' => 'P.'],
            'reglamento_normativa' => ['clase' => 'Reglamento.'],
            'bibliografias' => [],
        ];

        $request = Request::create('/api/restauracion/exportar-pac-asignatura', 'POST', [
            'asignatura' => $payloadAsignatura,
        ]);
        $exportResponse = app(RestauracionAcademicaController::class)->exportarExcelPacAsignatura($request);
        $this->assertSame(200, $exportResponse->getStatusCode());

        $exportContent = $exportResponse->getFile()->getContent();
        $tmpPath = tempnam(sys_get_temp_dir(), 'pac_ec_test_');
        file_put_contents($tmpPath, $exportContent);

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheet = $spreadsheet->getSheetByName('PAC');
        $this->assertNotNull($sheet);

        // Cada fila debe coincidir con el elemento_competencia de su unidad,
        // ignorando el array plano desfasado.
        $this->assertSame('Modelar fenomenos fisicos.', $sheet->getCell('D38')->getValue());
        $this->assertSame('Optimizar procesos.', $sheet->getCell('D39')->getValue());
        $this->assertSame('Resolver problemas.', $sheet->getCell('D40')->getValue());
        $this->assertTrue(in_array($sheet->getCell('D41')->getValue(), [null, ''], true));

        @unlink($tmpPath);
    }

    private function createMinimalSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'planificaciones_personales',
            'tema_bibliografia',
            'bibliografias',
            'indicadores',
            'logros_esperados',
            'temas',
            'unidades',
            'asignatura_carrera',
            'asignaturas',
            'carreras',
            'sedes',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('carreras', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('plan_estudios')->nullable();
            $table->timestamps();
        });

        Schema::create('sedes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo')->nullable();
            $table->timestamps();
        });

        Schema::create('asignaturas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo');
            $table->string('sigla')->nullable();
            $table->string('nombre');
            $table->string('plan_estudios')->nullable();
            $table->string('estado')->nullable();
            $table->text('descripcion')->nullable();
            $table->text('justificacion')->nullable();
            $table->text('proposito_general')->nullable();
            $table->text('metodologia_general')->nullable();
            $table->text('sistema_evaluacion')->nullable();
            $table->text('reglamento_normativa')->nullable();
            $table->text('contenido_minimo')->nullable();
            $table->text('requisitos')->nullable();
            $table->text('competencia_asignatura')->nullable();
            $table->text('competencia_global_especifica')->nullable();
            $table->text('elementos_competencia')->nullable();
            $table->string('modalidad')->nullable();
            $table->string('tipo_curso')->nullable();
            $table->string('area_desempenio')->nullable();
            $table->integer('sesiones_semanales_teoricas')->nullable();
            $table->integer('sesiones_semanales_practicas')->nullable();
            $table->string('docente_email')->nullable();
            $table->string('docente_formacion')->nullable();
            $table->string('docente_telefono')->nullable();
            $table->boolean('modificado_localmente')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('asignatura_carrera', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignatura_id');
            $table->foreignId('carrera_id');
            $table->foreignId('sede_id');
            $table->integer('semestre')->nullable();
            $table->timestamps();
        });

        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignatura_id');
            $table->string('numero')->nullable();
            $table->string('titulo');
            $table->string('tipo')->nullable();
            $table->text('objetivo')->nullable();
            $table->text('contenido_minimo')->nullable();
            $table->text('elemento_competencia')->nullable();
            $table->integer('orden')->nullable();
            $table->timestamps();
        });

        Schema::create('temas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidad_id');
            $table->integer('orden')->nullable();
            $table->string('titulo');
            $table->string('tipo')->nullable();
            $table->text('resultado_aprendizaje')->nullable();
            $table->text('contenido_conceptual')->nullable();
            $table->text('contenido_procedimental')->nullable();
            $table->text('contenido_actitudinal')->nullable();
            $table->integer('horas_practicas')->nullable();
            $table->integer('horas_teoricas')->nullable();
            $table->text('estrategias_metodologicas')->nullable();
            $table->text('estrategias_aprendizaje')->nullable();
            $table->text('estrategias_recursos')->nullable();
            $table->text('evaluacion_formativa')->nullable();
            $table->text('evaluacion_sumativa')->nullable();
            $table->text('contenido_items')->nullable();
            $table->timestamps();
        });

        Schema::create('logros_esperados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tema_id');
            $table->text('descripcion')->nullable();
            $table->string('tipo_logro')->nullable();
            $table->string('periodo')->nullable();
            $table->timestamps();
        });

        Schema::create('indicadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logro_esperado_id');
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::create('bibliografias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignatura_id');
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->string('autor')->nullable();
            $table->string('editorial')->nullable();
            $table->string('edicion')->nullable();
            $table->string('anio')->nullable();
            $table->string('tipo')->nullable();
            $table->string('isbn')->nullable();
            $table->string('paginas')->nullable();
            $table->timestamps();
        });

        Schema::create('tema_bibliografia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tema_id');
            $table->foreignId('bibliografia_id');
            $table->string('pagina_desde')->nullable();
            $table->string('pagina_hasta')->nullable();
            $table->timestamps();
        });

        Schema::create('planificaciones_personales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tema_id');
            $table->foreignId('user_id')->nullable();
            $table->text('estrategias_metodologicas')->nullable();
            $table->text('estrategias_aprendizaje')->nullable();
            $table->text('estrategias_recursos')->nullable();
            $table->text('evaluacion_formativa')->nullable();
            $table->text('evaluacion_sumativa')->nullable();
            $table->text('secuencia_didactica')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }
}
