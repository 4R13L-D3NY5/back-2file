<?php
/**
 * CORS Diagnostic Tool for cPanel/Laravel
 * Access via: https://api.documentacion.xpertiaplus.com/cors-diagnose.php
 * DELETE THIS FILE AFTER DIAGNOSIS!
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== CORS DIAGNOSTIC TOOL ===\n\n";

// Check if accessed via OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo "OPTIONS Preflight Request Detected\n";
    echo "HTTP Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'Not set') . "\n";
    echo "HTTP Access-Control-Request-Method: " . ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? 'Not set') . "\n";
    echo "HTTP Access-Control-Request-Headers: " . ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? 'Not set') . "\n";
    
    // Send CORS headers
    header('Access-Control-Allow-Origin: https://documentacion.xpertiaplus.com');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN, Accept, Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
    
    exit(0);
}

// Send CORS headers for GET request too
header('Access-Control-Allow-Origin: https://documentacion.xpertiaplus.com');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN, Accept, Origin');
header('Access-Control-Allow-Credentials: true');

// Collect diagnostic info
echo "1. SERVER INFORMATION:\n";
echo "   Request Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "   HTTP Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'Not set') . "\n";
echo "   Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
echo "   PHP Version: " . PHP_VERSION . "\n";
echo "   Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "\n\n";

echo "2. HEADERS SENT BY PHP:\n";
$headers = headers_list();
if (empty($headers)) {
    echo "   No headers sent yet\n";
} else {
    foreach ($headers as $header) {
        echo "   " . $header . "\n";
    }
}
echo "\n";

echo "3. APACHE MODULES (if available):\n";
if (function_exists('apache_get_modules')) {
    $modules = apache_get_modules();
    $important = ['mod_rewrite', 'mod_headers'];
    foreach ($important as $mod) {
        echo "   " . $mod . ": " . (in_array($mod, $modules) ? "ENABLED" : "DISABLED") . "\n";
    }
} else {
    echo "   apache_get_modules() not available\n";
}
echo "\n";

echo "4. LARAVEL CORS CONFIG (if accessible):\n";
$configPath = __DIR__ . '/../config/cors.php';
if (file_exists($configPath)) {
    $config = include $configPath;
    echo "   Allowed Origins:\n";
    foreach ($config['allowed_origins'] ?? [] as $origin) {
        echo "     - " . $origin . "\n";
    }
    echo "   Supports Credentials: " . ($config['supports_credentials'] ?? 'false') . "\n";
} else {
    echo "   config/cors.php not found\n";
}
echo "\n";

echo "5. .HTACCESS CHECK:\n";
$htaccessPath = __DIR__ . '/.htaccess';
if (file_exists($htaccessPath)) {
    echo "   .htaccess exists at: " . $htaccessPath . "\n";
    $content = file_get_contents($htaccessPath);
    $hasCors = stripos($content, 'Access-Control') !== false;
    $hasOptions = stripos($content, 'OPTIONS') !== false;
    echo "   Contains CORS rules: " . ($hasCors ? "YES" : "NO") . "\n";
    echo "   Contains OPTIONS handling: " . ($hasOptions ? "YES" : "NO") . "\n";
} else {
    echo "   .htaccess not found in public directory\n";
}
echo "\n";

echo "6. CURL TEST (simulating browser preflight):\n";
$testUrl = 'https://api.documentacion.xpertiaplus.com/api/login';
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $testUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'OPTIONS');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Origin: https://documentacion.xpertiaplus.com',
        'Access-Control-Request-Method: POST',
        'Access-Control-Request-Headers: Content-Type, Authorization'
    ]);
    
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    echo "   HTTP Status: " . $info['http_code'] . "\n";
    echo "   Response Headers (first 10 lines):\n";
    $headerLines = explode("\n", substr($response, 0, strpos($response, "\r\n\r\n")));
    for ($i = 0; $i < min(10, count($headerLines)); $i++) {
        echo "     " . trim($headerLines[$i]) . "\n";
    }
} else {
    echo "   cURL not available\n";
}
echo "\n";

echo "=== RECOMMENDATIONS ===\n";
echo "1. Check if mod_headers and mod_rewrite are enabled in cPanel\n";
echo "2. Verify .htaccess is being processed (AllowOverride All)\n";
echo "3. Clear Laravel cache: php artisan config:clear && php artisan cache:clear\n";
echo "4. Test with: curl -I -X OPTIONS https://api.documentacion.xpertiaplus.com/api/login\n";
echo "5. Check cPanel error logs\n";
echo "\n";
echo "DELETE THIS FILE AFTER DIAGNOSIS!\n";