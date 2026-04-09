# API de Exportación de Documentación Académica

Esta API permite exportar toda la documentación académica del sistema en formato JSON, incluyendo **Programa de Asignatura (PAC)**, **Programa Analítico** y **Plan de Clase** (planificaciones personales de todos los docentes). Es ideal para integración con otros sistemas, análisis de datos, generación de reportes o backup de documentación.

## 📋 Características

- **Exportación completa**: Incluye todos los componentes de la documentación académica
- **Planificaciones personales**: Contiene las planificaciones de **todos los docentes** por cada tema
- **Formato estándar**: JSON estructurado y fácil de consumir
- **Autenticación simple**: Token estático (sin necesidad de login de usuario)
- **Filtros flexibles**: Por carrera, sede o asignatura específica

## 🔐 Autenticación

Todos los endpoints usan un **token estático** configurado en la variable de entorno `PROGRAMAS_API_TOKEN` (valor por defecto: `unitepc-programas-2026`).

### Métodos de autenticación:

1. **Bearer Token** (recomendado):
   ```
   Authorization: Bearer unitepc-programas-2026
   ```

2. **Query Parameter**:
   ```
   ?token=unitepc-programas-2026
   ```

Si el token es inválido o no se proporciona, la API retorna `401 Unauthorized`.

## 📊 Endpoints Disponibles

### 1. Exportar Documentación por Carrera
Obtiene todas las asignaturas de una carrera específica con toda su documentación.

```
GET /api/export/documentacion-carrera
```

#### Parámetros:

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `carrera_id` | integer | **Sí** | ID de la carrera |
| `sede_id` | integer | No | ID de la sede (filtra por sede) |
| `token` | string | **Sí** | Token de autenticación (si no se usa Bearer) |

#### Ejemplo de cURL:

```bash
# Con Bearer Token
curl -H "Authorization: Bearer unitepc-programas-2026" \
  "http://tu-dominio.com/api/export/documentacion-carrera?carrera_id=5"

# Con query parameter
curl "http://tu-dominio.com/api/export/documentacion-carrera?carrera_id=5&sede_id=1&token=unitepc-programas-2026"
```

#### Ejemplo en JavaScript (fetch):

```javascript
async function exportarCarrera(carreraId, sedeId = null) {
  const params = new URLSearchParams({
    carrera_id: carreraId,
    ...(sedeId && { sede_id: sedeId })
  });

  const response = await fetch(`/api/export/documentacion-carrera?${params}`, {
    headers: {
      'Authorization': 'Bearer unitepc-programas-2026'
    }
  });

  if (!response.ok) throw new Error('Error en la solicitud');
  return await response.json();
}
```

### 2. Exportar Documentación por Asignatura
Obtiene una asignatura específica por su código con toda su documentación.

```
GET /api/export/documentacion-asignatura
```

#### Parámetros:

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `codigo` | string | **Sí** | Código de la asignatura (ej: "MAT101") |
| `sede_id` | integer | No | ID de la sede (filtra por sede) |
| `token` | string | **Sí** | Token de autenticación (si no se usa Bearer) |

#### Ejemplo de cURL:

```bash
curl -H "Authorization: Bearer unitepc-programas-2026" \
  "http://tu-dominio.com/api/export/documentacion-asignatura?codigo=MAT101&sede_id=1"
```

### 3. Exportación Básica (Endpoint Existente)
Endpoint pre-existente que no incluye planificaciones personales:

```
GET /api/programas-analiticos
```

Parámetros: `sede_id`, `carrera_id`, `semestre`, `search`, `token`

> **Nota**: Este endpoint solo incluye la estructura básica del programa analítico, sin las planificaciones personales de los docentes.

## 📁 Estructura de la Respuesta

