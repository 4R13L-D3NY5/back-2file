<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\University\UniversityService;

class TestUniversityConnection extends Command
{
    protected $signature = 'university:test';
    protected $description = 'Prueba la conexión completa con la API externa (Carreras -> Asignaturas -> Programa)';

    public function handle(UniversityService $service)
    {
        $this->info('🚀 Iniciando prueba de integración completa...');
        
        try {
            // 1. TOKEN
            $this->comment('--------- PASO 1: Autenticación ---------');
            $token = $service->getToken();
            $this->info("✅ Token generado correctamente.");

            // 2. CARRERAS
            $this->comment("\n--------- PASO 2: Carreras (Sede: cba) ---------");
            $careers = $service->getCareers('cba');
            $countCareers = count($careers);
            $this->info("✅ Se encontraron {$countCareers} carreras.");
            
            // Debug Structure
            if ($countCareers > 0) {
                 $this->info("Estructura de la primera carrera:");
                 print_r($careers[0]);
            }

            if ($countCareers === 0) {
                $this->error('❌ No se encontraron carreras. Abortando.');
                return 1;
            }
            
            // Intentar adivinar la llave o fallar controladamente
            $firstCareer = $careers[0];
            $careerCode = $firstCareer['careerCode'] ?? $firstCareer['code'] ?? $firstCareer['id'] ?? null;
            
            if (!$careerCode) {
                 $this->error('❌ No se encontró una llave válida para el código de carrera.');
                 return 1;
            }
            
            $this->info("👉 Usaremos la carrera Code: {$careerCode}");

            // 3. ASIGNATURAS
            $this->comment("\n--------- PASO 3: Asignaturas (Code: {$careerCode}) ---------");
            $courses = $service->getCourses('cba', $careerCode);
            $countCourses = count($courses);
            $this->info("✅ Se encontraron {$countCourses} asignaturas.");

            if ($countCourses > 0) {
                 $this->info("Estructura de la primera asignatura:");
                 print_r($courses[0]);
            }

            if ($countCourses === 0) {
                $this->error('❌ No se encontraron asignaturas. Abortando.');
                return 1;
            }

            // Seleccionar una asignatura para la siguiente prueba
            $course = $courses[0];
            $courseCode = $course['courseCode'] ?? $course['code'] ?? $course['id'] ?? null;
            $this->info("👉 Usaremos la asignatura Code: {$courseCode}");
            
            if (!$courseCode) {
                 $this->error('❌ No se encontró una llave válida para el código de asignatura.');
                 return 1;
            }

            // 4. PROGRAMA ANALÍTICO
            $this->comment("\n--------- PASO 4: Programa Analítico (Code: {$courseCode}) ---------");
            $program = $service->getAnalyticalProgram($courseCode, 'cba', $careerCode);
            
            if ($program) {
                $this->info("✅ Programa analítico obtenido correctamente.");
                // Mostrar un resumen verificado
                $this->line("   - Identificación: " . ($program['identification']['name'] ?? 'N/A'));
                $this->line("   - Créditos: " . ($program['identification']['credits'] ?? 'N/A'));
                $this->line("   - Unidades temáticas: " . count($program['structure'] ?? []));
            } else {
                $this->warn("⚠️ El programa analítico retornó vacío (null), pero sin error HTTP.");
            }

            $this->info("\n🎉 ¡TODAS LAS PRUEBAS FINALIZARON EXITOSAMENTE!");
            return 0;

        } catch (\Illuminate\Http\Client\RequestException $e) {
            $this->error('❌ Error HTTP: ' . $e->getMessage());
            $this->error('Body: ' . $e->response->body());
            return 1;
        } catch (\Exception $e) {
            $this->error('❌ Error General: ' . $e->getMessage());
            return 1;
        }
    }
}
