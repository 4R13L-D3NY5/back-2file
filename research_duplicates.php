<?php

use App\Models\Docente;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- RESEARCHING DUPLICATE DOCENTES ---\n";

// 1. Find Docentes with user_id but NO CI
$problematicDocentes = Docente::whereNotNull('user_id')
    ->where(function ($q) {
        $q->whereNull('ci')->orWhere('ci', '');
    })
    ->get();

echo "Found " . $problematicDocentes->count() . " Docentes with user_id but NO CI.\n";

foreach ($problematicDocentes as $d) {
    $user = User::find($d->user_id);
    if (!$user) {
        echo "[ORPHAN] Docente ID: {$d->id} has User ID: {$d->user_id} but user not found.\n";
        continue;
    }

    $username = $user->username;
    echo "Docente ID: {$d->id} | User ID: {$d->user_id} | Username: {$username} | Name: {$d->nombre_completo}\n";

    // Check if there is another Docente with this CI (username)
    $other = Docente::where('ci', $username)->first();
    if ($other) {
        echo "  -> COLLISION with Docente ID: {$other->id} (CI: {$other->ci}, User ID: " . ($other->user_id ?? 'NULL') . ")\n";
        
        // Count groups for both
        $countOrig = DB::table('grupos')->where('docente_id', $d->id)->count();
        $countOther = DB::table('grupos')->where('docente_id', $other->id)->count();
        
        echo "  -> Groups: Original={$countOrig}, Other={$countOther}\n";
    }
}

echo "--- END ---\n";