### Respuesta General
```json
{
  "total": 15,
  "carrera_id": 5,
  "sede_id": 1,
  "data": [
    {
      "id": 123,
      "codigo": "MAT101",
      "nombre": "Matemáticas I",
      "creditos": 4,
      "semestre": 1,
      "carrera": {
        "id": 5,
        "nombre": "Ingeniería de Sistemas",
        "sede": "Sede Central"
      },
      
      // Programa de Asignatura (PAC)
      "descripcion": "...",
      "justificacion": "...",
      "proposito_general": "...",
      "competencia_asignatura": "...",
      "competencia_global_especifica": "...",
      "elementos_competencia": "...",
      "contenido_minimo": "...",
      "metodologia_general": "...",
      "sistema_evaluacion": "...",
      "requisitos": "...",
      
      // Estructura del Programa Analítico
      "unidades": [
        {
          "id": 456,
          "numero": 1,
          "titulo": "Introducción a las Matemáticas",
          "elemento_competencia": "...",
          "temas": [
            {
              "id": 789,
              "titulo": "Conceptos Básicos",
              "orden": 1,
              "resultado_aprendizaje": "...",
              "contenido_items": [...],
              "contenido_conceptual": [...],
              "contenido_procedimental": [...],
              "contenido_actitudinal": [...],
              "estrategias_metodologicas": "...",
              "estrategias_aprendizaje": "...",
              "estrategias_recursos": [...],
              "evaluacion_formativa": {...},
              "evaluacion_sumativa": {...},
              "horas_teoricas": 2,
              "horas_practicas": 2,
              
              // Logros Esperados e Indicadores
              "logros_esperados": [
                {
                  "id": 890,
                  "descripcion": "Comprende los conceptos básicos...",
                  "tipo_logro": "CONOCIMIENTO",
                  "indicadores": [
                    {
                      "id": 891,
                      "descripcion": "Define correctamente los términos..."
                    }
                  ]
                }
              ],
              
              // Bibliografía por tema
              "bibliografias": [
                {
                  "id": 910,
                  "titulo": "Matemáticas Básicas",
                  "autor": "Autor Principal"
                }
              ],
              
              // PLANIFICACIONES PERSONALES (NUEVO)
              "planificaciones_personales": [
                {
                  "docente_id": 55,
                  "docente_nombre": "Juan Pérez",
                  "user_id": 100,
                  "estrategias_metodologicas": "Exposición magistral...",
                  "estrategias_aprendizaje": "Ejercicios prácticos...",
                  "estrategias_recursos": ["Pizarra", "Proyector"],
                  "evaluacion_formativa": {
                    "actividades": ["Quiz semanal"],
                    "instrumentos": ["Prueba escrita"],
                    "evidencias": ["Carpeta de trabajos"]
                  },
                  "evaluacion_sumativa": {
                    "actividades": ["Examen parcial"],
                    "instrumentos": ["Prueba objetiva"],
                    "evidencias": ["Portafolio"]
                  },
                  "secuencia_didactica": [
                    {
                      "momento": "Introducción",
                      "descripcion": "Presentación del tema...",
                      "duracion_minutos": 15
                    }
                  ]
                }
              ]
            }
          ]
        }
      ],
      
      // Bibliografía General de la Asignatura
      "bibliografias": [
        {
          "id": 911,
          "titulo": "Matemáticas Avanzadas",
          "autor": "Otro Autor",
          "editorial": "Editorial XYZ",
          "anio": 2023,
          "tipo": "PRINCIPAL"
        }
      ],
      
      // Docentes asignados a esta materia
      "docentes": [
        {
          "id": 55,
          "nombre_completo": "Juan Pérez",
          "user_id": 100,
          "email": "juan.perez@unitepc.edu"
        }
      ],
      
      // Estadísticas de progreso
      "progreso": {
        "total": 85,
        "programa_asignatura": 100,
        "programa_analitico": 90,
        "plan_clase": 65,
        "actualizado_en": "2026-04-09 14:30:00"
      }
    }
  ]
}
```

## 💻 Ejemplos de Consumo

