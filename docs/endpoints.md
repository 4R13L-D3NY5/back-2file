# Documentacion Tecnica de Endpoints (Incremental)

Este documento servira como referencia viva y verificada de los endpoints del sistema. Se actualizara modulo por modulo conforme se verifique contra el Frontend desplegado.

---

## Modulo 1: Asignaturas y Planificacion
**Estado de Verificacion:** [COMPLETADO] COMPLETADO PARCIAL (Detalles + Temas)
**Archivo Frontend Referencia:** `Academico/src/pages/documentacion/AsignaturaEditPage.vue`

### 1.1 Detalles de Asignatura (Informacion General)
Permite editar la informacion base, incluyendo campos extendidos que no vienen de la API.

- **Metodo:** `PUT`
- **URL:** `/api/asignaturas/{ID}`
- **Controlador:** `AsignaturaController@update`

#### Payload (JSON Request)
El frontend envia estos campos. El backend los mapea internamente a las columnas correctas.

```json
{
  "sigla": "SIS-301",
  "descripcion": "Introduccion a...",
  "objetivo_general": "Desarrollar competencias...", // Se guarda en 'proposito_general'
  "justificacion": "La ingenieria...",
  "saberes_previos": "Programacion I...",            // Se guarda en 'requisitos'
  "contenido_minimo": "Ciclo de vida...",
  "metodologia_ensenanza": "Clases magistrales...",  // Se guarda en 'metodologia_general'
  "criterios_evaluacion": "Examenes...",             // Se guarda en 'sistema_evaluacion'
  "horas_teoricas": 40,
  "horas_practicas": 20,
  "horas_laboratorio": 20
}
```

### 1.2 Editar Tema Completo (Unificado)
Este endpoint permite guardar todos los datos del formulario de un tema: contenidos, estrategias, evaluaciones y bibliografia.

- **Metodo:** `PUT`
- **URL:** `/api/planificacion/temas/{ID}/contenido`
- **Controlador:** `PlanificacionController@updateTema`

#### Payload (JSON Request)
Estructura exacta que envia el Frontend (`formTema`):

