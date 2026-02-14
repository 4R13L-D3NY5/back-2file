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
        // 1. Identificar Docente y Usuario con CI
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

        // Obtener Sede y Carrera
        $sede = Sede::where('codigo', 'CBA')->first();
        $carrera = Carrera::where('codigo', 'SIS')->first();

        // ==========================================
        // 2. ASIGNATURA: TALLER DE REDES
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

        // Limpieza previa
        $this->limpiarAsignatura($redes);

        // ==========================================
        // 3. BIBLIOGRAFÍA
        // ==========================================
        $this->seedBibliografia($redes);

        // ==========================================
        // 4. UNIDADES Y TEMAS
        // ==========================================
        $temasCreados = $this->seedUnidadesYTemas($redes, $user->id);

        // ==========================================
        // 5. CRONOGRAMA
        // ==========================================
        // Obtener grupos y generar cronograma
        $gruposRedes = Grupo::where('asignatura_id', $redes->id)
                            ->where('docente_id', $docente->id)
                            ->with('horarios')
                            ->get();

        if ($gruposRedes->isEmpty()) {
             $this->command->info("No se encontraron grupos asociados al docente {$username} para TALLER DE REDES.");
        } else {
            // Identificar grupo principal (424 o TEORICO)
            $grupoPrincipal = $gruposRedes->first(function($g) {
                return $g->id == 424; 
            }) ?? $gruposRedes->first(function($g) {
                return str_contains($g->nombre, '1') || $g->tipo === 'TEORICO';
            }) ?? $gruposRedes->first();

            $this->command->info("Grupo Principal para Cronograma: ID {$grupoPrincipal->id} ({$grupoPrincipal->nombre})");
            
            $grupoPrincipal->refresh();

            // Limpiar cronograma previo
            Cronograma::whereIn('grupo_id', $gruposRedes->pluck('id'))
                        ->where('asignatura_id', $redes->id)
                        ->delete();
            
            // Generar nuevo cronograma
            $this->generarCronogramaUnificado($grupoPrincipal, $gruposRedes, $redes, $temasCreados);
        }
    }

    private function seedBibliografia($asignatura)
    {
        $bibliografias = [
            [
                'titulo' => 'Fundamentos de Redes',
                'autor' => 'Harris, R., & Hancock, J.',
                'anio' => 2021,
                'tipo' => 'principal',
                'editorial' => 'Editorial Tecnológica'
            ],
            [
                'titulo' => 'Composición y Funcionamiento Interno de Redes',
                'autor' => 'Rodríguez, M.',
                'anio' => 2020,
                'tipo' => 'principal',
                'editorial' => 'Editorial Tecnológica',
            ],
            [
                'titulo' => 'Fundamentos de Direccionamiento IP y Subneteo',
                'autor' => 'Pérez, A.',
                'anio' => 2020,
                'tipo' => 'principal',
                'editorial' => 'Editorial Tecnológica',
            ],
            [
                'titulo' => 'Acceso a la Red (Switching)',
                'autor' => 'López, E.',
                'anio' => 2021,
                'tipo' => 'principal',
                'editorial' => 'Editorial Tecnológica',
            ],
            [
                'titulo' => 'Conectividad IP (Routing)',
                'autor' => 'González, R.',
                'anio' => 2020,
                'tipo' => 'principal',
                'editorial' => 'Editorial Tecnológica',
            ],
            [
                'titulo' => 'Redes de Computadoras',
                'autor' => 'Cysco System', // Sic en el documento
                'anio' => 2018, // Aproximado
                'tipo' => 'complementario',
            ],
            [
                'titulo' => 'Manual de Sistema Operativo Suse Linux',
                'autor' => 'Suse',
                'anio' => 2019,
                'tipo' => 'complementario',
            ],
            [
                'titulo' => 'WI-FI: Cómo construir una red inalámbrica',
                'autor' => 'Carballar, J. A.',
                'anio' => 2015,
                'tipo' => 'complementario',
                'editorial' => 'Alfaomega RA-MA'
            ],
        ];

        foreach ($bibliografias as $bib) {
            $asignatura->bibliografias()->updateOrCreate(
                ['titulo' => $bib['titulo']],
                $bib
            );
        }
    }

    private function seedUnidadesYTemas($asignatura, $userId)
    {
        $unidadesData = [
            [
                'numero' => 1,
                'titulo' => 'FUNDAMENTOS DE REDES',
                'objetivo' => 'Analizar componentes fundamentales de red.',
                'contenido_minimo' => 'Conceptos básicos, SOHO, WiFi, Cableado.',
                'elemento_competencia' => 'Describe componentes de red.',
                'temas' => [
                    [
                        'orden' => 1,
                        'titulo' => 'CONCEPTOS BÁSICOS DE REDES',
                        'resultado_aprendizaje' => 'Analiza y describe los componentes fundamentales de una red de datos, su funcionamiento y roles específicos en entornos Small Office/Home Office (SOHO) y corporativos.',
                        'horas_teoricas' => 2,
                        'horas_practicas' => 4,
                        'contenido_conceptual' => [
                            'Introducción a los conceptos básicos de redes',
                            'Funciones y características de los switches',
                            'Funciones y características de los routers',
                            'Exploración de Firewalls de Nueva Generación (NGF) e Intrusion Prevention Systems (IPS)',
                            'Dispositivos finales y endpoints',
                            'Comprender los Access Points (Puntos de Acceso)',
                            'Redes Small Office/Home Office (SOHO)',
                            'Comparación de servicios en sitio y en la nube',
                            'Principios fundamentales de redes WiFi',
                            'Conceptos esenciales de cableado',
                            'Tabla de direcciones MAC'
                        ],
                        'contenido_procedimental' => [
                            'Identifica topologías SOHO', 'Interpreta el direccionamiento físico (MAC)', 'Compara técnicamente servicios en la nube'
                        ],
                        'contenido_actitudinal' => [
                            'Ética en la seguridad de redes', 'Responsabilidad en el diseño de infraestructura'
                        ],
                        'logros' => [
                            ['tipo' => 'SABER', 'desc' => 'Describe las funciones críticas de switches y routers.', 'ind' => 'Diferencia correctamente entre el envío de tramas (L2) y el enrutamiento de paquetes (L3).'],
                            ['tipo' => 'SABER', 'desc' => 'Identifica componentes de seguridad perimetral como Firewalls de Nueva Generación (NGF) e IPS.', 'ind' => 'Explica cómo un IPS previene intrusiones comparado con un firewall tradicional.'],
                            ['tipo' => 'SABER', 'desc' => 'Explica el funcionamiento y la importancia de la tabla de direcciones MAC.', 'ind' => 'Realiza el seguimiento manual de cómo un switch aprende direcciones.'],
                            ['tipo' => 'SABER', 'desc' => 'Compara las características de los servicios on-premise frente a soluciones en la nube.', 'ind' => 'Categoriza beneficios de escalabilidad y disponibilidad para ambos modelos.'],
                            ['tipo' => 'HACER', 'desc' => 'Aplica principios fundamentales de redes WiFi y conceptos de cableado.', 'ind' => 'Selecciona el estándar 802.11 y el tipo de cable adecuado.']
                        ],
                        'personal' => [
                            'estrategias_metodologicas' => 'Clase magistral interactiva, demostración guiada en simulador.',
                            'estrategias_aprendizaje' => 'Aprendizaje basado en problemas, elaboración de mapas mentales.',
                            'estrategias_recursos' => ['Pizarra', 'Computadora con simulador (Packet Tracer)', 'Proyector'],
                            'evaluacion_formativa' => [
                                'actividades' => ['Foro de discusión sobre seguridad perimetral'],
                                'instrumentos' => ['Lista de cotejo'],
                                'evidencias' => ['Diagrama mental de componentes de red']
                            ],
                            'evaluacion_sumativa' => [
                                'actividades' => ['Examen teórico de conceptos básicos'],
                                'instrumentos' => ['Prueba objetiva (opción múltiple)'],
                                'evidencias' => ['Cuestionario resuelto']
                            ],
                            'secuencia_didactica' => [
                                ['momento' => 'INTRODUCCION', 'duracion' => 10, 'actividad' => 'Lluvia de ideas sobre la importancia de las redes en la conectividad diaria.'],
                                ['momento' => 'RESULTADOS/LOGROS', 'duracion' => 10, 'actividad' => 'Presentación de los objetivos y competencias a adquirir en el tema.'],
                                ['momento' => 'CONTENIDOS DE LA CLASE', 'duracion' => 10, 'actividad' => 'Resumen de hardware esencial y roles de dispositivos.'],
                                ['momento' => 'CUERPO DE CONTENIDOS', 'duracion' => 40, 'actividad' => 'Análisis detallado de seguridad perimetral, medios físicos y tablas MAC.'],
                                ['momento' => 'CONCLUSION O CIERRE', 'duracion' => 10, 'actividad' => 'Resumen de puntos clave y evaluación rápida de comprensión.']
                            ]
                        ],
                        'bibliografia_titulo' => 'Fundamentos de Redes'
                    ]
                ]
            ],
            [
                'numero' => 2,
                'titulo' => 'COMPOSICIÓN Y FUNCIONAMIENTO INTERNO DE REDES',
                'objetivo' => 'Diseñar topologías WAN y LAN.',
                'contenido_minimo' => 'Cisco IOS, Topologías jerárquicas, WAN, Spine-Leaf.',
                'elemento_competencia' => 'Diseña arquitecturas de red escalables.',
                'temas' => [
                    [
                        'orden' => 2,
                        'titulo' => 'FUNDAMENTOS, TOPOLOGÍAS Y ARQUITECTURAS WAN',
                        'resultado_aprendizaje' => 'Diseña y evalúa topologías y arquitecturas de red WAN y LAN utilizando modelos jerárquicos y modernos para optimizar el flujo de datos y la escalabilidad.',
                        'horas_teoricas' => 2, 'horas_practicas' => 4,
                        'contenido_conceptual' => [
                            'Introducción a Cisco IOS',
                            'Secuencia de inicio de equipos',
                            'Sistema de archivos en equipos',
                            'Introducción a las Wide Area Networks (WAN)',
                            'Comparación de topologías de red de 2 niveles y 3 niveles',
                            'Exploración de la topología Spine and Leaf'
                        ],
                        'contenido_procedimental' => [
                            'Navega por el CLI de Cisco', 'Configura routers inicialmente', 'Analiza la escalabilidad en topologías jerárquicas'
                        ],
                        'contenido_actitudinal' => [
                            'Disciplina en la aplicación de configuraciones', 'Interés por las arquitecturas de centros de datos'
                        ],
                        'logros' => [
                            ['tipo' => 'HACER', 'desc' => 'Navega con fluidez en el entorno de línea de comandos (CLI) de Cisco IOS.', 'ind' => 'Ejecuta comandos de configuración básica y verificación sin errores.'],
                            ['tipo' => 'SABER', 'desc' => 'Describe la secuencia de inicio y gestión del sistema de archivos.', 'ind' => 'Localiza y gestiona archivos de configuración.'],
                            ['tipo' => 'DECIDIR', 'desc' => 'Compara topologías de red jerárquicas de 2 y 3 niveles.', 'ind' => 'Justifica la elección de un modelo según tamaño organizacional.'],
                            ['tipo' => 'SABER', 'desc' => 'Explica beneficios de arquitectura Spine and Leaf.', 'ind' => 'Identifica la reducción de latencia este-oeste.'],
                            ['tipo' => 'SABER', 'desc' => 'Identifica tecnologías y propósitos principales de redes WAN.', 'ind' => 'Lista diferencias operativas clave entre LAN y WAN.']
                        ],
                        'personal' => [
                            'estrategias_metodologicas' => 'Inducción didáctica, práctica dirigida en laboratorio virtual.',
                            'estrategias_aprendizaje' => 'Gamificación mediante quizzes rápidos, simulación de escenarios corporativos.',
                            'estrategias_recursos' => ['Manuales de comandos Cisco', 'Simuladores de red', 'Proyector'],
                            'evaluacion_formativa' => [
                                'actividades' => ['Práctica de navegación en consola (CLI)'],
                                'instrumentos' => ['Guía de observación'],
                                'evidencias' => ['Captura de pantalla de configuración base realizada']
                            ],
                            'evaluacion_sumativa' => [
                                'actividades' => ['Laboratorio evaluado de topologías jerárquicas'],
                                'instrumentos' => ['Rúbrica de desempeño'],
                                'evidencias' => ['Archivo de simulación con topología configurada']
                            ],
                            'secuencia_didactica' => [
                                ['momento' => 'INTRODUCCION', 'duracion' => 10, 'actividad' => 'Contextualización de las redes WAN frente a las redes locales LAN.'],
                                ['momento' => 'RESULTADOS/LOGROS', 'duracion' => 10, 'actividad' => 'Definición de habilidades de configuración de IOS y jerarquías de red.'],
                                ['momento' => 'CONTENIDOS DE LA CLASE', 'duracion' => 10, 'actividad' => 'Estructura del sistema de archivos y modelos de diseño de 2 y 3 capas.'],
                                ['momento' => 'CUERPO DE CONTENIDOS', 'duracion' => 40, 'actividad' => 'Estudio profundo de Spine-Leaf y comandos de gestión inicial de equipos.'],
                                ['momento' => 'CONCLUSION O CIERRE', 'duracion' => 10, 'actividad' => 'Reflexión grupal sobre la escalabilidad de las arquitecturas estudiadas.']
                            ]
                        ],
                        'bibliografia_titulo' => 'Composición y Funcionamiento Interno de Redes'
                    ]
                ]
            ],
            [
                'numero' => 3,
                'titulo' => 'FUNDAMENTOS DE DIRECCIONAMIENTO IP Y SUBNETEO',
                'objetivo' => 'Implementar planes de direccionamiento eficientes.',
                'contenido_minimo' => 'IPv4, IPv6, Subneteo, VLSM.',
                'elemento_competencia' => 'Aplica técnicas de subneteo.',
                'temas' => [
                    [
                        'orden' => 3,
                        'titulo' => 'DIRECCIONAMIENTO IP Y SUBNETEO',
                        'resultado_aprendizaje' => 'Implementa planes de direccionamiento eficientes utilizando IPv4 e IPv6, aplicando técnicas de subredes para optimizar el uso del espacio de direcciones en infraestructuras de red.',
                        'horas_teoricas' => 2, 'horas_practicas' => 4,
                        'contenido_conceptual' => [
                            'Introducción al direccionamiento IPv4',
                            'Mascara de subred IPv4',
                            'Conceptos básicos de subredes IPv4',
                            'Introducción a las direcciones IPv6',
                            'Tipos de direcciones IPv6',
                            'Configuración de rutas estáticas IPv6',
                            'Configuración básica de direcciones IPv6'
                        ],
                        'contenido_procedimental' => [
                            'Calcula subredes en formato binario/decimal', 'Configura parámetros IP', 'Crea planes de direccionamiento eficientes'
                        ],
                        'contenido_actitudinal' => [
                            'Precisión matemática', 'Organización lógica de datos técnicos'
                        ],
                        'logros' => [
                            ['tipo' => 'HACER', 'desc' => 'Calcula subredes IPv4 utilizando máscaras de longitud variable (VLSM).', 'ind' => 'Determina correctamente el rango de host y dirección de red.'],
                            ['tipo' => 'SABER', 'desc' => 'Identifica y clasifica los diferentes tipos de direcciones IPv6.', 'ind' => 'Asigna direcciones Global Unicast y Link-Local.'],
                            ['tipo' => 'HACER', 'desc' => 'Configura conectividad básica mediante direccionamiento estático en IPv6.', 'ind' => 'Verifica comunicación mediante comandos ping en IPv6.'],
                            ['tipo' => 'SABER', 'desc' => 'Explica propósito y funcionamiento de rutas estáticas IPv6.', 'ind' => 'Configura una ruta estática predeterminada.'],
                            ['tipo' => 'HACER', 'desc' => 'Aplica conceptos de binario y decimal en resolución de problemas.', 'ind' => 'Convierte direcciones IP entre formatos con precisión.']
                        ],
                        'personal' => [
                            'estrategias_metodologicas' => 'Resolución de problemas en pizarra, método expositivo de lógica binaria.',
                            'estrategias_aprendizaje' => 'Talleres de ejercicios prácticos, autoevaluación de planes de subredes.',
                            'estrategias_recursos' => ['Hojas de trabajo de subneteo', 'Calculadoras IP', 'Simuladores de red'],
                            'evaluacion_formativa' => [
                                'actividades' => ['Ejercicio rápido de cálculo de máscara de red y hosts'],
                                'instrumentos' => ['Registro académico de participación'],
                                'evidencias' => ['Hoja de ejercicios de cálculo binario completada']
                            ],
                            'evaluacion_sumativa' => [
                                'actividades' => ['Examen práctico e individual de diseño de subredes IPv4 e IPv6'],
                                'instrumentos' => ['Escala de calificación por objetivos'],
                                'evidencias' => ['Plan de direccionamiento técnico entregado']
                            ],
                            'secuencia_didactica' => [
                                ['momento' => 'INTRODUCCION', 'duracion' => 10, 'actividad' => 'El problema del agotamiento de IPv4 y la necesidad del subneteo eficiente.'],
                                ['momento' => 'RESULTADOS/LOGROS', 'duracion' => 10, 'actividad' => 'Metas en el dominio del cálculo y configuración de redes numéricas.'],
                                ['momento' => 'CONTENIDOS DE LA CLASE', 'duracion' => 10, 'actividad' => 'Repaso de estructura binaria de la dirección IP.'],
                                ['momento' => 'CUERPO DE CONTENIDOS', 'duracion' => 40, 'actividad' => 'Algoritmos de subneteo VLSM y configuración base de IPv6.'],
                                ['momento' => 'CONCLUSION O CIERRE', 'duracion' => 10, 'actividad' => 'Prueba de conectividad entre las subredes calculadas en clase.']
                            ]
                        ],
                        'bibliografia_titulo' => 'Fundamentos de Direccionamiento IP y Subneteo'
                    ]
                ]
            ],
            [
                'numero' => 4,
                'titulo' => 'ACCESO A LA RED (SWITCHING)',
                'objetivo' => 'Configurar infraestructuras de switching y redundancia.',
                'contenido_minimo' => 'VLANs, Trunks, STP, EtherChannel.',
                'elemento_competencia' => 'Administra redes conmutadas.',
                'temas' => [
                    [
                        'orden' => 4,
                        'titulo' => 'ACCESO A LA RED (SWITCHING)',
                        'resultado_aprendizaje' => 'Configura y administra infraestructuras de switching aplicando protocolos de capa 2 para garantizar la segmentación, redundancia y alta disponibilidad.',
                        'horas_teoricas' => 2, 'horas_practicas' => 4,
                        'contenido_conceptual' => [
                            'Exploración de switches',
                            'Creación y configuración de VLANs',
                            'Configuración de troncales (Trunks)',
                            'Interfaz de VLAN de nivel 3 (SVIs)',
                            'Configuración de "Router on a Stick"',
                            'Conocimiento de Cisco Discovery Protocol (CDP)',
                            'Protocolo de Descubrimiento de Capa de Enlace (LLDP)',
                            'Introducción a EtherChannel',
                            'Introducción a Spanning Tree Protocol (STP)',
                            'Configuración de Portfast en STP',
                            'Configuración de SPAN',
                            'Exploración de StackWise',
                            'Dynamic Trunking Protocol (DTP)'
                        ],
                        'contenido_procedimental' => [
                            'Crea y asigna VLANs en puertos', 'Configura encapsulación dot1q', 'Realiza troubleshooting de STP y EtherChannel'
                        ],
                        'contenido_actitudinal' => [
                            'Responsabilidad en la gestión de redundancia', 'Rigurosidad en la verificación de troncales'
                        ],
                        'logros' => [
                            ['tipo' => 'HACER', 'desc' => 'Implementa VLANs para segmentar el tráfico de red.', 'ind' => 'Verifica el aislamiento de tráfico entre VLANs.'],
                            ['tipo' => 'HACER', 'desc' => 'Configura enlaces troncales (Trunks) con 802.1Q.', 'ind' => 'Asegura paso de múltiples etiquetas de VLAN.'],
                            ['tipo' => 'HACER', 'desc' => 'Establece comunicación inter-VLAN mediante SVIs y Router on a Stick.', 'ind' => 'Logra conectividad total entre segmentos lógicos.'],
                            ['tipo' => 'HACER', 'desc' => 'Optimiza topología lógica mediante Spanning Tree (STP).', 'ind' => 'Reduce tiempo de convergencia manteniendo red libre de bucles.'],
                            ['tipo' => 'HACER', 'desc' => 'Agrega ancho de banda y redundancia con EtherChannel.', 'ind' => 'Valida formación de canal lógico sobre enlaces físicos.']
                        ],
                        'personal' => [
                            'estrategias_metodologicas' => 'Demostración de configuración en vivo, resolución de casos de falla de bucles.',
                            'estrategias_aprendizaje' => 'Laboratorios prácticos individuales, depuración cooperativa de errores.',
                            'estrategias_recursos' => ['Switches de laboratorio o simuladores', 'Cables de consola', 'Manuales técnicos'],
                            'evaluacion_formativa' => [
                                'actividades' => ['Configuración guiada de una red con 3 VLANs y un troncal'],
                                'instrumentos' => ['Lista de verificación técnica'],
                                'evidencias' => ['Reporte de estado del switch (show vlan brief, show interface trunk)']
                            ],
                            'evaluacion_sumativa' => [
                                'actividades' => ['Implementación de una topología con redundancia L2 (STP adaptado)'],
                                'instrumentos' => ['Rúbrica de laboratorio'],
                                'evidencias' => ['Archivo de configuración funcional y topología física replicada']
                            ],
                            'secuencia_didactica' => [
                                ['momento' => 'INTRODUCCION', 'duracion' => 10, 'actividad' => 'Desafíos de los dominios de broadcast en redes grandes.'],
                                ['momento' => 'RESULTADOS/LOGROS', 'duracion' => 10, 'actividad' => 'Competencias en segmentación y optimización de Capa 2.'],
                                ['momento' => 'CONTENIDOS DE LA CLASE', 'duracion' => 10, 'actividad' => 'Conceptos de VLANs y protocolos de redundancia.'],
                                ['momento' => 'CUERPO DE CONTENIDOS', 'duracion' => 40, 'actividad' => 'Práctica intensiva en configuración de Trunks, SVIs y EtherChannel.'],
                                ['momento' => 'CONCLUSION O CIERRE', 'duracion' => 10, 'actividad' => 'Verificación de aislamiento y flujo de datos inter-VLAN.']
                            ]
                        ],
                        'bibliografia_titulo' => 'Acceso a la Red (Switching)'
                    ]
                ]
            ],
            [
                'numero' => 5,
                'titulo' => 'CONECTIVIDAD IP (ROUTING)',
                'objetivo' => 'Implementar protocolos de enrutamiento estático y dinámico.',
                'contenido_minimo' => 'Routing estático, OSPF, HSRP, OSPFv3.',
                'elemento_competencia' => 'Configura enrutamiento avanzado.',
                'temas' => [
                    [
                        'orden' => 5,
                        'titulo' => 'CONECTIVIDAD IP (ROUTING)',
                        'resultado_aprendizaje' => 'Implementa y gestiona protocolos de enrutamiento estático y dinámico (OSPF) para establecer conectividad óptima y redundante.',
                        'horas_teoricas' => 2, 'horas_practicas' => 4,
                        'contenido_conceptual' => [
                            'Introducción al routing',
                            'Configuración de rutas estáticas',
                            'Enrutamiento dinámico',
                            'Fundamentos de OSPF',
                            'Configuración básica de OSPF en el área 0',
                            'Identificación del Router ID en OSPF',
                            'OSPF en múltiples áreas',
                            'Métrica en OSPF',
                            'Tipos de paquetes OSPF',
                            'Tipos de SLAs en OSPF',
                            'Introducción a Protocolos de Redundancia en el Primer Salto (HSRP y VRRP)',
                            'OSPF versión 3'
                        ],
                        'contenido_procedimental' => [
                            'Configura routers Cisco para OSPF', 'Crea rutas estáticas', 'Implementa alta disponibilidad con HSRP'
                        ],
                        'contenido_actitudinal' => [
                            'Compromiso con la eficiencia del enrutamiento', 'Atención al detalle en configuración de protocolos dinámicos'
                        ],
                        'logros' => [
                            ['tipo' => 'HACER', 'desc' => 'Configura rutas estáticas y por defecto.', 'ind' => 'Resuelve problemas de conectividad redirigiendo tráfico.'],
                            ['tipo' => 'HACER', 'desc' => 'Establece adyacencias de vecinos OSPF en Area 0.', 'ind' => 'Confirma estado Full en tabla de vecindad.'],
                            ['tipo' => 'SABER', 'desc' => 'Analiza métrica OSPF basada en costo.', 'ind' => 'Modifica manualmente el costo de interfaz para influir en ruta.'],
                            ['tipo' => 'HACER', 'desc' => 'Configura protocolos de redundancia HSRP o VRRP.', 'ind' => 'Garantiza navegación ante falla física de router principal.'],
                            ['tipo' => 'HACER', 'desc' => 'Implementa OSPF en entornos IPv6 (OSPFv3).', 'ind' => 'Verifica intercambio de prefijos IPv6.']
                        ],
                        'personal' => [
                            'estrategias_metodologicas' => 'Análisis comparativo de protocolos, modelado de redes WAN en simulador.',
                            'estrategias_aprendizaje' => 'Resolución de casos de estudio sobre fallas de ruta, ejercicios prácticos de balanceo.',
                            'estrategias_recursos' => ['Simuladores de red (GNS3, Packet Tracer)', 'Guías de configuración oficial de Cisco'],
                            'evaluacion_formativa' => [
                                'actividades' => ['Configuración de adyacencia OSPF entre dos routers'],
                                'instrumentos' => ['Check-list de comandos de verificación'],
                                'evidencias' => ['Log de adyacencia establecida con éxito']
                            ],
                            'evaluacion_sumativa' => [
                                'actividades' => ['Escenario integral de enrutamiento dinámico multi-área con redundancia'],
                                'instrumentos' => ['Examen práctico en laboratorio virtual'],
                                'evidencias' => ['Topología operativa con convergencia garantizada']
                            ],
                            'secuencia_didactica' => [
                                ['momento' => 'INTRODUCCION', 'duracion' => 10, 'actividad' => 'Por qué necesitamos protocolos dinámicos en redes masivas.'],
                                ['momento' => 'RESULTADOS/LOGROS', 'duracion' => 10, 'actividad' => 'Definición de objetivos en OSPF y alta disponibilidad (First Hop).'],
                                ['momento' => 'CONTENIDOS DE LA CLASE', 'duracion' => 10, 'actividad' => 'Funcionamiento de los algoritmos de estado de enlace (Dijkstra).'],
                                ['momento' => 'CUERPO DE CONTENIDOS', 'duracion' => 40, 'actividad' => 'Configuración práctica de OSPF, Router-IDs y métricas de costo.'],
                                ['momento' => 'CONCLUSION O CIERRE', 'duracion' => 10, 'actividad' => 'Prueba de caída de enlaces y verificación de convergencia automática.']
                            ]
                        ],
                        'bibliografia_titulo' => 'Conectividad IP (Routing)'
                    ]
                ]
            ],
            [
                'numero' => 6,
                'titulo' => 'SERVICIOS IP',
                'objetivo' => 'Desplegar servicios de red esenciales (DHCP, NAT, SSH).',
                'contenido_minimo' => 'DHCP, NAT/PAT, QoS, SSH.',
                'elemento_competencia' => 'Implementa servicios de red.',
                'temas' => [
                    [
                        'orden' => 6,
                        'titulo' => 'SERVICIOS IP',
                        'resultado_aprendizaje' => 'Configura y despliega servicios de red esenciales como DHCP, NAT y SSH para facilitar administración y conectividad.',
                        'horas_teoricas' => 2, 'horas_practicas' => 4,
                        'contenido_conceptual' => [
                            'Configuración de DHCP',
                            'Introducción a Network Address Translation (NAT) y Port Address Translation (PAT)',
                            'Configuración de NAT estático',
                            'Configuración de NAT dinámico',
                            'Traducción de direcciones de puertos (PAT)',
                            'Introducción a Calidad de Servicio (QoS)',
                            'Acceso remoto mediante SSH',
                            'Laboratorio de configuración de HSRP'
                        ],
                        'contenido_procedimental' => [
                            'Configura NAT Outside/Inside', 'Implementa SSH con llaves RSA', 'Gestiona pools de direcciones DHCP'
                        ],
                        'contenido_actitudinal' => [
                            'Conciencia sobre escasez de IPv4', 'Ética en el acceso remoto'
                        ],
                        'logros' => [
                            ['tipo' => 'HACER', 'desc' => 'Configura servidores y agentes de relé DHCP.', 'ind' => 'Verifica que hosts reciban configuración IP automática.'],
                            ['tipo' => 'HACER', 'desc' => 'Implementa NAT estático, dinámico y PAT.', 'ind' => 'Logra que múltiples IPs privadas naveguen con una pública.'],
                            ['tipo' => 'HACER', 'desc' => 'Establece conexiones de administración remota SSH.', 'ind' => 'Deshabilita accesos inseguros (Telnet) y usa cifrado.'],
                            ['tipo' => 'SABER', 'desc' => 'Explica fundamentos de QoS.', 'ind' => 'Identifica mecanismos de marcado y priorización.'],
                            ['tipo' => 'HACER', 'desc' => 'Realiza laboratorios integrales de servicios y redundancia.', 'ind' => 'Integra HSRP y NAT en escenario funcional.']
                        ],
                        'personal' => [
                            'estrategias_metodologicas' => 'Demostración de traducción de direcciones, seminario sobre administración remota.',
                            'estrategias_aprendizaje' => 'Práctica hands-on en laboratorios, investigación sobre modelos QoS.',
                            'estrategias_recursos' => ['Routers con soporte NAT', 'Clientes SSH', 'Analizadores de paquetes (Wireshark)'],
                            'evaluacion_formativa' => [
                                'actividades' => ['Configuración de PAT para salida a Internet simulada'],
                                'instrumentos' => ['Lista de verificación de conectividad'],
                                'evidencias' => ['Tabla de traducciones NAT (show ip nat translations)']
                            ],
                            'evaluacion_sumativa' => [
                                'actividades' => ['Laboratorio integral de Servicios IP y Seguridad'],
                                'instrumentos' => ['Rúbrica de laboratorio práctico'],
                                'evidencias' => ['Configuración persistente en memoria start-up']
                            ],
                            'secuencia_didactica' => [
                                ['momento' => 'INTRODUCCION', 'duracion' => 10, 'actividad' => 'Cómo coexisten las redes privadas con el IPv4 público global.'],
                                ['momento' => 'RESULTADOS/LOGROS', 'duracion' => 10, 'actividad' => 'Dominio de la asignación dinámica (DHCP) y traducción de red (NAT).'],
                                ['momento' => 'CONTENIDOS DE LA CLASE', 'duracion' => 10, 'actividad' => 'Repaso de puertos TCP/UDP para NAT y encriptación en SSH.'],
                                ['momento' => 'CUERPO DE CONTENIDOS', 'duracion' => 40, 'actividad' => 'Implementación paso a paso de servicios en un router frontera.'],
                                ['momento' => 'CONCLUSION O CIERRE', 'duracion' => 10, 'actividad' => 'Prueba de acceso SSH externo y verificación de pool DHCP.']
                            ]
                        ],
                        'bibliografia_titulo' => 'Redes de Computadoras' // Aproximación
                    ]
                ]
            ],
            [
                'numero' => 7,
                'titulo' => 'SEGURIDAD',
                'objetivo' => 'Implementar medidas de seguridad perimetral y de capa 2.',
                'contenido_minimo' => 'ACLs, Port Security, DHCP Snooping, AAA.',
                'elemento_competencia' => 'Asegura la red contra amenazas comunes.',
                'temas' => [
                    [
                        'orden' => 7,
                        'titulo' => 'SEGURIDAD EN REDES',
                        'resultado_aprendizaje' => 'Implementa medidas de seguridad perimetral y de capa 2 utilizando ACLs, seguridad de puertos y autenticación.',
                        'horas_teoricas' => 2, 'horas_practicas' => 4,
                        'contenido_conceptual' => [
                            'Fundamentos de seguridad en redes',
                            'Componentes de un programa de seguridad',
                            'Elementos de políticas de seguridad relacionadas con claves',
                            'Introducción a Listas de Control de Acceso (ACLs)',
                            'Listas de Control de Acceso Estándar',
                            'Listas de Control de Acceso Extendido',
                            'Autenticación, Autorización y Contabilidad (AAA) con Tacacs y Radius',
                            'Seguridad de Capa 2 con Port Security',
                            'Protección de la consola del equipo',
                            'Seguridad de Capa 2 con DHCP Snooping'
                        ],
                        'contenido_procedimental' => [
                            'Configura ACLs de filtrado', 'Implementa restricciones de Port Security', 'Activa DHCP Snooping en VLANs'
                        ],
                        'contenido_actitudinal' => [
                            'Compromiso con la privacidad de los datos', 'Ética profesional en la gestión de seguridad'
                        ],
                        'logros' => [
                            ['tipo' => 'SABER', 'desc' => 'Explica elementos fundamentales de políticas de seguridad.', 'ind' => 'Describe componentes de programa de seguridad robusto.'],
                            ['tipo' => 'HACER', 'desc' => 'Configura ACLs estándar y extendidas.', 'ind' => 'Deniega tráfico específico mientras permite el resto.'],
                            ['tipo' => 'HACER', 'desc' => 'Implementa seguridad de Capa 2 (Port Security).', 'ind' => 'Configura violación de puerto para apagar ante MAC no autorizada.'],
                            ['tipo' => 'HACER', 'desc' => 'Establece mecanismos contra suplantación (DHCP Snooping).', 'ind' => 'Identifica puertos confiables y no confiables.'],
                            ['tipo' => 'HACER', 'desc' => 'Aplica autenticación remota AAA (TACACS+/RADIUS).', 'ind' => 'Configura acceso a consola protegido.']
                        ],
                        'personal' => [
                            'estrategias_metodologicas' => 'Análisis de casos de ciberataques, taller de mitigación de amenazas.',
                            'estrategias_aprendizaje' => 'Simulación de ataques y defensas, redacción de políticas de seguridad.',
                            'estrategias_recursos' => ['Simuladores de red', 'Guías de hardening Cisco'],
                            'evaluacion_formativa' => [
                                'actividades' => ['Aplicación de una ACL estándar en router frontera'],
                                'instrumentos' => ['Guía de autoevaluación técnica'],
                                'evidencias' => ['Prueba de conectividad bloqueada por ACL']
                            ],
                            'evaluacion_sumativa' => [
                                'actividades' => ['Proyecto final de seguridad perimetral y de acceso'],
                                'instrumentos' => ['Rúbrica de evaluación integradora'],
                                'evidencias' => ['Red protegida contra intrusiones L2 y filtrado L3']
                            ],
                            'secuencia_didactica' => [
                                ['momento' => 'INTRODUCCION', 'duracion' => 10, 'actividad' => 'Panorama actual de amenazas en redes de datos empresariales.'],
                                ['momento' => 'RESULTADOS/LOGROS', 'duracion' => 10, 'actividad' => 'Definición de objetivos en el aseguramiento de la infraestructura.'],
                                ['momento' => 'CONTENIDOS DE LA CLASE', 'duracion' => 10, 'actividad' => 'Repaso de tipos de ACLs y seguridad de puerto.'],
                                ['momento' => 'CUERPO DE CONTENIDOS', 'duracion' => 40, 'actividad' => 'Laboratorio de configuración de AAA, Port Security y ACLs extendidas.'],
                                ['momento' => 'CONCLUSION O CIERRE', 'duracion' => 10, 'actividad' => 'Reflexión sobre el equilibrio entre seguridad y operatividad.']
                            ]
                        ],
                        'bibliografia_titulo' => 'Fundamentos de Redes'
                    ]
                ]
            ],
        ];

        $temasCreados = collect();

        foreach ($unidadesData as $uData) {
            $unidad = Unidad::create([
                'asignatura_id' => $asignatura->id,
                'numero' => $uData['numero'],
                'titulo' => $uData['titulo'],
                'objetivo' => $uData['objetivo'],
                'contenido_minimo' => $uData['contenido_minimo'],
                'elemento_competencia' => $uData['elemento_competencia']
            ]);

            foreach ($uData['temas'] as $tData) {
                // Crear Tema
                $tema = Tema::create([
                    'unidad_id' => $unidad->id,
                    'orden' => $tData['orden'],
                    'titulo' => $tData['titulo'],
                    'resultado_aprendizaje' => $tData['resultado_aprendizaje'],
                    'horas_teoricas' => $tData['horas_teoricas'],
                    'horas_practicas' => $tData['horas_practicas'],
                    'contenido_conceptual' => $tData['contenido_conceptual'],
                    'contenido_procedimental' => $tData['contenido_procedimental'],
                    'contenido_actitudinal' => $tData['contenido_actitudinal'],
                    'contenido_items' => $tData['contenido_conceptual'] // ADDED: Populate items with conceptual content
                ]);

                // Crear Logros e Indicadores
                foreach ($tData['logros'] as $logroData) {
                    $logro = $tema->logros()->create([
                        'tipo_logro' => $logroData['tipo'],
                        'descripcion' => $logroData['desc']
                    ]);
                    $logro->indicadores()->create(['descripcion' => $logroData['ind']]);
                }

                // Asociar Bibliografía
                if (isset($tData['bibliografia_titulo'])) {
                    $bib = Bibliografia::where('titulo', 'LIKE', '%' . $tData['bibliografia_titulo'] . '%')->first();
                    if ($bib) {
                        $tema->bibliografias()->sync([$bib->id]);
                    }
                }

                // Crear Secuencias Didácticas (Para tabla secuencias_temas)
                if (isset($tData['personal']['secuencia_didactica'])) {
                    foreach ($tData['personal']['secuencia_didactica'] as $sec) {
                        $tema->secuencias()->create([
                            'momento' => $sec['momento'],
                            'duracion_minutos' => $sec['duracion'],
                            'descripcion' => $sec['actividad']
                        ]);
                    }
                }

                // Crear Planificación Personal
                $this->crearPlanificacionPersonal($tema, $userId, $tData['personal']);
                
                $temasCreados->push($tema);
            }
        }
        return $temasCreados;
    }


    private function crearPlanificacionPersonal($tema, $userId, $personalData = [])
    {
        if (!$userId) return;

        // Transformar secuencias para planificacion personal
        $secuenciasArray = [];
        if(isset($personalData['secuencia_didactica'])) {
             foreach($personalData['secuencia_didactica'] as $idx => $sec) {
                 $secuenciasArray[] = [
                    'id' => time() + $idx + rand(0, 1000),
                    'momento' => $sec['momento'],
                    'duracion' => (int)$sec['duracion'],
                    'actividad' => $sec['actividad']
                 ];
             }
        }

        $planificacionData = [
            'estrategias_metodologicas' => $personalData['estrategias_metodologicas'] ?? null,
            'estrategias_aprendizaje' => $personalData['estrategias_aprendizaje'] ?? null,
            'estrategias_recursos' => $personalData['estrategias_recursos'] ?? [],
            'evaluacion_formativa' => $personalData['evaluacion_formativa'] ?? [],
            'evaluacion_sumativa' => $personalData['evaluacion_sumativa'] ?? [],
            'secuencia_didactica' => $secuenciasArray
        ];

        PlanificacionPersonal::updateOrCreate(
            ['tema_id' => $tema->id, 'user_id' => $userId],
            $planificacionData
        );
    }

    private function generarCronogramaUnificado($grupoPrincipal, $todosLosGrupos, $asignatura, $listaTemas)
    {
        $fechaInicio = Carbon::create(2026, 2, 9);
        $fechaFin = Carbon::create(2026, 6, 27);
        
        $examenes = [
            '2026-03-25' => '1er Parcial',
            '2026-05-20' => '2do Parcial',
            '2026-06-10' => 'Examen Final',
            '2026-07-01' => '2da Instancia'
        ];

        $horarios = $todosLosGrupos->pluck('horarios')->flatten();

        // Mapeo preciso de días
        $mapLocal = [
            'Lunes' => 1, 'Martes' => 2, 'Miercoles' => 3, 'Miércoles' => 3, 
            'Jueves' => 4, 'Viernes' => 5, 'Sabado' => 6, 'Sábado' => 6, 'Domingo' => 7
        ];

        $diasClase = $horarios->map(function($h) use ($mapLocal) {
            $diaNorm = ucfirst(strtolower(str_replace(['é', 'á'], ['e', 'a'], $h->dia))); 
            return $mapLocal[$diaNorm] ?? null;
        })->filter()->unique()->values()->toArray();

        $fechaActual = $fechaInicio->copy();
        $sesionCount = 1;
        $maxSesiones = 12; // 6 weeks * 2 sessions

        // Balancear temas: Tenemos 7 temas y 12 sesiones.
        // Aprox 1-2 sesiones por tema.
        $sesionesPorTema = max(1, floor($maxSesiones / $listaTemas->count()));

        while ($fechaActual->lte($fechaFin) && $sesionCount <= $maxSesiones) {
            $diaSemana = $fechaActual->dayOfWeekIso;

            if (in_array($diaSemana, $diasClase)) {
                $observaciones = null;
                $tipoContenido = 'Práctica';
                $fechaStr = $fechaActual->format('Y-m-d');

                // Lógica de tipo de sesión basada en día (configurable)
                // Lunes (1) -> Práctica, Miércoles (3) -> Teoría
                if ($diaSemana === 3) $tipoContenido = 'Teoría';
                else $tipoContenido = 'Práctica';
                
                if (isset($examenes[$fechaStr])) {
                    $observaciones = "EXAMEN: " . $examenes[$fechaStr];
                    $tipoContenido = 'Evaluación';
                }

                // Selección de Tema
                $temaId = null;
                $conceptual = null;
                $procedimental = null;
                $temaActual = null;
                
                if ($listaTemas && $listaTemas->isNotEmpty() && $tipoContenido !== 'Evaluación') {
                    $indexTema = min(floor(($sesionCount - 1) / $sesionesPorTema), $listaTemas->count() - 1);
                    $temaActualObj = $listaTemas[$indexTema];
                    
                    // Recargar tema para asegurar relaciones
                    $temaActual = Tema::with(['logros', 'planificacionPersonal'])->find($temaActualObj->id);
                    $temaId = $temaActual->id;
                    
                    if ($tipoContenido === 'Teoría') {
                        $conceptual = $temaActual->contenido_conceptual;
                    } else {
                        $procedimental = $temaActual->contenido_procedimental;
                    }
                }

                $diasDesdeInicio = $fechaInicio->diffInDays($fechaActual);
                $semanaAcademica = (int)floor($diasDesdeInicio / 7) + 1;


                // Recopilar Criterios e Instrumentos del Tema
                $criteriosDesempeno = [];
                $instrumentosEvaluacion = [];

                if ($temaActual) {
                    // Criterios desde Logros
                    $criteriosDesempeno = $temaActual->logros->map(function($l) {
                        return $l->descripcion;
                    })->toArray(); // Guardar como Array

                    // Instrumentos desde Planificación Personal
                    $planPersonal = PlanificacionPersonal::where('tema_id', $temaActual->id)->where('user_id', $grupoPrincipal->docente->user_id)->first();
                    if ($planPersonal) {
                        $instFormativa = $planPersonal->evaluacion_formativa['instrumentos'] ?? [];
                        $instSumativa = $planPersonal->evaluacion_sumativa['instrumentos'] ?? [];
                        $todosInstrumentos = array_unique(array_merge($instFormativa, $instSumativa));
                        $instrumentosEvaluacion = array_values($todosInstrumentos); // Guardar como Array (reindexado)
                    }
                    
                    // NEW: Populate contenido_items_seleccionados
                    if (!empty($temaActual->contenido_items)) {
                        $contenidoItemsSeleccionados = [];
                        foreach ($temaActual->contenido_items as $idx => $item) {
                             $contenidoItemsSeleccionados[] = "{$temaActual->id}:{$idx}";
                        }
                    } elseif (!empty($temaActual->contenido_conceptual) && is_array($temaActual->contenido_conceptual)) {
                        $contenidoItemsSeleccionados = [];
                         foreach ($temaActual->contenido_conceptual as $idx => $item) {
                             $contenidoItemsSeleccionados[] = "{$temaActual->id}:{$idx}";
                        }
                    }
                }

                $cronograma = Cronograma::create([
                    'grupo_id' => $grupoPrincipal->id,
                    'asignatura_id' => $asignatura->id,
                    'fecha' => $fechaActual->toDateString(),
                    'numero_sesion' => $sesionCount++,
                    'semana_academica' => $semanaAcademica,
                    'observaciones' => $observaciones,
                    'tema_id' => $temaId,
                    'contenido_conceptual' => $conceptual, 
                    'contenido_procedimental' => $procedimental,
                    'contenido_actitudinal' => ['Participación activa y ética.'],
                    'criterios_desempeno' => $criteriosDesempeno,
                    'instrumentos_evaluacion' => $instrumentosEvaluacion,
                    'contenido_items_seleccionados' => isset($contenidoItemsSeleccionados) ? $contenidoItemsSeleccionados : [],
                    'cumplido' => false,
                    'pedagogico' => ['tipo_sesion' => $tipoContenido]
                ]);

                if ($temaId) {
                    $cronograma->temas()->attach($temaId);
                }
            }
            $fechaActual->addDay();
        }
    }

    private function limpiarAsignatura($asignatura) {
        // Limpieza profunda para evitar duplicados
        foreach($asignatura->unidades as $u) {
            foreach($u->temas as $t) {
                PlanificacionPersonal::where('tema_id', $t->id)->delete();
                foreach($t->logros as $logro) {
                    $logro->indicadores()->delete();
                }
                $t->logros()->delete();
                $t->secuencias()->delete();
            }
            $u->temas()->delete();
            $u->delete();
        }
        // Limpieza de bibliografias asociadas pivot
        $asignatura->bibliografias()->delete();
    }
}