### 1. Exportar y Guardar como Archivo JSON (Node.js)
```javascript
const fs = require('fs');
const fetch = require('node-fetch');

async function exportarYGuardar(carreraId, sedeId, nombreArchivo) {
  const params = new URLSearchParams({
    carrera_id: carreraId,
    sede_id: sedeId
  });

  const response = await fetch(`http://tu-dominio.com/api/export/documentacion-carrera?${params}`, {
    headers: {
      'Authorization': 'Bearer unitepc-programas-2026'
    }
  });

  const data = await response.json();
  fs.writeFileSync(nombreArchivo, JSON.stringify(data, null, 2));
  console.log(`Documentación exportada a ${nombreArchivo}`);
}

exportarYGuardar(5, 1, 'documentacion_carrera_5.json');
```

### 2. Consumir en Python
```python
import requests
import json

def exportar_documentacion(codigo_asignatura):
    url = "http://tu-dominio.com/api/export/documentacion-asignatura"
    params = {
        "codigo": codigo_asignatura,
        "token": "unitepc-programas-2026"
    }
    
    response = requests.get(url, params=params)
    response.raise_for_status()
    
    data = response.json()
    
    # Guardar en archivo
    with open(f'documentacion_{codigo_asignatura}.json', 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    
    # Procesar planificaciones
    for unidad in data.get('unidades', []):
        for tema in unidad.get('temas', []):
            for planificacion in tema.get('planificaciones_personales', []):
                print(f"Docente: {planificacion['docente_nombre']}")
                print(f"Estrategias: {planificacion['estrategias_metodologicas'][:100]}...")
    
    return data
```

### 3. Generar Reporte HTML/PDF
```javascript
// Ejemplo básico para generar un reporte HTML
async function generarReporteHTML(carreraId) {
  const data = await exportarCarrera(carreraId);
  
  let html = `
    <!DOCTYPE html>
    <html>
    <head>
      <title>Documentación Académica - Carrera ${carreraId}</title>
      <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .asignatura { border: 1px solid #ccc; margin: 10px 0; padding: 15px; }
        .tema { margin-left: 20px; }
        .docente { background-color: #f5f5f5; padding: 5px; margin: 5px 0; }
      </style>
    </head>
    <body>
      <h1>Documentación Académica</h1>
      <p>Total asignaturas: ${data.total}</p>
  `;
  
  data.data.forEach(asignatura => {
    html += `
      <div class="asignatura">
        <h2>${asignatura.codigo} - ${asignatura.nombre}</h2>
        <p><strong>Docentes:</strong> ${asignatura.docentes.map(d => d.nombre_completo).join(', ')}</p>
        
        <h3>Planificaciones por Tema:</h3>
        ${asignatura.unidades.map(unidad => `
          <h4>Unidad ${unidad.numero}: ${unidad.titulo}</h4>
          ${unidad.temas.map(tema => `
            <div class="tema">
              <h5>Tema ${tema.orden}: ${tema.titulo}</h5>
              ${tema.planificaciones_personales.map(plan => `
                <div class="docente">
                  <strong>${plan.docente_nombre}:</strong><br>
                  ${plan.estrategias_metodologicas.substring(0, 150)}...
                </div>
              `).join('')}
            </div>
          `).join('')}
        `).join('')}
      </div>
    `;
  });
  
  html += `</body></html>`;
  
  // Guardar HTML
  const blob = new Blob([html], { type: 'text/html' });
  const url = URL.createObjectURL(blob);
  window.open(url);
}
```

### 4. Consumo desde Postman
1. Crear nueva solicitud GET
2. URL: `http://tu-dominio.com/api/export/documentacion-carrera`
3. En la pestaña "Params", agregar:
   - `carrera_id`: [ID de la carrera]
   - `sede_id`: [Opcional]
4. En la pestaña "Headers", agregar:
   - `Authorization`: `Bearer unitepc-programas-2026`
5. Hacer clic en "Send"

## 🖨️ Cómo Imprimir/Exportar la Documentación

### Opción 1: Exportar a JSON y Convertir a PDF
1. Consumir la API y guardar como JSON
2. Usar una herramienta como **jsPDF** o **Puppeteer** para generar PDF
3. Incluir todas las planificaciones en el reporte

### Opción 2: Generar Reporte Directo desde el Sistema
El sistema ya incluye funcionalidad de impresión en:
- `GET /api/programas-analiticos` (para programas analíticos básicos)
- La interfaz web del sistema tiene botones de exportación e impresión

### Opción 3: Script Personalizado de Exportación
```bash
#!/bin/bash
# Script para exportar todas las carreras de una sede

TOKEN="unitepc-programas-2026"
BASE_URL="http://tu-dominio.com/api"
SEDE_ID=1

# Obtener lista de carreras (asumiendo endpoint /api/carreras)
CARRERAS=$(curl -s -H "Authorization: Bearer $TOKEN" "$BASE_URL/carreras?sede_id=$SEDE_ID")

echo $CARRERAS | jq -r '.data[] | "\(.id) \(.nombre)"' | while read id nombre; do
  echo "Exportando carrera: $nombre (ID: $id)"
  
  curl -H "Authorization: Bearer $TOKEN" \
    "$BASE_URL/export/documentacion-carrera?carrera_id=$id&sede_id=$SEDE_ID" \
    > "carrera_${id}_${nombre// /_}.json"
  
  echo "  -> Exportada a carrera_${id}_${nombre// /_}.json"
done
```

## ⚠️ Consideraciones y Límites

### Rendimiento
- Las respuestas pueden ser grandes (especialmente con muchas asignaturas)
- Considerar paginación para carreras con muchas materias (actualmente no implementada)
- El tiempo de respuesta depende del volumen de datos

### Seguridad
- El token estático debe ser configurado en producción
- Recomendado cambiar el token por defecto en `.env`:
  ```env
  PROGRAMAS_API_TOKEN=tu-token-seguro-aqui
  ```
- Los endpoints son públicos pero requieren el token

### Estructura de Datos
- Las planificaciones personales pueden estar vacías si los docentes no las han completado
- Algunos campos son arrays u objetos JSON (verificar con `json_decode` en PHP)
- Los campos de horas y créditos son numéricos

## 🔧 Configuración en Producción

### 1. Configurar Token Seguro
En el archivo `.env` del proyecto Laravel:
```env
PROGRAMAS_API_TOKEN=generar-token-unico-y-seguro-aqui
```

### 2. Configurar CORS (si se consume desde otro dominio)
En `config/cors.php`:
```php
'paths' => ['api/*'],
'allowed_origins' => ['https://tudominioexterno.com'],
'allowed_methods' => ['GET'],
```

### 3. Configurar Rate Limiting (opcional)
En `app/Http/Kernel.php` o usando middleware de Laravel.

## ❓ Preguntas Frecuentes

### ¿Puedo filtrar por semestre?
Actualmente no directamente, pero puedes filtrar por carrera y luego procesar localmente por el campo `semestre` en cada asignatura.

### ¿Cómo obtengo los IDs de carrera y sede?
- Usar endpoint `/api/carreras` (autenticado con Sanctum)
- O consultar directamente la base de datos

### ¿Qué pasa si una asignatura tiene múltiples docentes?
El campo `planificaciones_personales` contiene las planificaciones de **cada docente** por tema, y el campo `docentes` lista todos los docentes asignados.

### ¿Se puede exportar en otros formatos (Excel, CSV)?
Actualmente solo JSON, pero puedes procesar el JSON para convertirlo a otros formatos.

## 📞 Soporte y Contacto

Para problemas con la API:
1. Verificar que el token sea correcto
2. Confirmar que los IDs de carrera/sede existan
3. Revisar los logs de Laravel en `storage/logs/laravel.log`

Para nuevas funcionalidades o reporte de errores, contactar al equipo de desarrollo.

---

**Última actualización**: Abril 2026  
**Versión API**: 1.0  
**Base URL**: `http://[tu-dominio]/api/export/`