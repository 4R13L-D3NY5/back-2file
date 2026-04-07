<?php
// Test CORS headers
header('Access-Control-Allow-Origin: https://documentacion.xpertiaplus.com');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN, Accept, Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => 'CORS headers are set',
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'headers_sent' => headers_list()
]);