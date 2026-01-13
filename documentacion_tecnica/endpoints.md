# Documentación Técnica de Endpoints (Incremental)

Este documento servirá como referencia viva y verificada de los endpoints del sistema. Se actualizará módulo por módulo conforme se verifique contra el Frontend desplegado.

---

## Módulo 1: Asignaturas y Planificación
**Estado de Verificación:** ✅ COMPLETADO PARCIAL (Detalles + Temas)
**Archivo Frontend Referencia:** `Academico/src/pages/documentacion/AsignaturaEditPage.vue`

### 1.1 Detalles de Asignatura (Información General)
Permite editar la información base, incluyendo campos extendidos que no vienen de la API.

- **Método:** `PUT`
- **URL:** `/api/asignaturas/{ID}`
- **Controlador:** `AsignaturaController@update`

#### Payload (JSON Request)
El frontend envía estos campos. El backend los mapea internamente a las columnas correctas.

```json
{
  "sigla": "SIS-301",
  "descripcion": "Introducción a...",
  "objetivo_general": "Desarrollar competencias...", // Se guarda en 'proposito_general'
  "justificacion": "La ingeniería...",
  "saberes_previos": "Programación I...",            // Se guarda en 'requisitos'
  "contenido_minimo": "Ciclo de vida...",
  "metodologia_ensenanza": "Clases magistrales...",  // Se guarda en 'metodologia_general'
  "criterios_evaluacion": "Exámenes...",             // Se guarda en 'sistema_evaluacion'
  "horas_teoricas": 40,
  "horas_practicas": 20,
  "horas_laboratorio": 20
}
```

### 1.2 Editar Tema Completo (Unificado)
Este endpoint permite guardar todos los datos del formulario de un tema: contenidos, estrategias, evaluaciones y bibliografía.

- **Método:** `PUT`
- **URL:** `/api/planificacion/temas/{ID}/contenido`
- **Controlador:** `PlanificacionController@updateTema`

#### Payload (JSON Request)
Estructura exacta que envía el Frontend (`formTema`):

```json
{
  "contenidos": {
    "conceptual": ["Concepto 1", "Concepto 2"],    // Array de Strings
    "procedimental": ["Procedimiento 1"],           // Array de Strings
    "actitudinal": ["Actitud 1"]                    // Array de Strings
  },
  "estrategias": {
    "metodologicas": "Explicación magistral...",    // Texto (TextArea)
    "aprendizaje": "El estudiante resume...",       // Texto (TextArea)
    "recursos": ["Proyector", "Pizarra"]            // Array de Strings
  },
  "evaluacion": {
    "formativa": {
      "actividades": ["Debate"],                    // Array (Select Multiple)
      "instrumentos": ["Lista de Cotejo"],          // Array (Select Multiple)
      "evidencias": ["Informe"]                     // Array (Select Multiple)
    },
    "sumativa": {
      "actividades": ["Examen Final"],
      "instrumentos": ["Rúbrica"],
      "evidencias": ["Examen Escrito"]
    }
  },
  "referencias_bibliograficas": [
    {
      "bibliografia_id": 15,                        // ID de la tabla `bibliografias`
      "pagina_desde": 10,                           // Entero
      "pagina_hasta": 25                            // Entero
    }
  ]
}
```

#### Tablas en Base de Datos
- **`temas`**:
    - `contenido_conceptual` (JSON)
    - `contenido_procedimental` (JSON)
    - `contenido_actitudinal` (JSON)
    - `estrategias_metodologicas` (TEXT)
    - `estrategias_aprendizaje` (TEXT)
    - `estrategias_recursos` (JSON)
    - `evaluacion_formativa` (JSON)
    - `evaluacion_sumativa` (JSON)
- **`tema_bibliografia`** (Pivot):
    - `tema_id`
    - `bibliografia_id`
    - `pagina_desde`
    - `pagina_hasta`

---

### 1.2 Obtener Datos del Tema
Recupera el objeto completo para poblar la vista.

- **Método:** `GET`
- **URL:** `/api/planificacion/temas/{ID}/full`
- **Respuesta:** Mismo formato JSON que el Payload de envío, más IDs y timestamps.

---

### 1.3 Secuencia Didáctica
Endpoints para gestionar los "momentos" de la clase (Inicio, Desarrollo, Cierre).

- **Crear Momento:** `POST /api/planificacion/temas/{ID}/secuencias`
    - Payload: `{ "momento": "INICIO", "actividad": "...", "duracion_minutos": 15 }`
