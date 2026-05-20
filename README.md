# 🚀 SISA - Sistema Integrado de Seguimiento Académico
### ⚙️ Portal de la API Backend (Laravel 12 API REST)

Bienvenido al repositorio oficial del **Backend** de **SISA** (Sistema Integrado de Seguimiento Académico). Esta API REST proporciona el motor lógico, la persistencia, la sincronización y la seguridad para toda la plataforma web y móvil.

El backend está desarrollado utilizando **Laravel v12** (PHP 8.2+), siguiendo patrones de arquitectura robustos, controladores delgados (*Thin Controllers*), desacoplamiento mediante clases de *Servicios* y autenticación stateless por tokens mediante **Laravel Sanctum**.

---

## 📚 Índice de Documentación Técnica y Arquitectura

Toda la documentación detallada del sistema y su comportamiento en base de datos está disponible en la carpeta `docs/`. Explora los módulos a continuación:

```mermaid
graph TD
    A[SISA Backend API] --> B(01. Seguridad y Sanctum)
    A --> C(Endpoints e Integración API)
    A --> D(Diagrama de Base de Datos ER)
    A --> E(Reglas de Negocio y Sincronización)
```

### 📖 Recursos Críticos de la API:

*   **[🔌 Catálogo Completo de Endpoints (endpoints.md)](docs/endpoints.md):** Especificación técnica detallada de todas las rutas de la API, parámetros del Request, payload del Response y códigos de estado HTTP.
*   **[🗄️ Esquema y Diagrama de Base de Datos (diagrama_er_completo.md)](docs/diagrama_er_completo.md):** Definición detallada de tablas, llaves primarias y foráneas, e índices de rendimiento de la base de datos de SISA.

### 📘 Capítulos Generales de Documentación:

1.  **[🔒 Autenticación y Roles](docs/01_autenticacion_seguridad.md):** Laravel Sanctum, roles de usuario (`SUPER_ADMIN`, `DIRECTOR_CARRERA`, `DOCENTE`), y control de acceso jerárquico.
2.  **[🏫 Gestión de Infraestructura Académica](docs/02_estructura_academica.md):** Modelos y relaciones para Sedes, Carreras, Campus, Bloques, Aulas e importación de mallas.
3.  **[📚 PAC (Plan Académico de Asignatura)](docs/03_pac_y_bibliografia.md):** CRUD y lógica de carga jerárquica para unidades, temas y bibliografías.
4.  **[🔗 Algoritmo de Materias Comunes](docs/04_materias_comunes.md):** Lógica del backend para merge inteligente, resolución de conflictos y asignación multipropósito.
5.  **[📅 Planificación Semestral](docs/05_planificacion_semestral.md):** Generador de secuencias didácticas automáticas basadas en el calendario académico.
6.  **[📝 Control de Asistencia y Clases](docs/06_control_clase_seguimiento.md):** API de sincronización activa de clases firmadas, recepción y almacenamiento de imágenes en servidor.
7.  **[🎯 Banco de Evaluaciones](docs/07_banco_preguntas_evaluaciones.md):** Motor de selección aleatoria de reactivos según parámetros y generación masiva de PDF de exámenes.
8.  **[⚙️ Gestión de Evaluaciones y Rol de Exámenes](docs/08_gestion_evaluaciones_y_examenes.md):** Directivas de exámenes y calendario del Rol de Exámenes con validaciones de colisión en tiempo real.
9.  **[🔄 Sincronización y Motores de Comparación](docs/09_sincronizacion_y_patrones.md):** Sincronización centralizada, comparadores analíticos pre/post sync, verificador lexical PDF y restaurador granular de backups.

---

## 🛠️ Guía de Arranque Rápido para Desarrolladores

### Requisitos del Entorno
*   **PHP** >= 8.2 (con extensiones `pdo_mysql`, `mbstring`, `openssl`, etc.)
*   **Composer** >= 2.x
*   **MySQL / MariaDB**

### 1. Instalación de Dependencias
```bash
composer install
```

### 2. Configuración del Entorno
Duplica el archivo `.env.example` y renómbralo a `.env`:
```bash
cp .env.example .env
```
*Configura las credenciales de tu base de datos en las variables `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`.*

### 3. Generar la Llave de Aplicación y Enlace de Storage
```bash
php artisan key:generate
php artisan storage:link
```

### 4. Ejecución de Migraciones y Semillas (Seeders)
Crea la base de datos y llénala con datos de prueba oficiales:
```bash
php artisan migrate:fresh --seed
```

### 5. Iniciar Servidor de Desarrollo
```bash
php artisan serve
```
*La API estará disponible en `http://localhost:8000`.*

---

## 🛡️ Estándares y Convenciones

*   **Thin Controllers:** Los controladores se encargan estrictamente de HTTP.
*   **Service Pattern:** La lógica de negocio pesada reside en `app/Services/`.
*   **API Resources:** Transformación estandarizada de modelos a JSON en `app/Http/Resources/`.
*   **Seguridad:** Middlewares estrictos de control de accesos basados en roles.

---
Desarrollado con ❤️ para garantizar robustez y escalabilidad en SISA.
