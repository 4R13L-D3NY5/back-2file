<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$users = DB::table('users')->orderBy('id', 'desc')->limit(10)->get();

foreach ($users as $user) {
    echo "ID: {$user->id} | Email: {$user->email} | Rol: {$user->rol_id}\n";
}
