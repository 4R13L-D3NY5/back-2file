<?php
// Prueba para verificar el comportamiento del cast 'hashed' en Laravel

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Hash;

// Simular lo que hace el cast 'hashed' de Laravel
$plainPassword = '9465500';

// Escenario 1: Hash manual + cast hashed (código viejo)
$hashManual = Hash::make($plainPassword);
echo "Hash manual: " . $hashManual . "\n";
echo "Hash::check con hash manual: " . (Hash::check($plainPassword, $hashManual) ? 'OK' : 'FAIL') . "\n\n";

// Escenario 2: Pasar texto plano al cast hashed (código nuevo)
// El cast hashed detecta si ya está hasheado
$isHashed = Hash::isHashed($hashManual);
echo "Hash::isHashed en hash manual: " . ($isHashed ? 'YES' : 'NO') . "\n";

// Simular lo que haría el cast: si ya está hasheado, lo deja pasar
$castResult = Hash::isHashed($hashManual) ? $hashManual : Hash::make($hashManual);
echo "Resultado del cast con hash manual: " . (Hash::check($plainPassword, $castResult) ? 'OK' : 'FAIL') . "\n\n";

// Escenario 3: Verificar si un texto plano se hashea correctamente
$castPlain = Hash::isHashed($plainPassword) ? $plainPassword : Hash::make($plainPassword);
echo "Resultado del cast con texto plano: " . (Hash::check($plainPassword, $castPlain) ? 'OK' : 'FAIL') . "\n";

// Verificar si el rol DOCENTE existe
$rol = \App\Models\Rol::where('codigo', 'DOCENTE')->first();
echo "\nRol DOCENTE: " . ($rol ? "ID={$rol->id}, nombre={$rol->nombre}" : "NO EXISTE") . "\n";

// Verificar si existe el usuario 9465500
$user = \App\Models\User::where('username', '9465500')->orWhere('ci', '9465500')->first();
if ($user) {
    echo "\nUsuario 9465500 encontrado:\n";
    echo "  ID: {$user->id}\n";
    echo "  Username: {$user->username}\n";
    echo "  Estado: " . ($user->estado ? 'Activo' : 'Inactivo') . "\n";
    echo "  Rol ID: {$user->rol_id}\n";
    echo "  Password hash: " . substr($user->password, 0, 60) . "...\n";
    echo "  Hash::check(9465500, password): " . (Hash::check('9465500', $user->password) ? 'OK' : 'FAIL') . "\n";
} else {
    echo "\nUsuario 9465500 NO encontrado en la BD\n";
}

// Verificar si existe el docente 9465500
$docente = \App\Models\Docente::where('ci', '9465500')->first();
if ($docente) {
    echo "\nDocente 9465500 encontrado:\n";
    echo "  ID: {$docente->id}\n";
    echo "  Nombre: {$docente->nombre_completo}\n";
    echo "  User ID: " . ($docente->user_id ?? 'null') . "\n";
} else {
    echo "\nDocente 9465500 NO encontrado en la BD\n";
}
