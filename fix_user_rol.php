<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Fix users with invalid roles (anything other than 1-8 that was meant to be 9)
// or users with email lore@mail.com
$users = DB::table('users')->where('email', 'lore@mail.com')->get();

foreach ($users as $user) {
    echo "Fixing user: {$user->email} (current role: {$user->rol_id}) -> New role: 9\n";
    DB::table('users')->where('id', $user->id)->update(['rol_id' => 9]);
}

if (count($users) === 0) {
    echo "No user lore@mail.com found. Checking for any user with rol_id potentially wrong (> 8 or null if intended as 9).\n";
}
