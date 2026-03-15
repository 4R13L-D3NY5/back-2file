<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$dbCurrent = 'academico';

echo "Table List:\n";
print_r(Schema::connection('mysql')->getTableListing());

$user8 = DB::table('users')->where('id', 8)->first();
echo "\nUser 8:\n";
print_r($user8);

$docente8 = DB::table('docentes')->where('id', 8)->first();
echo "\nDocente 8 (by ID):\n";
print_r($docente8);

$docenteByUser8 = DB::table('docentes')->where('user_id', 8)->first();
echo "\nDocente associated with User 8:\n";
print_r($docenteByUser8);


