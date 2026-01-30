<?php
use App\Models\User;
use App\Models\Rol;
use Illuminate\Support\Facades\Hash;

// 1. Buscar o Crear Rol VICERRECTOR_SEDE
$rolCodigo = 'VICERRECTOR_SEDE';
$rol = Rol::where('codigo', $rolCodigo)->orWhere('nombre', $rolCodigo)->first();

if (!$rol) {
    $rol = Rol::create([
        'nombre' => 'Vicerrector de Sede',
        'codigo' => $rolCodigo,
        'descripcion' => 'Acceso limitado a su sede designada',
        'activo' => true,
        'orden' => 3
    ]);
    echo "Rol creado: " . $rol->nombre . "\n";
} else {
    // Asegurar codigo correcto
    if ($rol->codigo !== $rolCodigo) {
        $rol->codigo = $rolCodigo;
        $rol->save();
    }
    echo "Rol existente: " . $rol->nombre . " (ID: " . $rol->id . ")\n";
}

// 2. Crear Usuario
$email = 'vicerrector.sede@unitepc.edu.bo';
$user = User::where('email', $email)->first();

if (!$user) {
    $user = User::create([
        'username' => 'vicerrector_sede',
        'password' => Hash::make('password123'),
        'nombre' => 'Vicerrector',
        'apellido' => 'Sede Prueba',
        'email' => $email,
        'rol_id' => $rol->id,
        'sede_id' => 1, // Asumiendo Sede 1 es válida
        'ci' => '87654321',
        'estado' => true
    ]);
    echo "Usuario creado exitosamente: " . $user->username . "\n";
} else {
    $user->rol_id = $rol->id;
    $user->sede_id = 1;
    $user->save();
    echo "Usuario actualizado: " . $user->username . "\n";
}
