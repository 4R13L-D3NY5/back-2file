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

        // Limpieza contenido
        foreach($redes->unidades as $u) {
            $u->temas()->delete();
            $u->delete();
        }
        $redes->bibliografias()->delete();

        // Bibliografia
        $redes->bibliografias()->create(['titulo' => 'CCNA Routing and Switching Official Cert Guide', 'autor' => 'Wendell Odom', 'anio' => 2021, 'tipo' => 'BÁSICA']);
        $redes->bibliografias()->create(['titulo' => 'Computer Networking: A Top-Down Approach', 'autor' => 'Kurose & Ross', 'anio' => 2020, 'tipo' => 'BÁSICA']);
        $redes->bibliografias()->create(['titulo' => 'Data Communications and Networking', 'autor' => 'Behrouz A. Forouzan', 'anio' => 2012, 'tipo' => 'BÁSICA']);
        $redes->bibliografias()->create(['titulo' => 'Network Warrior', 'autor' => 'Gary A. Donahue', 'anio' => 2011, 'tipo' => 'BÁSICA']);
        $redes->bibliografias()->create(['titulo' => 'Cisco Networking Academy Program', 'autor' => 'Cisco Systems', 'anio' => 2018, 'tipo' => 'BÁSICA']);
        $redes->bibliografias()->create(['titulo' => 'TCP/IP Illustrated', 'autor' => 'W. Richard Stevens', 'anio' => 1994, 'tipo' => 'COMPLEMENTARIA']);
        $redes->bibliografias()->create(['titulo' => 'Network Security Essentials', 'autor' => 'William Stallings', 'anio' => 2017, 'tipo' => 'COMPLEMENTARIA']);
        $redes->bibliografias()->create(['titulo' => 'Packet Tracer Labs', 'autor' => 'Cisco', 'anio' => 2022, 'tipo' => 'COMPLEMENTARIA']);

        $uRedes = Unidad::create([
            'asignatura_id' => $redes->id, 
            'numero' => 1,
            'titulo' => 'VLANs y Trunking',
            'objetivo' => 'Segmentar redes lógicas para mejorar seguridad y rendimiento.',
            'contenido_minimo' => 'VLAN, 802.1Q, VTP, DTP',
            'elemento_competencia' => 'Configura VLANs en entornos conmutados.'
        ]);

        $tRedes = Tema::create([
            'unidad_id' => $uRedes->id, 
            'orden' => 1,
            'titulo' => 'Configuración de VLANs',
            'resultado_aprendizaje' => 'Segmenta tráfico de red utilizando switches gestionables.',
            'horas_teoricas' => 1,
            'horas_practicas' => 3,
            'contenido_conceptual' => ['Dominios de Broadcast', 'Etiquetado de tramas (Tagging)'],
            'contenido_procedimental' => ['Comandos CLI para VLAN y Trunk', 'Pruebas de ping entre VLANs'],
            'evaluacion_sumativa' => ['Examen Práctico Packet Tracer', 'Defensa de Laboratorio']
        ]);

         $tRedes->secuencias()->create(['momento' => 'Inicio', 'descripcion' => 'Análisis de problemas de broadcast.', 'duracion_minutos' => 10]);
         $tRedes->secuencias()->create(['momento' => 'Desarrollo', 'descripcion' => 'Laboratorio: Configuración de switches.', 'duracion_minutos' => 70]);
         $tRedes->secuencias()->create(['momento' => 'Cierre', 'descripcion' => 'Verificación de conectividad inter-VLAN.', 'duracion_minutos' => 10]);

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
}