- **Eliminar Momento:** `DELETE /api/planificacion/secuencias/{ID}`
- **Tabla:** `secuencias_temas`

---

### 1.4 Banco de Preguntas
Endpoints para gestionar preguntas asociadas a Logros Esperados.

- **Importar Excel:** `POST /api/banco-preguntas/import`
    - Payload (Multipart): `file` (.xlsx), `logro_esperado_id`.
- **Listar:** `GET /api/banco-preguntas?logro_id={ID}`
- **Tabla:** `banco_preguntas`

---

## Módulo 2: Gestión de Carreras (Dashboard)
**Estado de Verificación:** ✅ COMPLETADO
**Archivo Frontend Referencia:** `Academico/src/pages/admin/CarrerasPage.vue`

Este módulo funciona en modo **Sincronización Híbrida**. Al consultar la lista, el backend recupera datos frescos de la API University, actualiza la base de datos local y devuelve los registros locales enriquecidos con contadores y estados.

### 2.1 Listar Carreras (Con Filtros y Estadísticas)
Retorna la lista de carreras activas, incluyendo contadores de asignaturas y docentes.

- **Método:** `GET`
- **URL:** `/api/carreras`
- **Parámetros (Query):**
    - `sede_id` (Opcional): ID de la sede para filtrar (Ej: `1` para Cochabamba).
- **Controlador:** `CarreraController@index`

#### Respuesta (JSON)
Formato exacto esperado por el Store `carreras.js`.

```json
[
  {
    "id": 1,
    "nombre": "Ingeniería de Sistemas",
    "codigo": "SIS",            // Usado en las tarjetas
    "sede_id": 1,              // Mapeado a partir del branchCode (CBA -> 1)
    "activo": true,
    "asignaturas_count": 45,   // Contador real de base de datos local
    "docentes_count": 12       // Contador real (basado en asignaturas vinculadas)
  }
]
```

---

## Módulo 3: Planificación Semestral (Nuevo)
**Estado de Verificación:** 🔄 EN PROGRESO (Backend Listo)
**Archivo Frontend Referencia:** `Academico/src/pages/documentacion/PlanificacionPage.vue`

Este módulo maneja la distribución de temas en el tiempo (Generador Automático).

### 3.1 Obtener Estado Completo
Recupera la configuración de calendario, los horarios semanales y la lista de sesiones generadas (si existen).

- **Método:** `GET`
- **URL:** `/api/planificacion-semestral/{asignaturaId}`
- **Respuesta:**
  ```json
  {
    "config": { "fecha_inicio_clases": "2026-02-09", "fecha_fin_clases": "2026-06-27", "gestion_academica": "2026-I" },
    "horarios": [ { "dia": "Martes", "hora_inicio": "07:00", "hora_fin": "09:00", "aula": "301" } ],
    "planificacion": [
       { "numero_sesion": 1, "fecha": "2026-02-10", "tema": "Intro", "contenido_conceptual": "..." }
    ]
  }
  ```

### 3.2 Guardar Configuración y Horarios
Guarda las fechas del semestre y los horarios de clase (Lunes, Martes...).

- **Método:** `POST`
- **URL:** `/api/planificacion-semestral/{asignaturaId}/config`
- **Payload:**
  ```json
  {
    "fecha_inicio_clases": "2026-02-09",
    "fecha_fin_clases": "2026-06-27",
    "gestion_academica": "2026-I",
    "horarios": [ { "dia": "Martes", "hora_inicio": "07:00", "hora_fin": "09:00", "aula": "301" } ]
  }
  ```

### 3.3 Guardar Planificación (Sesiones)
Guarda (sobrescribe) el listado de sesiones generado o editado en el grid.

