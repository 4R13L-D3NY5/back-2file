<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Asignatura;
use App\Models\Carrera;
use App\Models\Sede;
use App\Models\Unidad;
use App\Models\Tema;
use App\Models\SecuenciaTema;
use App\Models\Bibliografia;
use App\Models\LogroEsperado;
use App\Models\Indicador;
use App\Models\PlanificacionPersonal;
use App\Models\User;
use App\Models\Grupo;
use App\Models\Docente;
use App\Models\Cronograma;
use App\Models\Horario;
use Carbon\Carbon;

class ArielCamaraSeeder extends Seeder
{
    public function run()
    {
        // 1. Identificar Docente y Usuario correctamente por CI
        // Esto evita problemas si existen usuarios duplicados o incorrectos
        $ci = '6522053';
        $docente = Docente::where('ci', $ci)->first();

        if (!$docente) {
             $this->command->warn("Docente con CI '{$ci}' no encontrado.");
             return;
        }

        $user = User::find($docente->user_id);
        
        if (!$user) {
            $this->command->warn("Usuario asociado al docente (ID: {$docente->id}) no encontrado.");
            return;
        }

        $username = $user->username;
        $this->command->info("Docente encontrado: {$docente->nombre_completo} (ID: {$docente->id}) - Usuario ID: {$user->id}");

        // Obtener Sede y Carrera (solo para vincular asignatura si hiciera falta)
        $sede = Sede::where('codigo', 'CBA')->first();
        $carrera = Carrera::where('codigo', 'SIS')->first();

        // ==========================================
        // 2. TALLER DE REDES
        // ==========================================
        $redes = Asignatura::updateOrCreate(
            ['codigo' => 'SIS-325'],
            [
                'nombre' => 'TALLER DE REDES',
                'creditos' => 4,
                'area_desempenio' => 'Infraestructura',
                'tipo_curso' => 'Práctico',
                'modalidad' => 'Presencial',
                'estado' => 'activo',
                'carga_horaria_total' => 120,
                'horas_teoricas' => 2,
                'horas_practicas' => 4,
                'sesiones_semanales_teoricas' => 1,
                'sesiones_semanales_practicas' => 1,
                'justificacion' => 'Capacitación práctica en configuración de dispositivos de red.',
                'proposito_general' => 'Diseñar y configurar redes corporativas seguras.',
                'competencia_global_especifica' => 'Implementa infraestructuras de red seguras.',
                'competencia_asignatura' => 'Administra dispositivos de interconexión (Routers, Switches).',
                'metodologia_general' => ['Simulación Packet Tracer', 'Laboratorios Físicos'],
                'sistema_evaluacion' => 'Laboratorios 100%',
            ]
        );

        if ($carrera && $sede) {
            if (!$redes->carreras()->where('carrera_id', $carrera->id)->exists()) {
                $redes->carreras()->attach($carrera->id, ['semestre' => 6, 'sede_id' => $sede->id]);
            }
        }

        $this->limpiarAsignatura($redes);

        // Bibliografía Taller de Redes
        // Definir variables de bibliografía que se usan más adelante
        $bibCCNA = $redes->bibliografias()->create([
            'titulo' => 'CCNA Routing and Switching', 
            'autor' => 'Cisco Press', 
            'anio' => 2021, 
            'tipo' => 'BÁSICA'
        ]);
        
        $bibForouzan = $redes->bibliografias()->create([
             'titulo' => 'Data Communications and Networking', 
             'autor' => 'Behrouz A. Forouzan', 
             'anio' => 2013, 
             'tipo' => 'COMPLEMENTARIA'
        ]);

        $bibTCPIP = $redes->bibliografias()->create([
             'titulo' => 'TCP/IP Illustrated', 
             'autor' => 'Kevin R. Fall', 
             'anio' => 2011, 
             'tipo' => 'COMPLEMENTARIA'
        ]);
        
        // Variables adicionales que podrían usarse más adelante (Tema 2.1 y 2.2)
        $bibKurose = $redes->bibliografias()->create([
             'titulo' => 'Computer Networking: A Top-Down Approach', 
             'autor' => 'James Kurose', 
             'anio' => 2017, 
             'tipo' => 'BÁSICA'
        ]);

        $bibCiscoAcademy = $redes->bibliografias()->create([
             'titulo' => 'Introduction to Networks Companion Guide', 
             'autor' => 'Cisco Networking Academy', 
             'anio' => 2014, 
             'tipo' => 'COMPLEMENTARIA'
        ]);
        
        $bibWarrior = $redes->bibliografias()->create([
             'titulo' => 'Ethernet Switches', 
             'autor' => 'Charles E. Spurgeon', 
             'anio' => 2013, 
             'tipo' => 'COMPLEMENTARIA'
        ]);

        // UNIDAD 1
        $uRedes1 = Unidad::create([
            'asignatura_id' => $redes->id, 'numero' => 1,
            'titulo' => 'VLANs y Trunking',
            'objetivo' => 'Segmentar redes lógicas.',
            'contenido_minimo' => 'VLANs, 802.1Q',
            'elemento_competencia' => 'Configura VLANs en switches.'
        ]);

        // Generar Cronograma Unificado para los grupos complementarios (Teoría + Práctica)
        $gruposRedes = Grupo::where('asignatura_id', $redes->id)
                            ->where('docente_id', $docente->id)
                            ->with('horarios')
                            ->get();

        if ($gruposRedes->isEmpty()) {
             $this->command->info("No se encontraron grupos asociados al docente {$username} para TALLER DE REDES.");
        } else {
            // Identificar el grupo principal (Teórico o el ID 424 explícitamente si preferimos)
            // Prioridad: ID 424 -> Nombre contiene "1" -> Tipo TEORICO -> El primero
            $grupoPrincipal = $gruposRedes->first(function($g) {
                return $g->id == 424; 
            }) ?? $gruposRedes->first(function($g) {
                return str_contains($g->nombre, '1') || $g->tipo === 'TEORICO';
            }) ?? $gruposRedes->first();

            $this->command->info("Grupo Principal identificado para Cronograma: ID {$grupoPrincipal->id} ({$grupoPrincipal->nombre})");
            
            // Recargar horarios del grupo principal para el cronograma
            $grupoPrincipal->refresh();

            // Limpiar cronograma de TODOS los grupos relacionados para evitar duplicados o basura
            Cronograma::whereIn('grupo_id', $gruposRedes->pluck('id'))
                        ->where('asignatura_id', $redes->id)
                        ->delete();

            $this->command->info("Generando cronograma unificado (40 sesiones)...");
            // Pasamos el grupo principal (para el ID) y la colección completa (para los horarios combinados)
            $this->generarCronogramaUnificado($grupoPrincipal, $gruposRedes, $redes);
        }

        // TEMA 1.1: Creación de VLANs
        $tRedes1_1 = Tema::create([
            'unidad_id' => $uRedes1->id, 
            'orden' => 1,
            'titulo' => 'Creación de VLANs',
            'resultado_aprendizaje' => 'Configura VLANs en Cisco IOS.',
            'horas_teoricas' => 1, 
            'horas_practicas' => 3,
            'contenido_conceptual' => ['Base de datos VLAN', 'Puertos de acceso'],
            'contenido_procedimental' => ['Comandos vlan database', 'Asignación de puertos'],
            'contenido_actitudinal' => ['Orden en el cableado estructurado']
        ]);

        // Secuencia Didáctica para Tema 1.1 (Reconstruida)
        $tRedes1_1->secuencias()->create([
            'momento' => 'INTRODUCCION',
            'descripcion' => 'Revisión de conceptos de broadcast domain.',
            'duracion_minutos' => 10
        ]);
        $tRedes1_1->secuencias()->create([
            'momento' => 'CONTENIDOS DE LA CLASE',
            'descripcion' => 'Explicación de segmentación lógica.',
            'duracion_minutos' => 20
        ]);
        $tRedes1_1->secuencias()->create([
            'momento' => 'CUERPO DE CONTENIDOS',
            'descripcion' => 'Práctica: Creación de VLANs 10, 20, 30.',
            'duracion_minutos' => 45
        ]);
        $tRedes1_1->secuencias()->create([
            'momento' => 'CONCLUSION O CIERRE',
            'descripcion' => 'Verificación de conectividad inter-VLAN. Reflexión sobre errores comunes.',
            'duracion_minutos' => 15
        ]);

        // Datos personales Tema 1.1 (Reconstruido)
        $tRedes1_1_personal = [
             'estrategias_metodologicas' => 'Clase participativa',
             'estrategias_aprendizaje' => 'Resolución de problemas',
             'estrategias_recursos' => ['Packet Tracer', 'Pizarra'],
             'evaluacion_formativa' => [],
             'evaluacion_sumativa' => []
        ];


        // Crear planificación personal con las secuencias y datos personales
        $this->crearPlanificacionPersonal($tRedes1_1, $user->id, $tRedes1_1_personal);

        // Datos personales Tema 1.2
        $tRedes1_2_personal = [
            'estrategias_metodologicas' => 'Aprendizaje basado en problemas: resolver falla de comunicación entre switches.',
            'estrategias_aprendizaje' => 'Trabajo en equipo para diagnosticar y resolver problemas de trunking.',
            'estrategias_recursos' => [
                'Packet Tracer',
                'Switches físicos Cisco',
                'Cables de consola',
                'Analizador de tramas Wireshark'
            ],
            'evaluacion_formativa' => [
                'actividades' => [
                    'Quiz sobre etiquetado 802.1Q',
                    'Participación en troubleshooting grupal',
                    'Análisis de capturas Wireshark'
                ],
                'instrumentos' => [
                    'Cuestionario en línea',
                    'Rúbrica de trabajo colaborativo',
                    'Lista de verificación de análisis'
                ],
                'evidencias' => [
                    'Resultados de quiz',
                    'Reporte de troubleshooting',
                    'Capturas de tráfico analizadas'
                ]
            ],
            'evaluacion_sumativa' => [
                'actividades' => [
                    'Laboratorio calificado: Configuración multi-switch',
                    'Informe técnico de configuración'
                ],
                'instrumentos' => [
                    'Rúbrica de laboratorio (70%)',
                    'Rúbrica de informe técnico (30%)'
                ],
                'evidencias' => [
                    'Topología funcional en Packet Tracer',
                    'Informe con diagramas y análisis',
                    'Video de demostración de conectividad'
                ]
            ]
        ];

        // TEMA 1.2: Enlaces Troncales (Trunking)
        $tRedes1_2 = Tema::create([
            'unidad_id' => $uRedes1->id, 
            'orden' => 2,
            'titulo' => 'Enlaces Troncales (Trunking) y Protocolo 802.1Q',
            'resultado_aprendizaje' => 'Implementa enlaces troncales entre switches para transportar múltiples VLANs.',
            'horas_teoricas' => 2,
            'horas_practicas' => 4,
            'contenido_conceptual' => [
                'Protocolo 802.1Q (Dot1Q)',
                'Etiquetado de tramas (Frame Tagging)',
                'VLAN Nativa',
                'DTP (Dynamic Trunking Protocol)'
            ],
            'contenido_procedimental' => [
                'Configuración de puertos trunk',
                'Comando switchport mode trunk',
                'Configuración de VLANs permitidas',
                'Troubleshooting de enlaces trunk'
            ],
            'contenido_actitudinal' => [
                'Atención al detalle en configuraciones críticas',
                'Proactividad en la detección de problemas'
            ]
        ]);

        // Referencias Bibliográficas para Tema 1.2
        $tRedes1_2->bibliografias()->attach($bibCCNA->id, ['pagina_desde' => 211, 'pagina_hasta' => 265]);
        $tRedes1_2->bibliografias()->attach($bibForouzan->id, ['pagina_desde' => 320, 'pagina_hasta' => 355]);
        $tRedes1_2->bibliografias()->attach($bibTCPIP->id, ['pagina_desde' => 89, 'pagina_hasta' => 112]);

        // Logros e Indicadores para Tema 1.2
        $logro1_2_1 = $tRedes1_2->logros()->create([
            'descripcion' => 'Comprende el funcionamiento del protocolo 802.1Q y el etiquetado de tramas',
            'tipo_logro' => 'SABER'
        ]);
        $logro1_2_1->indicadores()->create(['descripcion' => 'Describe el proceso de encapsulación y desencapsulación de tramas 802.1Q']);

        $logro1_2_2 = $tRedes1_2->logros()->create([
            'descripcion' => 'Configura y verifica enlaces troncales entre switches',
            'tipo_logro' => 'HACER'
        ]);
        $logro1_2_2->indicadores()->create(['descripcion' => 'Establece enlaces trunk funcionales y verifica con show interfaces trunk']);

        $logro1_2_3 = $tRedes1_2->logros()->create([
            'descripcion' => 'Diagnostica y resuelve problemas de trunking de manera sistemática',
            'tipo_logro' => 'DECIDIR'
        ]);
        $logro1_2_3->indicadores()->create(['descripcion' => 'Aplica metodología de troubleshooting para identificar y corregir errores']);

        // Secuencia Didáctica para Tema 1.2
        $tRedes1_2->secuencias()->create([
            'momento' => 'INTRODUCCION',
            'descripcion' => 'Planteamiento de problema: ¿Cómo comunicar VLANs entre múltiples switches?',
            'duracion_minutos' => 10
        ]);
        $tRedes1_2->secuencias()->create([
            'momento' => 'RESULTADOS DE APRENDIZAJE/LOGROS',
            'descripcion' => 'Presentación de competencias a desarrollar y criterios de evaluación.',
            'duracion_minutos' => 10
        ]);
        $tRedes1_2->secuencias()->create([
            'momento' => 'CONTENIDOS DE LA CLASE',
            'descripcion' => 'Explicación teórica de 802.1Q con diagramas. Análisis de captura Wireshark.',
            'duracion_minutos' => 25
        ]);
        $tRedes1_2->secuencias()->create([
            'momento' => 'CUERPO DE CONTENIDOS',
            'descripcion' => 'Laboratorio: Configuración de topología con 3 switches y 4 VLANs.',
            'duracion_minutos' => 70
        ]);
        $tRedes1_2->secuencias()->create([
            'momento' => 'CONCLUSION O CIERRE',
            'descripcion' => 'Verificación de conectividad end-to-end. Discusión de errores comunes.',
            'duracion_minutos' => 15
        ]);

        // Crear planificación personal con las secuencias y datos personales
        $this->crearPlanificacionPersonal($tRedes1_2, $user->id, $tRedes1_2_personal);

        // ========== UNIDAD 2: Enrutamiento Inter-VLAN ==========
        $uRedes2 = Unidad::create([
            'asignatura_id' => $redes->id, 
            'numero' => 2,
            'titulo' => 'Enrutamiento Inter-VLAN',
            'objetivo' => 'Implementar comunicación entre VLANs utilizando routers y switches de capa 3.',
            'contenido_minimo' => 'Router-on-a-Stick, Subinterfaces, SVI, Switch Multicapa, ip routing',
            'elemento_competencia' => 'Implementa enrutamiento entre VLANs para permitir comunicación controlada entre segmentos.'
        ]);

        // Datos personales Tema 2.1
        $tRedes2_1_personal = [
            'estrategias_metodologicas' => 'Demostración guiada seguida de práctica supervisada.',
            'estrategias_aprendizaje' => 'Estudiantes diseñan esquema de direccionamiento antes de configurar.',
            'estrategias_recursos' => [
                'Packet Tracer',
                'Router Cisco 2911',
                'Switch Cisco 2960',
                'Calculadora de subredes'
            ],
            'evaluacion_formativa' => [
                'actividades' => [
                    'Revisión de esquema de direccionamiento IP',
                    'Verificación de configuración con comandos show',
                    'Pruebas de conectividad progresivas'
                ],
                'instrumentos' => [
                    'Lista de cotejo de direccionamiento',
                    'Rúbrica de configuración',
                    'Registro de pruebas'
                ],
                'evidencias' => [
                    'Diagrama de direccionamiento IP',
                    'Capturas de comandos show',
                    'Resultados de ping entre VLANs'
                ]
            ],
            'evaluacion_sumativa' => [
                'actividades' => [
                    'Examen práctico: Implementar Router-on-a-Stick completo'
                ],
                'instrumentos' => [
                    'Rúbrica de examen práctico (100%)'
                ],
                'evidencias' => [
                    'Archivo .pkt con solución completa',
                    'Documento de configuración',
                    'Video de demostración funcional'
                ]
            ]
        ];

        // TEMA 2.1: Router-on-a-Stick
        $tRedes2_1 = Tema::create([
            'unidad_id' => $uRedes2->id, 
            'orden' => 1,
            'titulo' => 'Router-on-a-Stick',
            'resultado_aprendizaje' => 'Configura enrutamiento inter-VLAN utilizando subinterfaces en un router.',
            'horas_teoricas' => 2,
            'horas_practicas' => 4,
            'contenido_conceptual' => [
                'Concepto de Router-on-a-Stick',
                'Subinterfaces lógicas',
                'Encapsulamiento dot1Q en subinterfaces',
                'Gateway predeterminado por VLAN'
            ],
            'contenido_procedimental' => [
                'Creación de subinterfaces',
                'Comando encapsulation dot1q',
                'Asignación de direcciones IP por VLAN',
                'Configuración de default gateway en hosts'
            ],
            'contenido_actitudinal' => [
                'Precisión en el direccionamiento IP',
                'Metodología en la configuración paso a paso'
            ]
        ]);

        // Referencias Bibliográficas para Tema 2.1
        $tRedes2_1->bibliografias()->attach($bibCCNA->id, ['pagina_desde' => 380, 'pagina_hasta' => 425]);
        $tRedes2_1->bibliografias()->attach($bibKurose->id, ['pagina_desde' => 290, 'pagina_hasta' => 315]);
        $tRedes2_1->bibliografias()->attach($bibCiscoAcademy->id, ['pagina_desde' => 156, 'pagina_hasta' => 189]);

        // Logros e Indicadores para Tema 2.1
        $logro2_1_1 = $tRedes2_1->logros()->create([
            'descripcion' => 'Explica el funcionamiento del modelo Router-on-a-Stick',
            'tipo_logro' => 'SABER'
        ]);
        $logro2_1_1->indicadores()->create(['descripcion' => 'Describe el flujo de tráfico entre VLANs a través de subinterfaces']);

        $logro2_1_2 = $tRedes2_1->logros()->create([
            'descripcion' => 'Implementa enrutamiento inter-VLAN con subinterfaces',
            'tipo_logro' => 'HACER'
        ]);
        $logro2_1_2->indicadores()->create(['descripcion' => 'Configura subinterfaces y logra comunicación entre VLANs']);

        $logro2_1_3 = $tRedes2_1->logros()->create([
            'descripcion' => 'Planifica esquemas de direccionamiento IP de manera eficiente',
            'tipo_logro' => 'DECIDIR'
        ]);
        $logro2_1_3->indicadores()->create(['descripcion' => 'Diseña plan de direccionamiento optimizado para múltiples VLANs']);

        // Secuencia Didáctica para Tema 2.1
        $tRedes2_1->secuencias()->create([
            'momento' => 'INTRODUCCION',
            'descripcion' => 'Problema: VLANs configuradas pero sin comunicación entre ellas. ¿Cómo solucionarlo?',
            'duracion_minutos' => 10
        ]);
        $tRedes2_1->secuencias()->create([
            'momento' => 'RESULTADOS DE APRENDIZAJE/LOGROS',
            'descripcion' => 'Explicación de objetivos y presentación de rúbrica de evaluación.',
            'duracion_minutos' => 10
        ]);
        $tRedes2_1->secuencias()->create([
            'momento' => 'CONTENIDOS DE LA CLASE',
            'descripcion' => 'Explicación teórica de subinterfaces y encapsulamiento. Diagrama en pizarra.',
            'duracion_minutos' => 25
        ]);
        $tRedes2_1->secuencias()->create([
            'momento' => 'CUERPO DE CONTENIDOS',
            'descripcion' => 'Laboratorio: Configuración de Router-on-a-Stick para 3 VLANs.',
            'duracion_minutos' => 65
        ]);
        $tRedes2_1->secuencias()->create([
            'momento' => 'CONCLUSION O CIERRE',
            'descripcion' => 'Pruebas de ping entre VLANs. Análisis de tabla de enrutamiento.',
            'duracion_minutos' => 20
        ]);

        // Crear planificación personal con las secuencias y datos personales
        $this->crearPlanificacionPersonal($tRedes2_1, $user->id, $tRedes2_1_personal);

        // Datos personales Tema 2.2
        $tRedes2_2_personal = [
            'estrategias_metodologicas' => 'Comparación práctica entre Router-on-a-Stick y Switch L3.',
            'estrategias_aprendizaje' => 'Estudiantes evalúan ventajas y desventajas de cada método.',
            'estrategias_recursos' => [
                'Packet Tracer',
                'Switch Cisco 3560 (Multicapa)',
                'Herramienta de medición de latencia'
            ],
            'evaluacion_formativa' => [
                'actividades' => [
                    'Debate técnico: Router-on-a-Stick vs Switch L3',
                    'Medición de rendimiento comparativo',
                    'Análisis de casos de uso reales'
                ],
                'instrumentos' => [
                    'Rúbrica de debate',
                    'Hoja de medición de latencia',
                    'Matriz de análisis comparativo'
                ],
                'evidencias' => [
                    'Presentación de argumentos técnicos',
                    'Reporte de mediciones',
                    'Cuadro comparativo documentado'
                ]
            ],
            'evaluacion_sumativa' => [
                'actividades' => [
                    'Proyecto final: Diseño e implementación de red empresarial'
                ],
                'instrumentos' => [
                    'Rúbrica de proyecto final (100%)'
                ],
                'evidencias' => [
                    'Diseño de red completo (topología, direccionamiento)',
                    'Implementación funcional en Packet Tracer',
                    'Documentación técnica profesional',
                    'Presentación y defensa del proyecto'
                ]
            ]
        ];

        // TEMA 2.2: Switch Multicapa (Layer 3 Switching)
        $tRedes2_2 = Tema::create([
            'unidad_id' => $uRedes2->id, 
            'orden' => 2,
            'titulo' => 'Enrutamiento con Switch Multicapa (Layer 3)',
            'resultado_aprendizaje' => 'Implementa enrutamiento inter-VLAN utilizando switches de capa 3.',
            'horas_teoricas' => 2,
            'horas_practicas' => 4,
            'contenido_conceptual' => [
                'Switch Multicapa vs Switch Capa 2',
                'SVI (Switch Virtual Interface)',
                'Comando ip routing',
                'Ventajas de rendimiento sobre Router-on-a-Stick'
            ],
            'contenido_procedimental' => [
                'Habilitación de enrutamiento IP',
                'Creación de interfaces SVI',
                'Asignación de IPs a VLANs',
                'Configuración de rutas estáticas'
            ],
            'contenido_actitudinal' => [
                'Criterio para elegir solución tecnológica apropiada',
                'Eficiencia en el uso de recursos de red'
            ]
        ]);

        // Referencias Bibliográficas para Tema 2.2
        $tRedes2_2->bibliografias()->attach($bibCCNA->id, ['pagina_desde' => 426, 'pagina_hasta' => 478]);
        $tRedes2_2->bibliografias()->attach($bibWarrior->id, ['pagina_desde' => 145, 'pagina_hasta' => 178]);
        $tRedes2_2->bibliografias()->attach($bibKurose->id, ['pagina_desde' => 316, 'pagina_hasta' => 342]);

        // Logros e Indicadores para Tema 2.2
        $logro2_2_1 = $tRedes2_2->logros()->create([
            'descripcion' => 'Diferencia entre switches de capa 2 y capa 3',
            'tipo_logro' => 'SABER'
        ]);
        $logro2_2_1->indicadores()->create(['descripcion' => 'Compara funcionalidades y casos de uso de switches L2 vs L3']);

        $logro2_2_2 = $tRedes2_2->logros()->create([
            'descripcion' => 'Configura enrutamiento inter-VLAN en switch multicapa',
            'tipo_logro' => 'HACER'
        ]);
        $logro2_2_2->indicadores()->create(['descripcion' => 'Habilita ip routing y crea SVIs funcionales para cada VLAN']);

        $logro2_2_3 = $tRedes2_2->logros()->create([
            'descripcion' => 'Evalúa y selecciona la mejor solución de enrutamiento inter-VLAN según el contexto',
            'tipo_logro' => 'DECIDIR'
        ]);
        $logro2_2_3->indicadores()->create(['descripcion' => 'Justifica técnicamente la elección entre Router-on-a-Stick y Switch L3']);

        // Secuencia Didáctica para Tema 2.2
        $tRedes2_2->secuencias()->create([
            'momento' => 'INTRODUCCION',
            'descripcion' => 'Limitaciones de Router-on-a-Stick en redes grandes. Introducción a switches L3.',
            'duracion_minutos' => 15
        ]);
        $tRedes2_2->secuencias()->create([
            'momento' => 'RESULTADOS DE APRENDIZAJE/LOGROS',
            'descripcion' => 'Presentación de competencias y criterios de proyecto final.',
            'duracion_minutos' => 10
        ]);
        $tRedes2_2->secuencias()->create([
            'momento' => 'CONTENIDOS DE LA CLASE',
            'descripcion' => 'Explicación de SVIs y comando ip routing. Demostración en equipo real.',
            'duracion_minutos' => 30
        ]);
        $tRedes2_2->secuencias()->create([
            'momento' => 'CUERPO DE CONTENIDOS',
            'descripcion' => 'Laboratorio: Migración de Router-on-a-Stick a Switch L3.',
            'duracion_minutos' => 60
        ]);
        $tRedes2_2->secuencias()->create([
            'momento' => 'CONCLUSION O CIERRE',
            'descripcion' => 'Comparación de rendimiento. Reflexión sobre escalabilidad.',
            'duracion_minutos' => 15
        ]);

        // Crear planificación personal con las secuencias y datos personales
        $this->crearPlanificacionPersonal($tRedes2_2, $user->id, $tRedes2_2_personal);






    }

