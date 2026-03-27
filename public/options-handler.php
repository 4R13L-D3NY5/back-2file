<?php
/**
 * Universal OPTIONS handler for CORS preflight requests
 * This file handles all OPTIONS requests before they reach Laravel
 */

// Allow from any origin (but we'll restrict to specific domains)
if (isset($_SERVER['HTTP_ORIGIN'])) {
    $allowed_origins = [
        'https://documentacion.xpertiaplus.com',
        'https://www.documentacion.xpertiaplus.com',
        'https://planificacion.unitepc.edu.bo',
        'http://localhost:9000',
        'http://127.0.0.1:9000',
        'http://localhost:8080',
        'http://127.0.0.1:8080',
        'http://localhost',
        'capacitor://localhost',
        'https://localhost',
    ];
    
    $origin = $_SERVER['HTTP_ORIGIN'];
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: " . $origin);
    }
} else {
    // Fallback to primary domain
    header("Access-Control-Allow-Origin: https://documentacion.xpertiaplus.com");
}

// Always allow these methods
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");

// Allow these headers
$request_headers = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '';
if ($request_headers) {
    header("Access-Control-Allow-Headers: " . $request_headers);
} else {
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN, Accept, Origin");
}

// Allow credentials
header("Access-Control-Allow-Credentials: true");

// Cache preflight response for 24 hours
header("Access-Control-Max-Age: 86400");

// Return 200 OK for OPTIONS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// If not OPTIONS, redirect to Laravel (should not happen if .htaccess is configured correctly)
header("HTTP/1.1 400 Bad Request");
echo "This script only handles OPTIONS requests. Ensure your .htaccess is correctly configured.";