- **Método:** `POST`
- **URL:** `/api/planificacion-semestral/{asignaturaId}/sesiones`
- **Payload:**
  ```json
  {
    "sesiones": [
      {
        "numeroGlobal": 1,
        "fecha": "10/02/26",
        "semana": 1,
        "periodoExamen": null,
        "conceptual": "Texto editable...",
        "procedimental": "Texto editable...",
        "actitudinal": "Texto editable...",
        "criteriosDesempeno": "...", 
        "instrumentosEvaluacion": "..."


### 3.4 Generar Planificación Automática
Genera 20 semanas de sesiones basado en el calendario y horario de la asignatura.
**Lógica de Negocio:**
- Detecta automáticamente las semanas de examen: **Sem 7-8 (1er Parcial)**, **Sem 14-15 (2do Parcial)**, **Sem 18-19 (Final)**, **Sem 20 (2da Instancia)**.
- En semanas de examen, no se crea contenido, solo se marca el periodo.

- **Método:** `POST`
- **URL:** `/api/planificacion-semestral/{asignaturaId}/generar`
- **Respuesta:** `{ "message": "Planificación generada", "total": 45 }`

### 3.5 Copiar Planificación (Unificación)
Permite importar el **contenido** (temas, metodologías, evaluaciones) de otra asignatura (ej: Grupo 1 -> Grupo 2), manteniendo las fechas del grupo destino.

- **Método:** `POST`
- **URL:** `/api/planificacion-semestral/{asignaturaId}/copiar`
- **Payload:**
  ```json
  {
    "source_asignatura_id": 5  // ID de la asignatura "Master" o fuente
  }
  ```


### 3.4 Generar Planificación Automática
Genera 20 semanas de sesiones basado en el calendario y horario de la asignatura.
**Lógica de Negocio:**
- Detecta automáticamente las semanas de examen: **Sem 7-8 (1er Parcial)**, **Sem 14-15 (2do Parcial)**, **Sem 18-19 (Final)**, **Sem 20 (2da Instancia)**.
- En semanas de examen, no se crea contenido, solo se marca el periodo.

- **Método:** `POST`
- **URL:** `/api/planificacion-semestral/{asignaturaId}/generar`
- **Respuesta:** `{ "message": "Planificación generada", "total": 45 }`

### 3.5 Copiar Planificación (Unificación)
Permite importar el **contenido** (temas, metodologías, evaluaciones) de otra asignatura (ej: Grupo 1 -> Grupo 2), manteniendo las fechas del grupo destino.

- **Método:** `POST`
- **URL:** `/api/planificacion-semestral/{asignaturaId}/copiar`
- **Payload:**
  ```json
  {
    "source_asignatura_id": 5  // ID de la asignatura "Master" o fuente
  }
  ```

---

## Módulo 4: Gestión de Bibliografía
**Estado de Verificación:** 🔄 COMPLETADO
**Archivo Frontend Referencia:** `Academico/src/pages/documentacion/AsignaturaEditPage.vue` (Sección Bibliografía)

Gestión maestra de libros y recursos de la asignatura.

### 4.1 CRUD Bibliografía
- **Listar:** `GET /api/bibliografias?asignatura_id={ID}`
- **Crear:** `POST /api/bibliografias`
- **Actualizar:** `PUT /api/bibliografias/{ID}`
- **Eliminar:** `DELETE /api/bibliografias/{ID}`

#### Payload (JSON)
Campos soportados según la vista "Nueva Bibliografía":

```json
{
  "asignatura_id": 1,
  "titulo": "Ingeniería del Software",
  "autor": "Roger Pressman",
  "editorial": "McGraw Hill",
  "edicion": "8va Edición",
  "anio": "2015",
  "tipo": "Basica",          // Select: Basica | Complementaria
  "isbn": "978-1234567890",  // Opcional (Nullable en BD)
  "paginas": "100-150"       // Opcional. Texto libre.
}
```

> **Nota:** El campo `paginas` es referencial. El detalle exacto se gestiona en el Plan de Clase. El `isbn` también es opcional.

---

## Módulo 5: Unidades y Logros de Aprendizaje
**Estado de Verificación:** 🔄 COMPLETADO
**Archivo Frontend Referencia:** `Academico/src/pages/documentacion/AsignaturaEditPage.vue` (Sección Unidades)

Gestión de la estructura curricular, logros esperados e indicadores.

### 5.0 Gestión Detalles de Unidad
Editar el "Elemento de Competencia" o descripción de la unidad.

- **URL:** `PUT /api/planificacion/unidades/{ID}`
- **Payload:** `{ "objetivo": "Analiza e interpreta los fundamentos..." }`

### 5.1 Gestión de Logros Esperados
Cada tema tiene múltiples logros. Ahora incluye el "Periodo" o parcial.

- **Crear Logro:** `POST /api/planificacion/temas/{ID}/logros`
- **Eliminar:** `DELETE /api/planificacion/logros/{ID}`

#### Payload (JSON)
```json
{
  "descripcion": "Define correctamente el concepto de ingeniería...",
  "tipo_logro": "Saber",          // Saber | Hacer | Ser
  "periodo": "1er Parcial"        // Nuevo Campo: Periodo de evaluación al que pertenece
}
```

### 5.2 Gestión de Indicadores
Cada logro tiene múltiples indicadores de evidencia.

- **Crear Indicador:** `POST /api/planificacion/logros/{ID}/indicadores`
- **Payload:** `{ "descripcion": "Explica con precisión..." }`
- **Eliminar:** `DELETE /api/planificacion/indicadores/{ID}`

---

## Módulo 6: Banco de Preguntas
**Estado de Verificación:** 🔄 COMPLETADO
**Archivo Frontend Referencia:** `Academico/src/pages/documentacion/BancoPreguntasPage.vue`

Gestión de preguntas vinculadas a un Logro Esperado específico. Soporta carga masiva.

### 6.1 CRUD Preguntas
- **Listar:** `GET /api/banco-preguntas?logro_id={ID}`
- **Crear:** `POST /api/banco-preguntas`
- **Eliminar:** `DELETE /api/banco-preguntas/{ID}`

#### Payload (JSON)
Coincide con el formulario "Nueva Pregunta".

```json
{
  "logro_esperado_id": 15,
  "enunciado": "¿Cuál es el objetivo principal...?",
  "tipo": "SELECCION_UNICA",   // SELECCION_UNICA | SELECCION_MULTIPLE | FALSO_VERDADERO
  "dificultad": "Media",       // Alta | Media | Baja
  "peso": 10,                  // Entero (Puntaje)
  "opciones": [
    { "id": "A", "text": "Opción 1" },
    { "id": "B", "text": "Opción 2" }
  ],
  "respuesta_correcta": "B"    // ID de la opción correcta (o Array ["A","B"] si es múltiple)
  // "created_by": 1           // (Automático) Se guarda el ID del usuario autenticado.
}
```

### 6.2 Importar desde Excel
(Igual que antes)

---

## Módulo 7: Evaluaciones y Patrón
**Estado de Verificación:** 🔄 EN PROCESO
**Archivo Frontend Referencia:** `Academico/src/pages/documentacion/EvaluacionesPage.vue`

Gestión de exámenes parciales, configuración, y generación de patrones de respuestas.

### 7.1 Crear Evaluación (y Generar Patrón Automaticamente)
Crea la configuración del examen y genera aleatoriamente una versión "Tipo A" seleccionando preguntas de los Logros del Parcial indicado.

- **Método:** `POST`
- **URL:** `/api/evaluaciones`
- **Payload (JSON):**
    ```json
    {
      "asignatura_id": 5,
      "nombre": "Examen 1er Parcial - Anatomía I",
      "parcial": "1er Parcial",      // Sirve para filtrar preguntas por Logro.
      "fecha_examen": "2026-03-15",
      "hora_inicio": "08:00",
      "duracion_minutos": 45,
      "mezclar_preguntas": true,
      "mezclar_opciones": true, // Para el render en linea, no afecta patrón impreso salvo el orden.
      
      // Distribución de Dificultad (Suma DEBE ser <= total preguntas disponibles)
      "distribucion_facil": 5,
      "distribucion_medio": 10,
      "distribucion_dificil": 5
    }
    ```
- **Respuesta:** Objeto `Evaluacion` con array `examenes_generados`.

### 7.2 Obtener Datos del Patrón (PDF)
Devuelve la estructura JSON lista para renderizar la vista "Patrón de Respuestas" (Círculos A-E).

- **Método:** `GET`
- **URL:** `/api/examenes-generados/{id}/patron`
- **Respuesta (Ejemplo):**
    ```json
    {
      "institucion": "UNIVERSIDAD TÉCNICA PRIVADA COSMOS",
      "asignatura": "Anatomía I",
      "examen_titulo": "1er Parcial",
      "tipo_examen": "TIPO A",
      "fecha": "2026-03-15",
      "preguntas": [
        { "numero": 1, "respuesta_correcta": "B" },
        { "numero": 2, "respuesta_correcta": "A" },
        // ... hasta la 20
      ]
    }
    ```
Carga masiva de preguntas para un logro.

- **Método:** `POST`
- **URL:** `/api/banco-preguntas/import`
- **Body (FormData):**
    - `file`: Archivo `.xlsx`
    - `logro_esperado_id`: ID del logro destino.

#### Mapeo de Sedes (Backend)
El sistema sincroniza automáticamente los códigos de la API con los IDs del Frontend:
- `CBA`, `CBB` -> **1** (Cochabamba)
- `LPZ` -> **2** (La Paz)
- `SCZ` -> **3** (Santa Cruz)
- (Resto de sedes mapeadas secuencialmente)