    /**
     * Crea una planificación personal para un tema y usuario
     * Copia las secuencias de secuencias_temas a planificaciones_personales
     * Usa la estructura que espera el frontend: id, momento, duracion, actividad
     * 
     * @param Tema $tema
     * @param int|null $userId
     * @param array $personalData Datos personales (estrategias, evaluaciones)
     */
    private function crearPlanificacionPersonal($tema, $userId, $personalData = [])
    {
        if (!$userId || $tema->secuencias->isEmpty()) {
            return;
        }

        // Preparar datos de planificación personal
        $planificacionData = array_merge($personalData, [
            'secuencia_didactica' => $tema->secuencias->map(function($sec, $index) {
                return [
                    'id' => time() + $index, // ID único basado en timestamp
                    'momento' => $sec->momento,
                    'duracion' => (int)$sec->duracion_minutos, // Frontend usa 'duracion' (int)
                    'actividad' => $sec->descripcion // Frontend usa 'actividad'
                ];
            })->toArray()
        ]);

        // Crear o actualizar planificación personal
        PlanificacionPersonal::updateOrCreate(
            [
                'tema_id' => $tema->id,
                'user_id' => $userId
            ],
            $planificacionData
        );
    }

    private function generarCronogramaUnificado($grupoPrincipal, $todosLosGrupos, $asignatura)
    {
        $fechaInicio = Carbon::create(2026, 2, 9); // 09/02/2026
        $fechaFin = Carbon::create(2026, 6, 27);   // 27/06/2026 (Para tener exactamente 40 sesiones)
        
        // Fechas de Exámenes
        $examenes = [
            '2026-03-25' => '1er Parcial',
            '2026-05-20' => '2do Parcial',
            '2026-06-10' => 'Examen Final',
            '2026-07-01' => '2da Instancia'
        ];

        // Obtener horarios COMBINADOS de todos los grupos (Teoría + Práctica)
        $horarios = $todosLosGrupos->pluck('horarios')->flatten();
        
        // Mapeo de días
        $diasMap = [
            'Lunes' => 1,
            'Martes' => 2,
            'Miércoles' => 3,
            'Jueves' => 4,
            'Viernes' => 5,
            'Sábado' => 6,
            'Domingo' => 7
        ];

        // Obtener días de clase como enteros (ISO-8601: 1=Lunes, 7=Domingo)
        $diasClase = $horarios->map(function($h) use ($diasMap) {
            // Normalizar el día (quitar tildes si es necesario o manejar formatos)
            // En el dump vimos "Miercoles" (sin tilde) y "Lunes".
            // Ajustamos el mapa para ser robustos.
            $diaNormalizado = ucfirst(strtolower(str_replace(['é', 'á'], ['e', 'a'], $h->dia))); 
            // Esto maneja "Miercoles" y "Miércoles" -> "Miercoles" (si el mapa tiene Miercoles)
            
            // Mapa robusto local
            $mapLocal = [
                'Lunes' => 1, 'Martes' => 2, 'Miercoles' => 3, 'Miércoles' => 3, 
                'Jueves' => 4, 'Viernes' => 5, 'Sabado' => 6, 'Sábado' => 6, 'Domingo' => 7
            ];
            
            return $mapLocal[$diaNormalizado] ?? $mapLocal[$h->dia] ?? null;
        })->filter()->unique()->values()->toArray();

        $fechaActual = $fechaInicio->copy();
        $sesionCount = 1;

        while ($fechaActual->lte($fechaFin)) {
            $diaSemana = $fechaActual->dayOfWeekIso; // 1 (Mon) - 7 (Sun)
            $esDiaClase = in_array($diaSemana, $diasClase);
            $observaciones = null;
            $tipoContenido = 'Práctica'; // Default

            if ($esDiaClase) {
                // Determinar tipo basado en el día
                if ($diaSemana === 1) $tipoContenido = 'Teoría'; // Según el user Lunes es Práctico.. espera, revisemos el dump.
                // Dump: 424 (TEORICO) -> Miercoles. 425 (PRACTICO) -> Lunes.
                // OK, corregimos lógica:
                if ($diaSemana === 3) $tipoContenido = 'Teoría'; // Miércoles
                elseif ($diaSemana === 1) $tipoContenido = 'Práctica'; // Lunes
                else $tipoContenido = 'Práctica'; // Default
                
                $fechaStr = $fechaActual->format('Y-m-d');
                
                // Verificar si es fecha de examen
                if (isset($examenes[$fechaStr])) {
                    $observaciones = "EXAMEN: " . $examenes[$fechaStr];
                    $tipoContenido = 'Evaluación';
                }

                // Definir contenidos simplificados (menos de 255 caracteres)
                $conceptual = null;
                $procedimental = null;
                $actitudinal = ['Participación activa y ética profesional.'];
                $criterios = null;
                $instrumentos = null;

                if ($tipoContenido === 'Teoría') {
                    $conceptual = ['Introducción y desarrollo de conceptos teóricos.'];
                } elseif ($tipoContenido === 'Práctica') {
                    $procedimental = ['Desarrollo de prácticas y laboratorios guiados.'];
                } else { // Evaluación
                    $conceptual = ['Evaluación teórica de conocimientos.'];
                    $procedimental = ['Evaluación práctica de habilidades.'];
                }

                Cronograma::create([
                    'grupo_id' => $grupoPrincipal->id, // SIEMPRE al grupo principal (424)
                    'asignatura_id' => $asignatura->id,
                    'fecha' => $fechaActual->toDateString(),
                    'numero_sesion' => $sesionCount++,
                    'observaciones' => $observaciones,
                    'contenido_conceptual' => $conceptual, 
                    'contenido_procedimental' => $procedimental,
                    'contenido_actitudinal' => $actitudinal,
                    'criterios_desempeno' => $criterios,
                    'instrumentos_evaluacion' => $instrumentos,
                    'cumplido' => false,
                    'pedagogico' => [
                        'tipo_sesion' => $tipoContenido
                    ]
                ]);
            }

            $fechaActual->addDay();
        }
    }
    private function limpiarAsignatura($asignatura) {
        // Limpiar contenido académico previo (Unidades y Temas) para evitar corrupción de datos
        foreach($asignatura->unidades as $u) {
            foreach($u->temas as $t) {
                // Eliminar planificaciones personales asociadas
                PlanificacionPersonal::where('tema_id', $t->id)->delete();
                
                // Eliminar logros e indicadores
                foreach($t->logros as $logro) {
                    $logro->indicadores()->delete();
                }
                $t->logros()->delete();
                
                // Eliminar secuencias
                $t->secuencias()->delete();
            }
            $u->temas()->delete();
            $u->delete();
        }
    }
}
