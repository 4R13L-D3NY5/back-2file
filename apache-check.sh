#!/bin/bash
echo "=== CORS DIAGNOSTIC TOOL for cPanel ==="
echo

# Check if running on cPanel
echo "1. SERVER TYPE:"
if [ -d "/usr/local/cpanel" ]; then
    echo "   ✅ cPanel detected"
    CPANEL=true
else
    echo "   ⚠️  cPanel not detected (may be different hosting)"
    CPANEL=false
fi

echo

# Check Apache modules
echo "2. APACHE MODULES (required for CORS):"
if command -v apache2ctl &> /dev/null; then
    APACHE_CMD="apache2ctl"
elif command -v httpd &> /dev/null; then
    APACHE_CMD="httpd"
else
    APACHE_CMD=""
fi

if [ -n "$APACHE_CMD" ]; then
    $APACHE_CMD -M 2>/dev/null | grep -q "rewrite_module" && echo "   ✅ mod_rewrite: ENABLED" || echo "   ❌ mod_rewrite: DISABLED"
    $APACHE_CMD -M 2>/dev/null | grep -q "headers_module" && echo "   ✅ mod_headers: ENABLED" || echo "   ❌ mod_headers: DISABLED"
else
    echo "   ⚠️  Apache command not found"
    
    # Try alternative method for cPanel
    if [ "$CPANEL" = true ]; then
        echo "   Trying cPanel-specific check..."
        if [ -f "/usr/local/apache/conf/httpd.conf" ]; then
            grep -q "LoadModule rewrite_module" /usr/local/apache/conf/httpd.conf && echo "   ✅ mod_rewrite: ENABLED (in httpd.conf)" || echo "   ❌ mod_rewrite: NOT FOUND in httpd.conf"
            grep -q "LoadModule headers_module" /usr/local/apache/conf/httpd.conf && echo "   ✅ mod_headers: ENABLED (in httpd.conf)" || echo "   ❌ mod_headers: NOT FOUND in httpd.conf"
        fi
    fi
fi

echo

# Check .htaccess
echo "3. .HTACCESS FILES:"
HTACCESS_PATHS=(".htaccess" "public/.htaccess" "../.htaccess" "../../.htaccess")
for path in "${HTACCESS_PATHS[@]}"; do
    if [ -f "$path" ]; then
        echo "   ✅ Found: $path"
        echo "   Size: $(wc -l < "$path") lines"
        grep -q "Access-Control" "$path" && echo "   Contains CORS rules: YES" || echo "   Contains CORS rules: NO"
        grep -q "OPTIONS" "$path" && echo "   Handles OPTIONS: YES" || echo "   Handles OPTIONS: NO"
        echo
    fi
done

echo

# Check PHP and Laravel
echo "4. PHP & LARAVEL:"
if command -v php &> /dev/null; then
    echo "   PHP Version: $(php -v | head -1)"
    
    # Check if Laravel artisan exists
    if [ -f "artisan" ]; then
        echo "   ✅ Laravel artisan found"
        
        # Check Laravel CORS config
        if [ -f "config/cors.php" ]; then
            echo "   ✅ Laravel CORS config exists"
            echo "   Allowed origins:"
            grep -A10 "'allowed_origins'" config/cors.php | grep "https://" | sed 's/^/     /'
        else
            echo "   ⚠️  Laravel CORS config not found"
        fi
    else
        echo "   ⚠️  Not a Laravel root directory (artisan not found)"
    fi
else
    echo "   ❌ PHP not found"
fi

echo

# Test CORS with curl
echo "5. CORS TEST (using curl):"
if command -v curl &> /dev/null; then
    # Try to get the domain from current directory or config
    DOMAIN="api.documentacion.xpertiaplus.com"
    
    echo "   Testing OPTIONS request to $DOMAIN..."
    curl -s -I -X OPTIONS "https://$DOMAIN/api/login" > /tmp/cors_test.txt 2>&1
    
    if [ $? -eq 0 ]; then
        echo "   HTTP Status: $(grep -i "^HTTP" /tmp/cors_test.txt | head -1)"
        echo "   Headers found:"
        grep -i "access-control" /tmp/cors_test.txt | sed 's/^/     /'
        
        if grep -q "Access-Control-Allow-Origin" /tmp/cors_test.txt; then
            echo "   ✅ CORS headers detected!"
        else
            echo "   ❌ No CORS headers in response"
        fi
    else
        echo "   ⚠️  Could not connect to $DOMAIN"
        cat /tmp/cors_test.txt | tail -5
    fi
    rm -f /tmp/cors_test.txt
else
    echo "   ⚠️  curl not installed"
fi

echo

# cPanel-specific instructions
echo "6. cPanel RECOMMENDATIONS:"
if [ "$CPANEL" = true ]; then
    echo "   a) Enable Apache modules via:"
    echo "      - Login to cPanel"
    echo "      - Search for 'Apache Modules'"
    echo "      - Enable: rewrite_module, headers_module"
    echo
    echo "   b) Check PHP version:"
    echo "      - 'Select PHP Version' in cPanel"
    echo "      - Choose PHP 8.1 or higher"
    echo
    echo "   c) Check error logs:"
    echo "      - cPanel → Metrics → Errors"
    echo "      - Or: /home/$(whoami)/logs/error_log"
else
    echo "   For non-cPanel hosting:"
    echo "   - Contact hosting support to enable mod_rewrite and mod_headers"
    echo "   - Ensure AllowOverride All is set for your directory"
fi

echo
echo "=== QUICK FIXES ==="
echo "1. If modules are disabled, ask hosting support to enable them"
echo "2. Ensure .htaccess is in the correct directory (usually public/)"
echo "3. Clear Laravel cache: php artisan config:clear && php artisan cache:clear"
echo "4. Test with diagnostic tool: https://api.documentacion.xpertiaplus.com/cors-diagnose.php"
echo "5. If using Cloudflare, disable temporarily or add CORS rules in Page Rules"