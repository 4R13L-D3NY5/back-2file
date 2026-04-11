# API de Exportación de JSON por Asignatura (Autenticada)

Esta API permite a **Directores de Carrera** y **Super Administradores** descargar la documentación completa de una asignatura en formato JSON directamente desde la interfaz web. A diferencia de la API pública de exportación, este endpoint requiere autenticación y valida los permisos del usuario.

## 📋 Características

- **Acceso restringido**: Solo usuarios con roles `DIRECTOR_CARRERA` o `SUPER_ADMIN`
- **JSON completo**: Incluye toda la documentación (PAC, programa analítico, planificaciones personales de todos los docentes)
- **Descarga directa**: Archivo JSON descargable con nombre automático
- **Integración frontend**: Botón integrado en la vista "Plan de Estudios"

## 🔐 Autenticación y Permisos

El endpoint utiliza la autenticación estándar del sistema (Laravel Sanctum) y middleware de roles:

- **Middleware**: `auth:sanctum` + `role:DIRECTOR_CARRERA,SUPER_ADMIN`
- **Token**: Bearer Token de sesión del usuario
- **Validación**: El usuario debe estar autenticado y tener uno de los roles permitidos

## 📊 Endpoint

### Descargar JSON de Asignatura
Obtiene la documentación completa de una asignatura específica en formato JSON descargable.

```
GET /api/asignaturas/{id}/export-json
```

#### Parámetros:
| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `id` | integer | **Sí** | ID de la asignatura |

#### Headers:
```
Authorization: Bearer {token_de_sesión}
Accept: application/json
```

#### Respuesta Exitosa (200):
- **Content-Type**: `application/json`
- **Content-Disposition**: `attachment; filename="asignatura_{codigo}_{fecha}.json"`
- **Body**: JSON completo con la estructura detallada abajo

#### Códigos de Error:
- `401 Unauthorized`: Usuario no autenticado
- `403 Forbidden`: Usuario no tiene permisos (rol incorrecto)
- `404 Not found`: Asignatura no encontrada

## 📁 Estructura de la Respuesta

La respuesta JSON tiene la misma estructura que el endpoint público `/api/export/documentacion-asignatura`, incluyendo:

```json
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
  
  // Programa Analítico con planificaciones personales
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
          "logros_esperados": [...],
          
          // Bibliografía por tema
          "bibliografias": [...],
          
          // PLANIFICACIONES PERSONALES (de todos los docentes)
          "planificaciones_personales": [
            {
              "docente_id": 55,
              "docente_nombre": "Juan Pérez",
              "user_id": 100,
              "estrategias_metodologicas": "Exposición magistral...",
              "estrategias_aprendizaje": "Ejercicios prácticos...",
              "estrategias_recursos": ["Pizarra", "Proyector"],
              "evaluacion_formativa": {...},
              "evaluacion_sumativa": {...},
              "secuencia_didactica": [...]
            }
          ]
        }
      ]
    }
  ],
  
  // Bibliografía General
  "bibliografias": [...],
  
  // Docentes asignados
  "docentes": [...],
  
  // Estadísticas de progreso
  "progreso": {...}
}
```

## 💻 Uso desde el Frontend

### 1. Servicio JavaScript
El servicio `asignaturaService` incluye el método `exportAsignaturaJson`:

```javascript
// Academico/src/services/asignaturaService.js
exportAsignaturaJson(id) {
  return api.get(`/asignaturas/${id}/export-json`, {
    responseType: 'blob'
  })
}
```

### 2. Componente Vue.js
En `AsignaturasDirectorPage.vue` se añadió un botón en la columna "Acciones":

```vue
<q-btn
  flat
  round
  dense
  icon="download"
  color="positive"
  size="sm"
  @click="descargarJson(props.row)"
>
  <q-tooltip>Descargar JSON completo</q-tooltip>
</q-btn>
```

### 3. Función de Descarga
```javascript
async function descargarJson(row) {
  try {
    const response = await asignaturaService.exportAsignaturaJson(row.id)
    const blob = new Blob([response.data], { type: 'application/json' })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `asignatura_${row.codigo}_${fecha}.json`
    link.click()
    window.URL.revokeObjectURL(url)
  } catch (error) {
    // Manejo de errores
  }
}
```

## 🔧 Configuración

### 1. Backend (Laravel)
El endpoint está definido en `routes/api.php`:

```php
Route::get('/asignaturas/{id}/export-json', [AsignaturaController::class, 'exportJson'])
    ->middleware(['auth:sanctum', 'role:DIRECTOR_CARRERA,SUPER_ADMIN']);
```

