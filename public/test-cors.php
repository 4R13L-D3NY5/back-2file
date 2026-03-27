<?php
/**
 * Simple CORS test file
 * URL: https://api.documentacion.xpertiaplus.com/test-cors.php
 */

// Always set CORS headers
header('Access-Control-Allow-Origin: https://documentacion.xpertiaplus.com');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN, Accept, Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');
header('Content-Type: text/plain; charset=utf-8');

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit('OPTIONS handled by PHP');
}

// Show server info
echo "=== CORS TEST ===\n\n";
echo "Request Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "HTTP Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'Not set') . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "\n";
echo "Script Filename: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'Unknown') . "\n";
echo "Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'Unknown') . "\n\n";

// Check if .htaccess is being processed
echo "=== .HTACCESS TEST ===\n";
$htaccessPath = __DIR__ . '/.htaccess';
if (file_exists($htaccessPath)) {
    echo ".htaccess exists: YES\n";
    echo "Path: " . $htaccessPath . "\n";
    echo "Size: " . filesize($htaccessPath) . " bytes\n\n";
    
    // Test rewrite by checking for special header
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        echo "RewriteRule for Authorization header: WORKING\n";
    }
} else {
    echo ".htaccess exists: NO\n";
}

// Test mod_rewrite
echo "\n=== MOD_REWRITE TEST ===\n";
if (function_exists('apache_get_modules')) {
    $modules = apache_get_modules();
    echo "Apache modules loaded:\n";
    $important = ['mod_rewrite', 'mod_headers', 'mod_env'];
    foreach ($important as $mod) {
        echo "  " . $mod . ": " . (in_array($mod, $modules) ? "YES" : "NO") . "\n";
    }
} else {
    echo "apache_get_modules() not available\n";
    echo "Try checking: " . php_ini_loaded_file() . "\n";
}

// Check for CGI/FPM
echo "\n=== PHP HANDLER ===\n";
$sapi = php_sapi_name();
echo "PHP SAPI: " . $sapi . "\n";
if (strpos($sapi, 'cgi') !== false || strpos($sapi, 'fpm') !== false) {
    echo "WARNING: Running as CGI/FPM - .htaccess may not work fully\n";
    echo "Try adding to php.ini or .user.ini:\n";
    echo "  cgi.fix_pathinfo = 1\n";
}

// Test OPTIONS handling
echo "\n=== OPTIONS TEST ===\n";
echo "To test OPTIONS preflight:\n";
echo "curl -X OPTIONS -H 'Origin: https://documentacion.xpertiaplus.com' \\\n";
echo "  -H 'Access-Control-Request-Method: POST' \\\n";
echo "  https://api.documentacion.xpertiaplus.com/test-cors.php\n";

echo "\n=== RECOMMENDATIONS ===\n";
echo "1. If .htaccess not working, check cPanel:\n";
echo "   - 'Apache Modules' → enable rewrite_module, headers_module\n";
echo "   - File Manager → check .htaccess is in correct directory\n";
echo "2. If using CGI/FPM, add to .user.ini:\n";
echo "   cgi.fix_pathinfo = 1\n";
echo "3. Test with: curl -I -X OPTIONS https://api.documentacion.xpertiaplus.com/test-cors.php\n";