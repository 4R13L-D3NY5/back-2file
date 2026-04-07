# Despliegue Backend - API Documentación XpertiaPlus

## Configuración
- **URL Backend:** `https://api.documentacion.xpertiaplus.com`
- **URL Frontend:** `https://documentacion.xpertiaplus.com`
- **Framework:** Laravel 11
- **PHP:** 8.2+

## Variables de Entorno

### Producción (`.env.production`)
```env
APP_NAME="Documentación XpertiaPlus"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.documentacion.xpertiaplus.com
SANCTUM_STATEFUL_DOMAINS=documentacion.xpertiaplus.com

# Configuración CORS
SESSION_DOMAIN=.xpertiaplus.com

# Base de datos (configurar según entorno)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=xpertiap_documentacion
DB_USERNAME=xpertiap_documentacionuser
DB_PASSWORD=xpertiaplus
```

## Configuración CORS
Archivo `config/cors.php` ya configurado con:
1. Dominios permitidos:
   - `https://documentacion.xpertiaplus.com`
   - `https://planificacion.unitepc.edu.bo` (legacy)
   - Dominios de desarrollo local
2. Métodos permitidos: `*`
3. Credenciales: `true`
4. Headers permitidos: `*`

## Pasos de Despliegue

### 1. Preparación del Servidor
```bash
# Clonar repositorio
git clone <repo> /var/www/api.documentacion.xpertiaplus.com

# Navegar al directorio
cd /var/www/api.documentacion.xpertiaplus.com

# Configurar permisos
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### 2. Configuración de Entorno
```bash
# Copiar archivo de entorno de producción
cp .env.production .env

# Generar clave de aplicación (si no existe)
php artisan key:generate

# Configurar almacenamiento simbólico
php artisan storage:link
```

### 3. Instalación de Dependencias
```bash
# Instalar dependencias de Composer
composer install --optimize-autoloader --no-dev

# Instalar dependencias NPM (para assets)
npm install && npm run build
```

### 4. Configuración de Base de Datos
```bash
# Ejecutar migraciones
php artisan migrate --force

# Ejecutar seeders (opcional)
php artisan db:seed --force

# Cache de configuración
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 5. Configuración del Servidor Web

#### Nginx
```nginx
server {
    listen 80;
    server_name api.documentacion.xpertiaplus.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name api.documentacion.xpertiaplus.com;

    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;

    root /var/www/api.documentacion.xpertiaplus.com/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

#### Apache (.htaccess en public/)
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

### 6. Configuración de Colas (Opcional)
```bash
# Configurar supervisor para queues
sudo nano /etc/supervisor/conf.d/laravel-worker.conf

[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/api.documentacion.xpertiaplus.com/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/api.documentacion.xpertiaplus.com/storage/logs/worker.log
```

### 7. Verificación
```bash
# Verificar estado de la aplicación
php artisan route:list
php artisan storage:link --verbose

# Probar conexión API
curl -I https://api.documentacion.xpertiaplus.com/api/health
```

### 8. Monitoreo y Mantenimiento
```bash
# Programar tareas cron
* * * * * cd /var/www/api.documentacion.xpertiaplus.com && php artisan schedule:run >> /dev/null 2>&1

# Limpiar cache periódicamente
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

## Configuración de Desarrollo Local
```bash
# Usar entorno local
cp .env.example .env

# Servidor de desarrollo
php artisan serve --port=8000
```

## Problemas Comunes

### CORS
- Verificar que `config/cors.php` incluya el dominio frontend
- Asegurar que `Access-Control-Allow-Credentials: true`
- Verificar headers `Access-Control-Allow-Origin`

### Sesiones
- Configurar `SESSION_DOMAIN=.xpertiaplus.com` para cookies entre subdominios
- Asegurar que `SESSION_SECURE_COOKIE=true` en producción

### Storage
- Verificar que `storage/` tenga permisos de escritura
- Ejecutar `php artisan storage:link` para enlace simbólico

## Solución de Problemas CORS

### Síntomas
- Error en navegador: "No 'Access-Control-Allow-Origin' header is present"
- Preflight OPTIONS falla con error de red

### Verificación Rápida
1. **Probar preflight OPTIONS:**
   ```bash
   curl -X OPTIONS -I https://api.documentacion.xpertiaplus.com/api/login
   ```
   Debe retornar:
   - `HTTP/1.1 204 No Content`
   - `Access-Control-Allow-Origin: https://documentacion.xpertiaplus.com`
   - `Access-Control-Allow-Credentials: true`

2. **Diagnóstico detallado:**
   - Acceder a: `https://api.documentacion.xpertiaplus.com/cors-diagnose.php`
   - Este archivo muestra información del servidor y verifica configuración CORS

### Soluciones Comunes

#### 1. Módulos Apache
```bash
# Habilitar módulos necesarios (cPanel)
a2enmod rewrite headers
systemctl restart apache2
```

#### 2. Permisos .htaccess
- Verificar que el directorio tenga `AllowOverride All` en configuración Apache
- Asegurar que `.htaccess` esté en `/public/`

#### 3. Cache Laravel
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

#### 4. Configuración de Sesión
- Verificar `.env.production` tiene:
  ```
  SESSION_DOMAIN=.xpertiaplus.com
  SESSION_SAME_SITE=none
  SANCTUM_STATEFUL_DOMAINS=documentacion.xpertiaplus.com
  ```

### Archivos de Configuración Clave
1. `public/.htaccess` - Headers CORS de Apache
2. `config/cors.php` - Configuración CORS de Laravel
3. `public/index.php` - Manejo PHP de preflight OPTIONS
4. `routes/api.php` - Ruta OPTIONS específica

### Si persiste el error
1. Revisar logs de error de Apache
2. Verificar que el servidor no sea Nginx (usar `cors-diagnose.php`)
3. Probar con el archivo `test-cors-simple.php`