### 2. Controlador
El método `exportJson` en `AsignaturaController.php` reutiliza la lógica de `documentacionAsignatura` pero:
- Valida autenticación mediante middleware
- Agrega headers para descarga de archivo
- Genera nombre de archivo automático

## ⚠️ Consideraciones

### Rendimiento
- El JSON puede ser grande si la asignatura tiene muchas unidades, temas y planificaciones
- Se recomienda usar compresión Gzip en el servidor
- El tiempo de respuesta depende del volumen de datos

### Seguridad
- Solo usuarios autorizados pueden acceder
- Los directores de carrera solo pueden descargar asignaturas de sus carreras asignadas (validación futura)
- Los tokens de sesión tienen expiración

### Compatibilidad
- Formato JSON compatible con cualquier cliente HTTP
- Encoding UTF-8 para soportar caracteres especiales
- Estructura idéntica a la API pública para consistencia

## 🖥️ Ejemplos de Consumo

### 1. cURL (con token de sesión)
```bash
curl -H "Authorization: Bearer {session_token}" \
  "https://tu-dominio.com/api/asignaturas/123/export-json" \
  --output "asignatura_MAT101_2026-04-10.json"
```

### 2. JavaScript (Fetch API)
```javascript
async function downloadAsignaturaJson(asignaturaId, token) {
  const response = await fetch(`/api/asignaturas/${asignaturaId}/export-json`, {
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });
  
  if (!response.ok) throw new Error('Error en la descarga');
  
  const blob = await response.blob();
  const url = window.URL.createObjectURL(blob);
  // ... proceder con descarga
}
```

### 3. Postman
1. Configurar autenticación Bearer Token con token de sesión
2. Método: GET
3. URL: `{{base_url}}/api/asignaturas/123/export-json`
4. La respuesta descargará automáticamente el archivo JSON

## 🔄 Diferencias con la API Pública

| Característica | API Pública (`/export/documentacion-asignatura`) | API Autenticada (`/asignaturas/{id}/export-json`) |
|----------------|--------------------------------------------------|---------------------------------------------------|
| **Autenticación** | Token estático (`PROGRAMAS_API_TOKEN`) | Sesión de usuario (Sanctum) |
| **Permisos** | Cualquiera con el token | Solo DIRECTOR_CARRERA y SUPER_ADMIN |
| **Uso** | Integración externa, scripts | Interfaz web del sistema |
| **Headers** | `Accept: application/json` | `Authorization: Bearer {token}` |
| **Respuesta** | JSON en body | JSON con headers de descarga |

## 📞 Soporte y Solución de Problemas

### Problemas Comunes

1. **Error 403 Forbidden**
   - Verificar que el usuario tenga rol `DIRECTOR_CARRERA` o `SUPER_ADMIN`
   - Confirmar que el token de sesión sea válido

2. **Error 404 Not Found**
   - Verificar que el ID de asignatura exista
   - Confirmar que la asignatura no esté eliminada

3. **JSON muy grande**
   - Considerar implementar paginación si es necesario
   - Verificar límites de memoria del servidor

4. **Problemas de descarga en frontend**
   - Verificar que el servicio use `responseType: 'blob'`
   - Confirmar que el navegador no bloquee ventanas emergentes

### Registros y Debug
- Revisar logs de Laravel: `storage/logs/laravel.log`
- Verificar middlewares de autenticación y roles
- Comprobar eager loading de relaciones

### Endpoints Adicionales

Además del endpoint por ID, se ha implementado un endpoint por código de asignatura:

#### Descargar JSON por Código de Asignatura
```
GET /api/asignaturas/codigo/{codigo}/export-json
```

**Parámetros:**
| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `codigo` | string | **Sí** | Código de la asignatura (ej: "MAT101") |

**Notas:**
- Misma autenticación y permisos que el endpoint por ID
- Si existen múltiples asignaturas con el mismo código, se devuelve la primera encontrada
- Recomendado usar el endpoint por ID cuando se conoce el identificador único

**Ejemplo de cURL:**
```bash
curl -H "Authorization: Bearer {session_token}" \
  "https://tu-dominio.com/api/asignaturas/codigo/MAT101/export-json" \
  --output "asignatura_MAT101_2026-04-10.json"
```

**Servicio Frontend:**
```javascript
exportAsignaturaJsonByCode(codigo) {
  return api.get(`/asignaturas/codigo/${codigo}/export-json`, {
    responseType: 'blob'
  })
}
```

---

**Última actualización**: Abril 2026  
**Versión**: 1.1  
**Endpoints**: 
- `GET /api/asignaturas/{id}/export-json` (por ID)
- `GET /api/asignaturas/codigo/{codigo}/export-json` (por código)  
**Roles permitidos**: `DIRECTOR_CARRERA`, `SUPER_ADMIN`