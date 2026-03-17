<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$users = DB::table('users')->select('id', 'email', 'username', 'ci', 'rol_id', 'estado')->get();
foreach ($users as $u) {
    echo "ID: $u->id, Email: $u->email, Username: $u->username, CI: $u->ci, Rol: $u->rol_id, Estado: $u->estado\n";
}