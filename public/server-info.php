<?php
/**
 * Server information for debugging CORS issues
 * Access: https://api.documentacion.xpertiaplus.com/server-info.php
 */

// Disable caching
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Content-Type: text/plain; charset=utf-8');

// Simple CORS headers for this file
header('Access-Control-Allow-Origin: https://documentacion.xpertiaplus.com');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

echo "=== SERVER INFORMATION ===\n\n";

// Basic server info
echo "1. SERVER DETAILS:\n";
echo "   Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') . "\n";
echo "   Server Name: " . ($_SERVER['SERVER_NAME'] ?? 'N/A') . "\n";
echo "   Server Addr: " . ($_SERVER['SERVER_ADDR'] ?? 'N/A') . "\n";
echo "   Server Port: " . ($_SERVER['SERVER_PORT'] ?? 'N/A') . "\n";
echo "   Remote Addr: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "\n";
echo "   Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'N/A') . "\n";
echo "   Script Filename: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'N/A') . "\n";
echo "   Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "\n";
echo "   Request Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "   HTTP Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'Not set') . "\n\n";

// PHP info
echo "2. PHP CONFIGURATION:\n";
echo "   PHP Version: " . PHP_VERSION . "\n";
echo "   PHP SAPI: " . php_sapi_name() . "\n";
echo "   Loaded php.ini: " . (php_ini_loaded_file() ?: 'N/A') . "\n";
echo "   Additional .ini: " . (php_ini_scanned_files() ?: 'N/A') . "\n";
echo "   Memory Limit: " . ini_get('memory_limit') . "\n";
echo "   Max Execution Time: " . ini_get('max_execution_time') . "\n";
echo "   Post Max Size: " . ini_get('post_max_size') . "\n";
echo "   Upload Max Filesize: " . ini_get('upload_max_filesize') . "\n\n";

// Check Apache modules
echo "3. APACHE MODULES (if available):\n";
if (function_exists('apache_get_modules')) {
    $modules = apache_get_modules();
    sort($modules);
    $important = ['mod_rewrite', 'mod_headers', 'mod_env', 'mod_setenvif'];
    foreach ($important as $mod) {
        echo "   " . $mod . ": " . (in_array($mod, $modules) ? "ENABLED" : "DISABLED/MISSING") . "\n";
    }
    echo "   Total modules: " . count($modules) . "\n";
} else {
    echo "   apache_get_modules() not available\n";
    echo "   PHP is likely running as CGI/FPM\n";
}

// Check for .htaccess
echo "\n4. .HTACCESS CHECK:\n";
$paths = [
    '.htaccess',
    dirname(__DIR__) . '/.htaccess',
    $_SERVER['DOCUMENT_ROOT'] . '/.htaccess',
    $_SERVER['DOCUMENT_ROOT'] . '/public/.htaccess'
];

foreach ($paths as $path) {
    if (file_exists($path)) {
        echo "   FOUND: " . $path . "\n";
        echo "   Size: " . filesize($path) . " bytes\n";
        $content = file_get_contents($path);
        $hasCors = stripos($content, 'Access-Control') !== false;
        $hasOptions = stripos($content, 'OPTIONS') !== false;
        echo "   Contains CORS: " . ($hasCors ? 'YES' : 'NO') . "\n";
        echo "   Contains OPTIONS: " . ($hasOptions ? 'YES' : 'NO') . "\n\n";
    }
}

// Check Laravel structure
echo "5. LARAVEL STRUCTURE CHECK:\n";
$laravelFiles = [
    'artisan' => 'Laravel root file',
    'bootstrap/app.php' => 'Laravel bootstrap',
    'public/index.php' => 'Laravel public index',
    'routes/api.php' => 'API routes',
    'config/cors.php' => 'CORS config'
];

foreach ($laravelFiles as $file => $desc) {
    $fullPath = dirname(__DIR__) . '/' . $file;
    if (file_exists($fullPath)) {
        echo "   ✅ " . $desc . ": " . $file . "\n";
    } else {
        // Try relative to document root
        $rootPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $file;
        if (file_exists($rootPath)) {
            echo "   ✅ " . $desc . ": " . $rootPath . " (from doc root)\n";
        } else {
            echo "   ❌ " . $desc . ": NOT FOUND\n";
        }
    }
}

// Test current directory
echo "\n6. CURRENT DIRECTORY ANALYSIS:\n";
echo "   Current file: " . __FILE__ . "\n";
echo "   Current dir: " . __DIR__ . "\n";
echo "   Parent dir: " . dirname(__DIR__) . "\n";

// Check if we're in public directory
$isPublicDir = basename(__DIR__) === 'public';
echo "   Is in 'public' directory: " . ($isPublicDir ? 'YES' : 'NO') . "\n";

// Check parent directory for Laravel files
$parentHasArtisan = file_exists(dirname(__DIR__) . '/artisan');
echo "   Parent has 'artisan': " . ($parentHasArtisan ? 'YES' : 'NO') . "\n";

// Check document root relationship
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
echo "   Document Root: " . $docRoot . "\n";
echo "   Is current dir same as doc root: " . (realpath(__DIR__) === realpath($docRoot) ? 'YES' : 'NO') . "\n";

// Recommendations
echo "\n=== RECOMMENDATIONS ===\n";
echo "1. If PHP is CGI/FPM:\n";
echo "   - Create .user.ini in public/ with: cgi.fix_pathinfo = 1\n";
echo "   - Or contact hosting to enable Apache module\n\n";

echo "2. If .htaccess not found:\n";
echo "   - Ensure .htaccess is in Document Root: " . $docRoot . "\n";
echo "   - Check cPanel File Manager permissions\n\n";

echo "3. If modules missing:\n";
echo "   - cPanel → Apache Modules → enable rewrite_module, headers_module\n\n";

echo "4. Quick test URLs:\n";
echo "   - This file: https://" . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'] . "\n";
echo "   - OPTIONS test: curl -X OPTIONS https://" . $_SERVER['SERVER_NAME'] . "/api/login\n";
echo "   - Simple test: curl -I https://" . $_SERVER['SERVER_NAME'] . "/test-cors.php\n";

echo "\n=== NEXT STEPS ===\n";
echo "1. Check cPanel error logs\n";
echo "2. Test with simplest .htaccess first\n";
echo "3. Verify Document Root points to Laravel's public/ directory\n";
echo "4. Contact hosting support if modules can't be enabled\n";