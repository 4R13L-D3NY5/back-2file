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

class ArielCamaraSeeder extends Seeder
{
    public function run()
    {
        // 1. Obtener Sede y Carrera
        $sede = Sede::where('codigo', 'CBA')->first();
        if (!$sede) {
            $sede = Sede::create(['codigo' => 'CBA', 'nombre' => 'Cochabamba', 'ciudad' => 'Cochabamba', 'activo' => true]);
        }
        
        $carrera = Carrera::where('codigo', 'SIS')->first();
        if (!$carrera) {
             $carrera = Carrera::firstOrCreate([
                'codigo' => 'SIS'
             ], [
                'nombre' => 'Ingeniería de Sistemas',
                'area' => 'Ciencias Exactas y Tecnología',
                'sede_id' => $sede->id
             ]);
        }

        // Obtener un docente para crear planificaciones personales
        // INTENTO 1: Buscar usuario específico ID 93 (solicitado por usuario)
        $docente = User::find(93);

        // INTENTO 2: Buscar usuario ID 114
        if (!$docente) {
             $docente = User::find(114);
        }

        // INTENTO 3: Buscar primer usuario con rol Docente
        if (!$docente) {
            $docente = User::whereHas('roles', function($q) {
                $q->where('nombre', 'Docente');
            })->first();
        }

        // FALLBACK: Usar el primer usuario disponible
        if (!$docente) {
            $docente = User::first();
        }

        // ==========================================
        // 1. PROGRAMACION III (Poblado Completo)
        // ==========================================
        $prog3 = Asignatura::updateOrCreate(
            ['codigo' => 'SIS-213'],
            [
                'nombre' => 'PROGRAMACION III',
                'creditos' => 5,
                'area_desempenio' => 'Desarrollo de Software',
                'tipo_curso' => 'Teórico-Práctico',
                'modalidad' => 'Presencial',
                'estado' => 'activo',
                'carga_horaria_total' => 80,
                'horas_teoricas' => 40,
                'horas_practicas' => 40,
                'sesiones_semanales_teoricas' => 2,
                'sesiones_semanales_practicas' => 2,
                'justificacion' => 'La asignatura impulsa al estudiante dominando estructuras de datos avanzadas y POO.',
                'proposito_general' => 'Desarrollar software de alta calidad aplicando patrones y buenas prácticas.',
                'elementos_competencia' => null, 
                'competencia_global_especifica' => 'Diseña y desarrolla soluciones informáticas aplicando paradigmas de programación avanzada y estándares de calidad.',
                'competencia_asignatura' => 'Gestiona estructuras de datos complejas para soluciones eficientes.',
                'metodologia_general' => ['Aprendizaje Basado en Proyectos', 'Clases Prácticas', 'Investigación Formativa'],
                'sistema_evaluacion' => 'Continua 40%, Parcial 30%, Final 30%',
            ]
        );

        // Pivot Carrera-Asignatura
        if (!$prog3->carreras()->where('carrera_id', $carrera->id)->exists()) {
            $prog3->carreras()->attach($carrera->id, ['semestre' => 3, 'sede_id' => $sede->id]);
        }

        // Limpiar contenido académico previo (Unidades y Temas) para evitar corrupción de datos
        foreach($prog3->unidades as $u) {
            $u->temas()->delete();
            $u->delete();
        }

        // Limpiar bibliografía vieja (HasMany: delete)
        $prog3->bibliografias()->delete();

        // BIbliografía Nueva
        $prog3->bibliografias()->create(['titulo' => 'Java: The Complete Reference', 'autor' => 'Herbert Schildt', 'anio' => 2020, 'tipo' => 'BÁSICA']);
        $prog3->bibliografias()->create(['titulo' => 'Estructuras de Datos en Java', 'autor' => 'Mark Allen Weiss', 'anio' => 2013, 'tipo' => 'BÁSICA']);
        $prog3->bibliografias()->create(['titulo' => 'Thinking in Java', 'autor' => 'Bruce Eckel', 'anio' => 2006, 'tipo' => 'BÁSICA']);
        $prog3->bibliografias()->create(['titulo' => 'Introduction to Algorithms', 'autor' => 'Thomas H. Cormen', 'anio' => 2009, 'tipo' => 'BÁSICA']);
        $prog3->bibliografias()->create(['titulo' => 'Core Java Volume I', 'autor' => 'Cay S. Horstmann', 'anio' => 2020, 'tipo' => 'BÁSICA']);
        $prog3->bibliografias()->create(['titulo' => 'Clean Code', 'autor' => 'Robert C. Martin', 'anio' => 2008, 'tipo' => 'COMPLEMENTARIA']);
        $prog3->bibliografias()->create(['titulo' => 'Design Patterns', 'autor' => 'Erich Gamma', 'anio' => 1994, 'tipo' => 'COMPLEMENTARIA']);
        $prog3->bibliografias()->create(['titulo' => 'Effective Java', 'autor' => 'Joshua Bloch', 'anio' => 2018, 'tipo' => 'COMPLEMENTARIA']);

        // Crear Unidades Nuevas
        $u1 = Unidad::create([
            'asignatura_id' => $prog3->id, 
            'numero' => 1,
            'titulo' => 'Estructura de Datos No Lineales',
            'objetivo' => 'Implementar árboles y grafos para optimizar búsquedas.',
            'contenido_minimo' => 'Árboles Binarios, AVL, Grafos, Algoritmos de Recorrido',
            'elemento_competencia' => 'Aplica estructuras no lineales en la resolución de problemas.'
        ]);

        // Crear Tema 1.1 con arrays simples
        $t1 = Tema::create([
            'unidad_id' => $u1->id, 
            'orden' => 1,
            'titulo' => 'Árboles Binarios de Búsqueda',
            'resultado_aprendizaje' => 'Implementa las operaciones básicas de un ABB.',
            'horas_teoricas' => 2,
            'horas_practicas' => 4,
            'contenido_conceptual' => [
                'Definición de Árbol', 
                'Propiedades de ABB',
                'Recorridos InOrder, PreOrder, PostOrder'
            ],
            'contenido_procedimental' => [
                'Implementación de clase Nodo y Arbol', 
                'Algoritmos de inserción y búsqueda'
            ],
            'estrategias_metodologicas' => 'Explicación gráfica y codificación en vivo.',
            'estrategias_recursos' => [
                'Pizarra Digital', 
                'IDE NetBeans/IntelliJ'
            ],
            // Usando arrays simples de strings
            'evaluacion_formativa' => [
                'Implementación de recorrido en pizarra',
                'Quiz rápido sobre propiedades'
            ],
            'evaluacion_sumativa' => [
                'Laboratorio 1: Implementación de Diccionario'
            ]
        ]);

        $t1->secuencias()->create(['momento' => 'Inicio', 'descripcion' => 'Recuperación de conocimientos sobre listas enlazadas.', 'duracion_minutos' => 15]);
        $t1->secuencias()->create(['momento' => 'Desarrollo', 'descripcion' => 'Explicación de la lógica de punteros en árboles y codificación.', 'duracion_minutos' => 60]);
        $t1->secuencias()->create(['momento' => 'Cierre', 'descripcion' => 'Prueba de escritorio de un recorrido.', 'duracion_minutos' => 15]);

        // ==========================================
        // 2. TALLER DE REDES (Poblado Completo)
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
                'carga_horaria_total' => 60,
                'horas_teoricas' => 20,
                'horas_practicas' => 40,
                'sesiones_semanales_teoricas' => 1,
                'sesiones_semanales_practicas' => 3,
                'justificacion' => 'Capacitación práctica en configuración de dispositivos de red.',
                'proposito_general' => 'Diseñar y configurar redes corporativas seguras.',
                'elementos_competencia' => null,
                'competencia_global_especifica' => 'Implementa y administra infraestructuras de red aplicando normas y estándares internacionales.',
                'competencia_asignatura' => 'Administra dispositivos de interconexión de redes.',
                'metodologia_general' => ['Simulación', 'Laboratorios Físicos', 'Troubleshooting'],
                'sistema_evaluacion' => 'Evaluación Continua 100% (Laboratorios)',
            ]
        );
        
        if (!$redes->carreras()->where('carrera_id', $carrera->id)->exists()) {
            $redes->carreras()->attach($carrera->id, ['semestre' => 6, 'sede_id' => $sede->id]);
        }

        // Limpieza contenido existente de SIS-325
        foreach($redes->unidades as $u) {
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
        $redes->bibliografias()->delete();

        // Bibliografia (guardar referencias para asociar a temas)
        $bibCCNA = $redes->bibliografias()->create(['titulo' => 'CCNA Routing and Switching Official Cert Guide', 'autor' => 'Wendell Odom', 'anio' => 2021, 'tipo' => 'BÁSICA']);
        $bibKurose = $redes->bibliografias()->create(['titulo' => 'Computer Networking: A Top-Down Approach', 'autor' => 'Kurose & Ross', 'anio' => 2020, 'tipo' => 'BÁSICA']);
        $bibForouzan = $redes->bibliografias()->create(['titulo' => 'Data Communications and Networking', 'autor' => 'Behrouz A. Forouzan', 'anio' => 2012, 'tipo' => 'BÁSICA']);
        $bibWarrior = $redes->bibliografias()->create(['titulo' => 'Network Warrior', 'autor' => 'Gary A. Donahue', 'anio' => 2011, 'tipo' => 'BÁSICA']);
        $bibCiscoAcademy = $redes->bibliografias()->create(['titulo' => 'Cisco Networking Academy Program', 'autor' => 'Cisco Systems', 'anio' => 2018, 'tipo' => 'BÁSICA']);
        $bibTCPIP = $redes->bibliografias()->create(['titulo' => 'TCP/IP Illustrated', 'autor' => 'W. Richard Stevens', 'anio' => 1994, 'tipo' => 'COMPLEMENTARIA']);
        $bibSecurity = $redes->bibliografias()->create(['titulo' => 'Network Security Essentials', 'autor' => 'William Stallings', 'anio' => 2017, 'tipo' => 'COMPLEMENTARIA']);
        $bibPacketTracer = $redes->bibliografias()->create(['titulo' => 'Packet Tracer Labs', 'autor' => 'Cisco', 'anio' => 2022, 'tipo' => 'COMPLEMENTARIA']);

        // ========== UNIDAD 1: VLANs y Trunking ==========
        $uRedes1 = Unidad::create([
            'asignatura_id' => $redes->id, 
            'numero' => 1,
            'titulo' => 'VLANs y Trunking',
            'objetivo' => 'Segmentar redes lógicas para mejorar seguridad y rendimiento.',
            'contenido_minimo' => 'VLAN, 802.1Q, VTP, DTP, Segmentación de dominios de broadcast',
            'elemento_competencia' => 'Configura VLANs en entornos conmutados para optimizar el tráfico de red.'
        ]);

        // TEMA 1.1: Configuración de VLANs
        // Datos personales (estrategias, evaluaciones) que irán a planificaciones_personales
        $tRedes1_1_personal = [
            'estrategias_metodologicas' => 'Demostración práctica en Packet Tracer seguida de laboratorio guiado.',
            'estrategias_aprendizaje' => 'Los estudiantes configuran switches en equipos de 2, documentando cada paso.',
            'estrategias_recursos' => [
                'Packet Tracer',
                'Switches Cisco 2960',
                'Guía de laboratorio impresa'
            ],
            'evaluacion_formativa' => [
                'actividades' => [
                    'Observación durante la configuración en laboratorio',
                    'Preguntas orales sobre comandos CLI',
                    'Revisión de documentación técnica'
                ],
                'instrumentos' => [
                    'Lista de cotejo para configuración',
                    'Rúbrica de documentación',
                    'Registro anecdótico'
                ],
                'evidencias' => [
                    'Capturas de pantalla de configuraciones',
                    'Archivo de configuración del switch',
                    'Documento técnico con diagramas'
                ]
            ],
            'evaluacion_sumativa' => [
                'actividades' => [
                    'Examen práctico en Packet Tracer',
                    'Defensa oral de laboratorio'
                ],
                'instrumentos' => [
                    'Rúbrica de examen práctico (60%)',
                    'Rúbrica de defensa oral (40%)'
                ],
                'evidencias' => [
                    'Archivo .pkt con topología configurada',
                    'Video de demostración',
                    'Informe de laboratorio'
                ]
            ]
        ];

        $tRedes1_1 = Tema::create([
            'unidad_id' => $uRedes1->id, 
            'orden' => 1,
            'titulo' => 'Configuración de VLANs',
            'resultado_aprendizaje' => 'Segmenta tráfico de red utilizando switches gestionables.',
            'horas_teoricas' => 2,
            'horas_practicas' => 4,
            'contenido_conceptual' => [
                'Dominios de Broadcast',
                'Concepto de VLAN',
                'Tipos de puertos (Access y Trunk)',
                'Base de datos VLAN (vlan.dat)'
            ],
            'contenido_procedimental' => [
                'Comandos CLI para crear VLANs',
                'Asignación de puertos a VLANs',
                'Verificación con show vlan brief',
                'Pruebas de conectividad'
            ],
            'contenido_actitudinal' => [
                'Orden en la documentación de configuraciones',
                'Responsabilidad en la segmentación de redes'
            ]
        ]);

        // Referencias Bibliográficas para Tema 1.1
        $tRedes1_1->bibliografias()->attach($bibCCNA->id, ['pagina_desde' => 170, 'pagina_hasta' => 210]);
        $tRedes1_1->bibliografias()->attach($bibCiscoAcademy->id, ['pagina_desde' => 45, 'pagina_hasta' => 89]);
        $tRedes1_1->bibliografias()->attach($bibPacketTracer->id, ['pagina_desde' => 12, 'pagina_hasta' => 35]);

        // Logros e Indicadores para Tema 1.1
        $logro1_1_1 = $tRedes1_1->logros()->create([
            'descripcion' => 'Identifica la necesidad de segmentación de redes mediante VLANs',
            'tipo_logro' => 'SABER'
        ]);
        $logro1_1_1->indicadores()->create(['descripcion' => 'Explica los problemas de dominios de broadcast grandes']);

        $logro1_1_2 = $tRedes1_1->logros()->create([
            'descripcion' => 'Configura VLANs en switches Cisco utilizando CLI',
            'tipo_logro' => 'HACER'
        ]);
        $logro1_1_2->indicadores()->create(['descripcion' => 'Crea VLANs y asigna puertos correctamente usando comandos IOS']);

        $logro1_1_3 = $tRedes1_1->logros()->create([
            'descripcion' => 'Documenta configuraciones de red de manera ordenada y profesional',
            'tipo_logro' => 'SER'
        ]);
        $logro1_1_3->indicadores()->create(['descripcion' => 'Presenta documentación técnica completa con diagramas y comandos']);

        // Secuencia Didáctica para Tema 1.1
        $tRedes1_1->secuencias()->create([
            'momento' => 'INTRODUCCION',
            'descripcion' => 'Análisis de problemas de broadcast en redes planas. Presentación de caso real.',
            'duracion_minutos' => 15
        ]);
        $tRedes1_1->secuencias()->create([
            'momento' => 'RESULTADOS DE APRENDIZAJE/LOGROS',
            'descripcion' => 'Explicación de objetivos del laboratorio y rúbrica de evaluación.',
            'duracion_minutos' => 10
        ]);
        $tRedes1_1->secuencias()->create([
            'momento' => 'CONTENIDOS DE LA CLASE',
            'descripcion' => 'Demostración en vivo: creación de VLANs, asignación de puertos, verificación.',
            'duracion_minutos' => 30
        ]);
        $tRedes1_1->secuencias()->create([
            'momento' => 'CUERPO DE CONTENIDOS',
            'descripcion' => 'Laboratorio práctico: estudiantes configuran topología de 3 VLANs en Packet Tracer.',
            'duracion_minutos' => 60
        ]);
        $tRedes1_1->secuencias()->create([
            'momento' => 'CONCLUSION O CIERRE',
            'descripcion' => 'Verificación de conectividad inter-VLAN. Reflexión sobre errores comunes.',
            'duracion_minutos' => 15
        ]);

        // Crear planificación personal con las secuencias y datos personales
        $this->crearPlanificacionPersonal($tRedes1_1, $docente?->id, $tRedes1_1_personal);

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
        $this->crearPlanificacionPersonal($tRedes1_2, $docente?->id, $tRedes1_2_personal);

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
        $this->crearPlanificacionPersonal($tRedes2_1, $docente?->id, $tRedes2_1_personal);

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
        $this->crearPlanificacionPersonal($tRedes2_2, $docente?->id, $tRedes2_2_personal);

        // ==========================================
        // 3. TELECOMUNICACIONES (Vacío)
        // ==========================================
        $tele = Asignatura::updateOrCreate(
            ['codigo' => 'SIS-413'],
            [
                'nombre' => 'TELECOMUNICACIONES',
                'creditos' => 5,
                'area_desempenio' => 'Telecomunicaciones',
                'tipo_curso' => 'Teórico',
                'modalidad' => 'Presencial',
                'estado' => 'activo',
                'carga_horaria_total' => 80
            ]
        );

        if (!$tele->carreras()->where('carrera_id', $carrera->id)->exists()) {
            $tele->carreras()->attach($carrera->id, ['semestre' => 7, 'sede_id' => $sede->id]);
        }
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
                    'duracion' => $sec->duracion_minutos, // Frontend usa 'duracion'
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
}
