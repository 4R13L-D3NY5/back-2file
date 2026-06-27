# 10. CodeGraph — Herramientas de Desarrollo Inteligentes

> Última actualización: 2026-05-26

---

## 10.1 ¿Qué es CodeGraph?

**CodeGraph** es un grafo de conocimiento del código fuente generado mediante **tree-sitter**, que indexa cada símbolo, arista y archivo del proyecto. Proporciona consultas sub-milisegundo sobre la estructura del código — algo que `grep` no puede hacer.

El proyecto SISA tiene CodeGraph completamente inicializado (`.codegraph/`) y configurado como servidor MCP en `opencode.jsonc`, permitiendo a los asistentes de IA (como OpenCode) navegar y entender el código con precisión estructural.

## 10.2 Stack de Herramientas Dev

| Herramienta | Propósito | Ubicación |
|---|---|---|
| **CodeGraph** | Grafo de conocimiento AST del código | `.codegraph/` (raíz del proyecto) |
| **OpenCode** | Asistente de IA para desarrollo | Configurado en `opencode.jsonc` |
| **ESLint** | Linting de código frontend | `Academico/eslint.config.js` |
| **Prettier** | Formateo de código | `Academico/.prettierrc` |
| **Vitest** | Pruebas unitarias frontend | `Academico/tests/unit/` |
| **Playwright** | Pruebas E2E y API | `Academico/tests/e2e/`, `Academico/tests/api/` |
| **PHPUnit** | Pruebas backend | `back-2file/tests/` |

## 10.3 Herramientas de CodeGraph Disponibles

CodeGraph expone las siguientes herramientas MCP que pueden ser invocadas por asistentes de IA:

| Herramienta | Propósito | Ejemplo de uso |
|---|---|---|
| `codegraph_search` | Buscar símbolos por nombre | "¿Dónde está definido `MateriasComunesSyncService`?" |
| `codegraph_callers` | Ver quién llama a una función | "¿Qué llama a `syncUnidad()`?" |
| `codegraph_callees` | Ver qué llama una función | "¿Qué métodos invoca `mergeAndSyncOnLink`?" |
| `codegraph_trace` | Trazar ruta entre dos símbolos | "¿Cómo llega `guardarSeguimiento` a `propagarSeguimientoAComunes`?" |
| `codegraph_impact` | Analizar impacto de un cambio | "¿Qué rompería si modifico `comun_token`?" |
| `codegraph_node` | Ver detalle de un símbolo | Firma, docstring, ubicación de una función |
| `codegraph_context` | Contexto completo para una tarea | "¿Cómo funciona el módulo de materias comunes?" |
| `codegraph_explore` | Ver fuente de varios símbolos | Código agrupado por archivo de múltiples símbolos |
| `codegraph_files` | Explorar estructura de archivos | "¿Qué archivos hay en `src/pages/admin/`?" |
| `codegraph_status` | Estado del índice | Archivos pendientes de sincronizar |

## 10.4 Reglas de Uso para Desarrollo con IA

### Cuándo usar CodeGraph vs búsqueda tradicional

| Tipo de pregunta | Usar |
|---|---|
| "¿Dónde se define X?" / "Buscar símbolo X" | `codegraph_search` |
| "¿Qué función llama a Y?" | `codegraph_callers` |
| "¿Qué llama Y?" | `codegraph_callees` |
| "¿Cómo llega X a Y?" / "Trazar flujo de X a Y" | `codegraph_trace` (una sola llamada = ruta completa) |
| "¿Qué rompería si cambio Z?" | `codegraph_impact` |
| "Muéstrame firma/fuente de Y" | `codegraph_node` |
| "Dame contexto para tarea/área" | `codegraph_context` |
| "Ver fuente de varios símbolos" | `codegraph_explore` |
| "¿Qué archivos hay en ruta/?" | `codegraph_files` |
| Búsqueda literal de texto (strings, comentarios, logs) | `grep` / búsqueda nativa |

### Mejores prácticas

1. **Responder directo — no delegar exploración.** Para preguntas de arquitectura, usar `codegraph_context` primero, luego UN solo `codegraph_explore`. Para flujos específicos, iniciar con `codegraph_trace` desde→hasta.

2. **Confiar en resultados de CodeGraph.** Provienen de un parseo AST completo. NO re-verificar con grep — es más lento, menos preciso y consume más contexto.

3. **No hacer grep primero** al buscar símbolos por nombre. `codegraph_search` es más rápido.

4. **No encadenar `codegraph_search` + `codegraph_node`** cuando solo necesitas contexto — `codegraph_context` lo hace en una llamada.

5. **No iterar `codegraph_node` sobre muchos símbolos** — un solo `codegraph_explore` devuelve la fuente de varios símbolos agrupada.

6. **Verificar el banner de staleness.** Si la respuesta de codegraph comienza con "[!WARNING] Some files referenced below were edited since the last index sync...", esos archivos estan pendientes de re-indexacion. Leer esos archivos especificos. `codegraph_status` tambien lista archivos pendientes.

## 10.5 Inicialización y Mantenimiento

El índice de CodeGraph se encuentra en `.codegraph/` en la raíz del proyecto. Si alguna vez necesitas reconstruirlo:

```bash
codegraph init -i
```

Esto re-indexa todos los archivos del proyecto usando tree-sitter, creando el grafo de conocimiento completo.

### Estado actual del índice

El proyecto SISA cuenta con **568 archivos indexados** en CodeGraph, cubriendo tanto el frontend (Vue/Quasar) como el backend (Laravel/PHP), incluyendo todos los controladores, modelos, servicios, stores, páginas y componentes.

---

## 10.6 Scripts de Documentación

El proyecto incluye scripts de generación de documentación HTML:

```bash
# Desde Academico/
node generate_local_docs.cjs

# Desde back-2file/
node generate_local_docs.cjs
```

Estos scripts convierten todos los archivos `.md` de la carpeta `docs/` en páginas HTML interactivas con:
- Sidebar de navegación
- Renderizado Markdown con Marked.js
- Diagramas Mermaid
- Estilos Tailwind CSS dark theme
- Diseño responsive con menú móvil

---

## 10.7 Convenciones del Proyecto

| Convención | Descripción |
|---|---|
| `comun_token` | UUID para agrupar materias comunes |
| Anti-recursión | `static bool $isSyncing` en `MateriasComunesSyncService` |
| Matching de temas | Siempre por `unidad.numero + tema.orden` |
| Composición API | Vue 3 `<script setup>` en todo el frontend |
| Stores | Pinia con `pinia-plugin-persistedstate` |
| Rutas API | Prefijo `/api`, protegidas con `auth:sanctum` |
| Offline-First | Capacitor Filesystem + Network para modo sin conexión |