```json
{
  "contenidos": {
    "conceptual": ["Concepto 1", "Concepto 2"],    // Array de Strings
    "procedimental": ["Procedimiento 1"],           // Array de Strings
    "actitudinal": ["Actitud 1"]                    // Array de Strings
  },
  "estrategias": {
    "metodologicas": "Explicacion magistral...",    // Texto (TextArea)
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
      "instrumentos": ["Rubrica"],
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

- **Metodo:** `GET`
- **URL:** `/api/planificacion/temas/{ID}/full`
- **Respuesta:** Mismo formato JSON que el Payload de envio, mas IDs y timestamps.

---

### 1.3 Secuencia Didactica
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

## Modulo 2: Gestion de Carreras (Dashboard)
**Estado de Verificacion:** [COMPLETADO] COMPLETADO
**Archivo Frontend Referencia:** `Academico/src/pages/admin/CarrerasPage.vue`

Este modulo funciona en modo **Sincronizacion Hibrida**. Al consultar la lista, el backend recupera datos frescos de la API University, actualiza la base de datos local y devuelve los registros locales enriquecidos con contadores y estados.

### 2.1 Listar Carreras (Con Filtros y Estadisticas)
Retorna la lista de carreras activas, incluyendo contadores de asignaturas y docentes.

- **Metodo:** `GET`
- **URL:** `/api/carreras`
- **Parametros (Query):**
    - `sede_id` (Opcional): ID de la sede para filtrar (Ej: `1` para Cochabamba).
- **Controlador:** `CarreraController@index`

#### Respuesta (JSON)
Formato exacto esperado por el Store `carreras.js`.

```json
[
  {
    "id": 1,
    "nombre": "Ingenieria de Sistemas",
    "codigo": "SIS",            // Usado en las tarjetas
    "sede_id": 1,              // Mapeado a partir del branchCode (CBA -> 1)
    "activo": true,
    "asignaturas_count": 45,   // Contador real de base de datos local
    "docentes_count": 12       // Contador real (basado en asignaturas vinculadas)
  }
]
```

---

## Modulo 3: Planificacion Semestral (Nuevo)
**Estado de Verificacion:** [EN_PROGRESO] EN PROGRESO (Backend Listo)
**Archivo Frontend Referencia:** `Academico/src/pages/documentacion/PlanificacionPage.vue`

Este modulo maneja la distribucion de temas en el tiempo (Generador Automatico).

### 3.1 Obtener Estado Completo
Recupera la configuracion de calendario, los horarios semanales y la lista de sesiones generadas (si existen).

- **Metodo:** `GET`
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

### 3.2 Guardar Configuracion y Horarios
Guarda las fechas del semestre y los horarios de clase (Lunes, Martes...).

- **Metodo:** `POST`
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

### 3.3 Guardar Planificacion (Sesiones)
Guarda (sobrescribe) el listado de sesiones generado o editado en el grid.

- **Metodo:** `POST`
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


### 3.4 Generar Planificacion Automatica
Genera 20 semanas de sesiones basado en el calendario y horario de la asignatura.
**Logica de Negocio:**
- Detecta automaticamente las semanas de examen: **Sem 7-8 (1er Parcial)**, **Sem 14-15 (2do Parcial)**, **Sem 18-19 (Final)**, **Sem 20 (2da Instancia)**.
- En semanas de examen, no se crea contenido, solo se marca el periodo.

- **Metodo:** `POST`
- **URL:** `/api/planificacion-semestral/{asignaturaId}/generar`
- **Respuesta:** `{ "message": "Planificacion generada", "total": 45 }`

### 3.5 Copiar Planificacion (Unificacion)
Permite importar el **contenido** (temas, metodologias, evaluaciones) de otra asignatura (ej: Grupo 1 -> Grupo 2), manteniendo las fechas del grupo destino.

- **Metodo:** `POST`
- **URL:** `/api/planificacion-semestral/{asignaturaId}/copiar`
- **Payload:**
  ```json
  {
    "source_asignatura_id": 5  // ID de la asignatura "Master" o fuente
  }
  ```


### 3.4 Generar Planificacion Automatica
Genera 20 semanas de sesiones basado en el calendario y horario de la asignatura.
**Logica de Negocio:**
- Detecta automaticamente las semanas de examen: **Sem 7-8 (1er Parcial)**, **Sem 14-15 (2do Parcial)**, **Sem 18-19 (Final)**, **Sem 20 (2da Instancia)**.
- En semanas de examen, no se crea contenido, solo se marca el periodo.

- **Metodo:** `POST`
- **URL:** `/api/planificacion-semestral/{asignaturaId}/generar`
- **Respuesta:** `{ "message": "Planificacion generada", "total": 45 }`

### 3.5 Copiar Planificacion (Unificacion)
Permite importar el **contenido** (temas, metodologias, evaluaciones) de otra asignatura (ej: Grupo 1 -> Grupo 2), manteniendo las fechas del grupo destino.

- **Metodo:** `POST`
- **URL:** `/api/planificacion-semestral/{asignaturaId}/copiar`
- **Payload:**
  ```json
  {
    "source_asignatura_id": 5  // ID de la asignatura "Master" o fuente
  }
  ```

---

## Modulo 4: Gestion de Bibliografia
**Estado de Verificacion:** [EN_PROGRESO] COMPLETADO
**Archivo Frontend Referencia:** `Academico/src/pages/documentacion/AsignaturaEditPage.vue` (Seccion Bibliografia)

Gestion maestra de libros y recursos de la asignatura.

### 4.1 CRUD Bibliografia
- **Listar:** `GET /api/bibliografias?asignatura_id={ID}`
- **Crear:** `POST /api/bibliografias`
- **Actualizar:** `PUT /api/bibliografias/{ID}`
- **Eliminar:** `DELETE /api/bibliografias/{ID}`

#### Payload (JSON)
Campos soportados segun la vista "Nueva Bibliografia":

```json
{
  "asignatura_id": 1,
  "titulo": "Ingenieria del Software",
  "autor": "Roger Pressman",
  "editorial": "McGraw Hill",
  "edicion": "8va Edicion",
  "anio": "2015",
  "tipo": "Basica",          // Select: Basica | Complementaria
  "isbn": "978-1234567890",  // Opcional (Nullable en BD)
  "paginas": "100-150"       // Opcional. Texto libre.
}
```

> **Nota:** El campo `paginas` es referencial. El detalle exacto se gestiona en el Plan de Clase. El `isbn` tambien es opcional.

---

## Modulo 5: Unidades y Logros de Aprendizaje
**Estado de Verificacion:** [EN_PROGRESO] COMPLETADO
**Archivo Frontend Referencia:** `Academico/src/pages/documentacion/AsignaturaEditPage.vue` (Seccion Unidades)

Gestion de la estructura curricular, logros esperados e indicadores.

### 5.0 Gestion Detalles de Unidad
Editar el "Elemento de Competencia" o descripcion de la unidad.

- **URL:** `PUT /api/planificacion/unidades/{ID}`
- **Payload:** `{ "objetivo": "Analiza e interpreta los fundamentos..." }`

### 5.1 Gestion de Logros Esperados
Cada tema tiene multiples logros. Ahora incluye el "Periodo" o parcial.

- **Crear Logro:** `POST /api/planificacion/temas/{ID}/logros`
- **Eliminar:** `DELETE /api/planificacion/logros/{ID}`

#### Payload (JSON)
```json
{
  "descripcion": "Define correctamente el concepto de ingenieria...",
  "tipo_logro": "Saber",          // Saber | Hacer | Ser
  "periodo": "1er Parcial"        // Nuevo Campo: Periodo de evaluacion al que pertenece
}
```

### 5.2 Gestion de Indicadores
Cada logro tiene multiples indicadores de evidencia.

- **Crear Indicador:** `POST /api/planificacion/logros/{ID}/indicadores`
- **Payload:** `{ "descripcion": "Explica con precision..." }`
- **Eliminar:** `DELETE /api/planificacion/indicadores/{ID}`

---

## Modulo 6: Banco de Preguntas
**Estado de Verificacion:** [EN_PROGRESO] COMPLETADO
**Archivo Frontend Referencia:** `Academico/src/pages/documentacion/BancoPreguntasPage.vue`

Gestion de preguntas vinculadas a un Logro Esperado especifico. Soporta carga masiva.

### 6.1 CRUD Preguntas
- **Listar:** `GET /api/banco-preguntas?logro_id={ID}`
- **Crear:** `POST /api/banco-preguntas`
- **Eliminar:** `DELETE /api/banco-preguntas/{ID}`

#### Payload (JSON)
Coincide con el formulario "Nueva Pregunta".

```json
{
  "logro_esperado_id": 15,
  "enunciado": "¿Cual es el objetivo principal...?",
  "tipo": "SELECCION_UNICA",   // SELECCION_UNICA | SELECCION_MULTIPLE | FALSO_VERDADERO
  "dificultad": "Media",       // Alta | Media | Baja
  "peso": 10,                  // Entero (Puntaje)
  "opciones": [
    { "id": "A", "text": "Opcion 1" },
    { "id": "B", "text": "Opcion 2" }
  ],
  "respuesta_correcta": "B"    // ID de la opcion correcta (o Array ["A","B"] si es multiple)
  // "created_by": 1           // (Automatico) Se guarda el ID del usuario autenticado.
}
```

### 6.2 Importar desde Excel
(Igual que antes)

---

## Modulo 7: Evaluaciones y Patron
**Estado de Verificacion:** [EN_PROGRESO] EN PROCESO
**Archivo Frontend Referencia:** `Academico/src/pages/documentacion/EvaluacionesPage.vue`

Gestion de examenes parciales, configuracion, y generacion de patrones de respuestas.

### 7.1 Crear Evaluacion (y Generar Patron Automaticamente)
Crea la configuracion del examen y genera aleatoriamente una version "Tipo A" seleccionando preguntas de los Logros del Parcial indicado.

- **Metodo:** `POST`
- **URL:** `/api/evaluaciones`
- **Payload (JSON):**
    ```json
    {
      "asignatura_id": 5,
      "nombre": "Examen 1er Parcial - Anatomia I",
      "parcial": "1er Parcial",      // Sirve para filtrar preguntas por Logro.
      "fecha_examen": "2026-03-15",
      "hora_inicio": "08:00",
      "duracion_minutos": 45,
      "mezclar_preguntas": true,
      "mezclar_opciones": true, // Para el render en linea, no afecta patron impreso salvo el orden.
      
      // Distribucion de Dificultad (Suma DEBE ser <= total preguntas disponibles)
      "distribucion_facil": 5,
      "distribucion_medio": 10,
      "distribucion_dificil": 5
    }
    ```
- **Respuesta:** Objeto `Evaluacion` con array `examenes_generados`.

### 7.2 Obtener Datos del Patron (PDF)
Devuelve la estructura JSON lista para renderizar la vista "Patron de Respuestas" (Circulos A-E).

- **Metodo:** `GET`
- **URL:** `/api/examenes-generados/{id}/patron`
- **Respuesta (Ejemplo):**
    ```json
    {
      "institucion": "UNIVERSIDAD TECNICA PRIVADA COSMOS",
      "asignatura": "Anatomia I",
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

- **Metodo:** `POST`
- **URL:** `/api/banco-preguntas/import`
- **Body (FormData):**
    - `file`: Archivo `.xlsx`
    - `logro_esperado_id`: ID del logro destino.

#### Mapeo de Sedes (Backend)
El sistema sincroniza automaticamente los codigos de la API con los IDs del Frontend:
- `CBA`, `CBB` -> **1** (Cochabamba)
- `LPZ` -> **2** (La Paz)
- `SCZ` -> **3** (Santa Cruz)
- (Resto de sedes mapeadas secuencialmente)
