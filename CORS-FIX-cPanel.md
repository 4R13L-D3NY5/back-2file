# Solución CORS para cPanel - Laravel API

## Problema
Error CORS al intentar login desde `https://documentacion.xpertiaplus.com` a `https://api.documentacion.xpertiaplus.com/api/login`:
```
Access to XMLHttpRequest at 'https://api.documentacion.xpertiaplus.com/api/login' from origin 'https://documentacion.xpertiaplus.com' has been blocked by CORS policy
```

## Archivos Modificados

### 1. `routes/api.php` (líneas 18-30)
Agrega manejo global de peticiones OPTIONS preflight.

### 2. `config/cors.php` (línea 24)
Agrega `https://www.documentacion.xpertiaplus.com` a los orígenes permitidos.

### 3. `public/.htaccess` (completo)
Nueva configuración optimizada para cPanel que:
- Redirige peticiones OPTIONS a `options-handler.php`
- Establece headers CORS con `Header set`
- Incluye reglas de seguridad básicas

### 4. `public/options-handler.php` (nuevo)
Manejador universal de peticiones OPTIONS que establece headers CORS.

### 5. `public/cors-diagnose.php` (nuevo)
Herramienta de diagnóstico (eliminar después de usar).

## Pasos de Implementación

### Paso 1: Subir archivos al servidor
```bash
# Conectarse al servidor via SSH o usar File Manager de cPanel
cd /home/usuario/public_html/api.documentacion.xpertiaplus.com

# Asegurar que el directorio public/ es el document root
# Si el dominio apunta directamente a public/, subir archivos allí:
cp routes/api.php /home/usuario/public_html/api.documentacion.xpertiaplus.com/app/routes/
cp config/cors.php /home/usuario/public_html/api.documentacion.xpertiaplus.com/app/config/
cp public/.htaccess /home/usuario/public_html/api.documentacion.xpertiaplus.com/public/
cp public/options-handler.php /home/usuario/public_html/api.documentacion.xpertiaplus.com/public/
cp public/cors-diagnose.php /home/usuario/public_html/api.documentacion.xpertiaplus.com/public/  # Temporal
```

### Paso 2: Habilitar módulos Apache en cPanel
1. Acceder a cPanel
2. Buscar "Apache Modules" en la barra de búsqueda
3. Asegurar que están habilitados:
   - `mod_rewrite`
   - `mod_headers`
4. Si no aparecen, contactar al soporte del hosting para habilitarlos.

### Paso 3: Verificar configuración de PHP
1. En cPanel, ir a "Select PHP Version"
2. Seleccionar PHP 8.1 o superior
3. Hacer clic en "Switch to PHP Options"
4. Verificar que no haya restricciones inusuales

### Paso 4: Limpiar caché de Laravel
```bash
cd /home/usuario/public_html/api.documentacion.xpertiaplus.com
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

### Paso 5: Probar configuración
1. Acceder a: `https://api.documentacion.xpertiaplus.com/cors-diagnose.php`
2. Verificar que muestre información y headers CORS
3. Probar con curl:
```bash
curl -I -X OPTIONS https://api.documentacion.xpertiaplus.com/api/login
```
Debería devolver:
```
HTTP/1.1 200 OK
Access-Control-Allow-Origin: https://documentacion.xpertiaplus.com
Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS
Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN, Accept, Origin
Access-Control-Allow-Credentials: true
Access-Control-Max-Age: 86400
```

### Paso 6: Probar login desde el frontend
Intentar login en `https://documentacion.xpertiaplus.com`

### Paso 7: Eliminar archivos temporales
```bash
rm /home/usuario/public_html/api.documentacion.xpertiaplus.com/public/cors-diagnose.php
```

## Solución de Problemas

### Si OPTIONS sigue devolviendo 404
1. Verificar que `.htaccess` se esté procesando:
   ```bash
   # En .htaccess agregar temporalmente:
   # ErrorDocument 404 "HTACCESS WORKS"
   ```
2. Verificar permisos de archivos:
   ```bash
   chmod 644 public/.htaccess
   chmod 644 public/options-handler.php
   ```

### Si los headers CORS no aparecen
1. Probar si `mod_headers` está habilitado:
   ```bash
   php -r "print_r(apache_get_modules());" | grep headers
   ```
2. Intentar con `Header always set` en lugar de `Header set`

### Si el problema persiste
1. Revisar logs de error de Apache:
   ```bash
   tail -f /usr/local/apache/logs/error_log
   ```
2. Verificar si hay Cloudflare o CDN:
   - Configurar reglas CORS en Cloudflare
   - Desactivar temporalmente para probar

## Configuración Alternativa (si nada funciona)

### Usar solo PHP para CORS
En `bootstrap/app.php`, agregar middleware personalizado:

```php
$middleware->append(function ($request, $next) {
    $response = $next($request);
    return $response
        ->header('Access-Control-Allow-Origin', 'https://documentacion.xpertiaplus.com')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN, Accept, Origin')
        ->header('Access-Control-Allow-Credentials', 'true');
});
```

### Contactar al hosting
Algunos hosts bloquean ciertas directivas de .htaccess por seguridad. Solicitar:
- Habilitar `AllowOverride All` para el directorio
- Habilitar `mod_headers` y `mod_rewrite`

## Archivos de Soporte
- `apache-check.sh`: Verifica configuración Apache
- `clear-cache.sh`: Limpia caché Laravel
- `nginx-cors.conf`: Configuración para Nginx (si aplica)