# Diagrama Entidad-Relación (ERD) - SISA 2.0 (Completo)

**Simbología:**
*   Las tablas en **<span style="color:orange">NARANJA</span>** (`:::university`) representan datos que provienen o se sincronizan desde el **Sistema Universitario**.
*   Las tablas en **AZUL** son propias de **SISA** (Datos locales enriquecidos).

```mermaid
erDiagram
    classDef university fill:#ffeedd,stroke:#ff9900,stroke-width:2px;

    %% --- ACTORES & SEGURIDAD (INTEGRACIÓN) ---
    USERS {
        bigint id PK
        string name
        string email
        string password "Hash"
        string estado "ACTIVO"
    }
    class USERS university

    DIRECTORS {
        bigint id PK
        bigint user_id FK
        string nombres
        string apellidos
        string titulo
    }
    class DIRECTORS university

    DOCENTES {
        bigint id PK
        bigint user_id FK
        string nombre_completo
        string celular
    }
    class DOCENTES university
    
    ESTUDIANTES {
        bigint id PK
        string codigo "ID Externo"
        string nombres
        string apellidos
    }
    class ESTUDIANTES university

    %% --- ESTRUCTURA ACADÉMICA (CORE - INTEGRACIÓN) ---
    CARRERAS {
        bigint id PK
        string nombre
        string facultad
        string sede
        bigint director_id FK
    }
    class CARRERAS university

    ASIGNATURAS {
        bigint id PK
        string codigo "Espejo"
        string nombre "Espejo"
        string semestre "Espejo"
        integer creditos "Espejo"
        %% --- EXTENSIÓN LOCAL SISA ---
        text justificacion
        text metodologia_general
        text sistema_evaluacion
        integer carga_horaria_total
    }
    class ASIGNATURAS university

    MATRICULAS {
        bigint id PK
        bigint estudiante_id FK
        bigint asignatura_id FK
        string gestion
    }
    class MATRICULAS university

    %% --- MÓDULO 1: DOCUMENTACIÓN Y PLANIFICACIÓN (LOCAL) ---
    UNIDADES {
        bigint id PK
        bigint asignatura_id FK
        string numero
        string titulo
        text objetivo
        text contenido_minimo
    }

    TEMAS {
        bigint id PK
        bigint unidad_id FK
        string titulo
        json contenido_conceptual
        json contenido_procedimental
        json contenido_actitudinal
        integer horas_practicas
        integer horas_teoricas
    }

    LOGROS_ESPERADOS {
        bigint id PK
        bigint tema_id FK
        string descripcion
        string tipo_logro "Saber, Hacer, Ser"
        string periodo "1er Parcial, 2do..."
    }

    INDICADORES {
        bigint id PK
        bigint logro_esperado_id FK
        string descripcion
    }

    BIBLIOGRAFIAS {
        bigint id PK
        string titulo
        string autor
        string anio
        string isbn
        string tipo
    }

    %% --- MÓDULO 2 Y 3: EJECUCIÓN (ASISTENCIA Y CLASES) ---
    HORARIOS {
        bigint id PK
        bigint asignatura_id FK
        string dia
        time hora_inicio
        time hora_fin
        string aula
    }
    class HORARIOS university

    CRONOGRAMAS {
        bigint id PK
        bigint asignatura_id FK
        integer numero_sesion
        date fecha "Nullable (Plan Maestro)"
        string periodo_examen
        text contenido_conceptual "Snapshot"
    }

    SECUENCIAS_DIDACTICAS {
        bigint id PK
        bigint cronograma_id FK
        string momento
        text actividad
        string duracion
    }

    ASISTENCIAS {
        bigint id PK
        bigint cronograma_id FK
        bigint matricula_id FK
        boolean asistio
    }

    %% --- MÓDULO 6 Y 7: EVALUACIÓN Y BANCO (LOCAL) ---
    BANCO_PREGUNTAS {
        bigint id PK
        bigint logro_esperado_id FK
        string enunciado
        string tipo "Seleccion..."
        json opciones
        string respuesta_correcta
        string dificultad
        integer peso
        bigint created_by FK
    }

    EVALUACIONES {
        bigint id PK
        bigint asignatura_id FK
        string nombre
        string parcial
        date fecha_examen
        time hora_inicio
        integer duracion_minutos
        boolean mezclar_preguntas
        string estado
    }

    EXAMENES_GENERADOS {
        bigint id PK
        bigint evaluacion_id FK
        string tipo "TIPO A, B"
        json patron_respuestas_json
    }

    EXAMEN_PREGUNTAS {
        bigint id PK
        bigint examen_generado_id FK
        bigint banco_pregunta_id FK
        integer orden
    }

    %% RELACIONES
    CARRERAS ||--|{ ASIGNATURAS : tiene
    DIRECTORS ||--|{ CARRERAS : dirige
    USERS ||--|| DIRECTORS : es
    USERS ||--|| DOCENTES : es
    
    DOCENTES ||--|{ ASIGNATURAS : dicta
    
    ESTUDIANTES ||--|{ MATRICULAS : cursa
    
    ASIGNATURAS ||--|{ MATRICULAS : tiene_inscritos
    ASIGNATURAS ||--|{ UNIDADES : estructura
    ASIGNATURAS ||--|{ HORARIOS : se_dicta
    ASIGNATURAS ||--|{ CRONOGRAMAS : planifica
    ASIGNATURAS ||--|{ EVALUACIONES : evalua
    ASIGNATURAS ||--|{ BIBLIOGRAFIAS : refiere

    UNIDADES ||--|{ TEMAS : compone
    TEMAS ||--|{ LOGROS_ESPERADOS : define
    LOGROS_ESPERADOS ||--|{ INDICADORES : mide
    LOGROS_ESPERADOS ||--|{ BANCO_PREGUNTAS : origen_de

    CRONOGRAMAS ||--|{ SECUENCIAS_DIDACTICAS : detalles
    CRONOGRAMAS ||--|{ ASISTENCIAS : registra

    MATRICULAS ||--|{ ASISTENCIAS : asiste

    EVALUACIONES ||--|{ EXAMENES_GENERADOS : versiones
    EXAMENES_GENERADOS ||--|{ EXAMEN_PREGUNTAS : contiene
    BANCO_PREGUNTAS ||--|{ EXAMEN_PREGUNTAS : usa
```
