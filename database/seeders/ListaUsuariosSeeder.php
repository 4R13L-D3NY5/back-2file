<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Carrera;
use App\Models\Director;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ListaUsuariosSeeder extends Seeder
{
    public function run()
    {
        // Data with duplicates removed
        $usersData = [
            // COBIJA
            ['email' => 'ccossio_cobija@unitepc.edu.bo', 'sede' => 'COBIJA', 'cargo' => 'VICERRECTOR', 'carrera' => '', 'nombre' => 'Claudia Yukary Cossio Shimabukuro', 'ci' => '3878980', 'telefono' => '71112423'],
            ['email' => 'ccortez_medcbj@unitepc.edu.bo', 'sede' => 'COBIJA', 'cargo' => 'JEFE DE DEPARTAMENTO', 'carrera' => 'MEDICINA', 'nombre' => 'Consuelo Cortez Suarez', 'ci' => '4202143', 'telefono' => '76101321'],
            ['email' => 'avaldez_dmedcbj@unitepc.edu.bo', 'sede' => 'COBIJA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'MEDICINA', 'nombre' => 'ANDREA PAOLA VALDEZ VALERIANO', 'ci' => '1767811', 'telefono' => '72700367'],
            ['email' => 'ivoncardozo_dmedcbj@unitepc.edu.bo', 'sede' => 'COBIJA', 'cargo' => 'JEFE DE DEPARTAMENTO', 'carrera' => 'MEDICINA', 'nombre' => 'Ivon Cardozo Garzon', 'ci' => '5700899', 'telefono' => '72920177'],
            ['email' => 'sussy_dmedcbj@unitepc.edu.bo', 'sede' => 'COBIJA', 'cargo' => 'JEFE DE DEPARTAMENTO', 'carrera' => 'MEDICINA', 'nombre' => 'SUSSY SKARLEN TENORIO VELEZ', 'ci' => '4208007', 'telefono' => '74752791'],
            ['email' => 'remysejas@unitepc.edu.bo', 'sede' => 'COBIJA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => '', 'nombre' => 'REMY ANTONIO SEJAS RALDE', 'ci' => '2394948', 'telefono' => '71112414'],
            ['email' => 'warana_dbyfcbj@unitepc.edu.bo', 'sede' => 'COBIJA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'BIOQUÍMICA Y FARMACIA', 'nombre' => 'Walter Arana Costa', 'ci' => '4208970', 'telefono' => '76108189'],
            
            // COCHABAMBA - Excluded Directors are filtered in loop
            ['email' => 'carlaperez@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'MEDICINA VETERINARIA Y ZOOTECNIA', 'nombre' => 'CARLA YAMIL PÉREZ SÁNCHEZ', 'ci' => '5280067', 'telefono' => '69535203'],
            ['email' => 'rebecapoma@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'NUTRICIÓN Y DIETÉTICA', 'nombre' => 'Rebeca Damaris Poma Halcon', 'ci' => '9123119', 'telefono' => '69772330'],
            ['email' => 'fsejas_dpt@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'PRÓTESIS DENTAL', 'nombre' => 'Francisco Sejas Rocha', 'ci' => '3596001', 'telefono' => '70784811'],
            ['email' => 'yflores_delc@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'INGENIERÍA ELECTRÓNICA', 'nombre' => 'YVER ROLANDO FLORES CALLE', 'ci' => '5932705', 'telefono' => '74349227'],
            ['email' => 'renevera@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'CONTADURÍA PÚBLICA', 'nombre' => 'RENE CESAR VERA CASTELLON', 'ci' => '3748248', 'telefono' => '79768669'],
            ['email' => 'marcelomerida@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'INGENIERÍA COMERCIAL', 'nombre' => 'MARCELO ALBERTO MERIDA CORDOVA', 'ci' => '3592767', 'telefono' => '70711466'],
            ['email' => 'renevera@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'COMPLEMENTARIA DE CONTADURÍA PÚBLICA', 'nombre' => 'RENE CESAR VERA CASTELLON', 'ci' => '3748248', 'telefono' => '79768669'],
            ['email' => 'nohelializarro@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'DERECHO', 'nombre' => 'NOH ELIA LIZARRO ZAPATA', 'ci' => '5518679', 'telefono' => '70351720'],
            ['email' => 'marcelomerida@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'ADMINISTRACIÓN DE EMPRESAS', 'nombre' => 'MARCELO ALBERTO MERIDA CORDOVA', 'ci' => '3592767', 'telefono' => '70711466'],
            ['email' => 'marcelomerida@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'COMPLEMENTARIA DE INGENIERÍA COMERCIAL', 'nombre' => 'MARCELO ALBERTO MERIDA CORDOVA', 'ci' => '3592767', 'telefono' => '70711466'],
            ['email' => 'marcelomerida@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'COMPLEMENTARIA DE ADMINISTRACIÓN DE EMPRESAS', 'nombre' => 'MARCELO ALBERTO MERIDA CORDOVA', 'ci' => '3592767', 'telefono' => '70711466'],
            ['email' => 'vmicordia_dibi@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'INGENIERÍA BIOMÉDICA', 'nombre' => 'Verona Zolmy Vicordis Romero', 'ci' => '6451150', 'telefono' => '72732594'],
            ['email' => 'vcoro_med@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'MEDICINA', 'nombre' => 'Verónica Jannette Coro Mogro', 'ci' => '8798537', 'telefono' => '70302107'],
            ['email' => 'beatrizflores@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'COMUNICACIÓN SOCIAL', 'nombre' => 'BEATRIZ FLORES BALDERRAMA', 'ci' => '3793500', 'telefono' => '70735007'],
            ['email' => 'beatrizflores@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'ARTES Y ESCULTURA', 'nombre' => 'BEATRIZ FLORES BALDERRAMA', 'ci' => '3793500', 'telefono' => '70735007'],
            ['email' => 'enriquegimenez@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'FISIOTERAPIA Y KINESIOLOGÍA', 'nombre' => 'Enrique Gary Jimenez Vignolo', 'ci' => '4068000', 'telefono' => '76979301'],
            ['email' => 'stefan_son@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'INGENIERÍA DE SONIDO', 'nombre' => 'Sergio Martin Terán Gamarra', 'ci' => '3444949', 'telefono' => '79959330'],
            ['email' => 'lrojas120@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'BIOQUÍMICA Y FARMACIA', 'nombre' => 'Lizeth Rojas Panozo', 'ci' => '6415220', 'telefono' => '70742409'],
            ['email' => 'jackelinecejas@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'ENFERMERÍA', 'nombre' => 'Jackeline Judith Sejas Vidaurre', 'ci' => '8011914', 'telefono' => '75480111'],
            ['email' => 'joseclauro@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'INGENIERÍA DE SISTEMAS', 'nombre' => 'Jose James Clauré Ricaldí', 'ci' => '5188558', 'telefono' => '72242424'],
            ['email' => 'carlavidal@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'FONOAUDIOLOGÍA', 'nombre' => 'CARLA VIDAL AGUILAR', 'ci' => '5314656', 'telefono' => '70425756'],
            ['email' => 'manuelcamacho@unitepc.edu.bo', 'sede' => 'COCHABAMBA', 'cargo' => 'JEFE DE DEPARTAMENTO', 'carrera' => '', 'nombre' => 'MANUEL CAMACHO ARCE', 'ci' => '6420033', 'telefono' => '70347440'],

            // EL ALTO 
            ['email' => 'acaceres_vrcral@unitepc.edu.bo', 'sede' => 'EL ALTO', 'cargo' => 'VICERRECTOR', 'carrera' => '', 'nombre' => 'Amilcar Bruno Caceres Perez', 'ci' => '3756361', 'telefono' => '71411450'],
            ['email' => 'eddaquispe@unitepc.edu.bo', 'sede' => 'EL ALTO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'MEDICINA VETERINARIA Y ZOOTECNIA', 'nombre' => 'EDDA JANNETH QUISPE DE MEDINA', 'ci' => '3217705', 'telefono' => '60151579'],
            ['email' => 'vladimircruz@unitepc.edu.bo', 'sede' => 'EL ALTO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'FISIOTERAPIA Y KINESIOLOGÍA', 'nombre' => 'Vladimir Cruz Barrenechea', 'ci' => '10924082', 'telefono' => '60503233'],
            ['email' => 'bmitabai_dsoneal@unitepc.edu.bo', 'sede' => 'EL ALTO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'INGENIERÍA DE SONIDO', 'nombre' => 'BILLY JONATAN MITABAI COCARICO', 'ci' => '8442629', 'telefono' => '71270862'],
            ['email' => 'rosachipana@unitepc.edu.bo', 'sede' => 'EL ALTO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'ENFERMERÍA', 'nombre' => 'Rosa Chipana Limachi', 'ci' => '6048471', 'telefono' => '68006395'],
            ['email' => 'freddychambi@unitepc.edu.bo', 'sede' => 'EL ALTO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'COMPLEMENTARIA DE INGENIERÍA COMERCIAL', 'nombre' => 'FREDDY CHAMBI LA YURA', 'ci' => '6027178', 'telefono' => '70103352'],
            ['email' => 'isalazar@unitepc.edu.bo', 'sede' => 'EL ALTO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'DERECHO', 'nombre' => 'Ivan Rodrigo Salazar Illanes', 'ci' => '4828745', 'telefono' => '70580847'],
            ['email' => 'aisarabia_dcpcp@unitepc.edu.bo', 'sede' => 'EL ALTO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'COMPLEMENTARIA DE CONTADURÍA PÚBLICA', 'nombre' => 'AITAD SARABIA SUAREZ CHIGUANTO', 'ci' => '8289440', 'telefono' => '60708907'],
            ['email' => 'aisarabia_dcae@unitepc.edu.bo', 'sede' => 'EL ALTO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'COMPLEMENTARIA DE ADMINISTRACIÓN DE EMPRESAS', 'nombre' => 'AITAD SARABIA SUAREZ CHIGUANTO', 'ci' => '8289440', 'telefono' => '60708907'],
            ['email' => 'bmitabai_dbioeal@unitepc.edu.bo', 'sede' => 'EL ALTO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'INGENIERÍA BIOMÉDICA', 'nombre' => 'BILLY JONATAN MITABAI COCARICO', 'ci' => '8442629', 'telefono' => '71270862'],
            ['email' => 'varispe_dmedeal@unitepc.edu.bo', 'sede' => 'EL ALTO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'MEDICINA', 'nombre' => 'Vania Miriam Arispe Ramos', 'ci' => '12724086', 'telefono' => '72000236'],

            // GUAYARAMERIN
            ['email' => 'migueltapia@unitepc.edu.bo', 'sede' => 'GUAYARAMERIN', 'cargo' => 'DIRECTOR ACADEMICO', 'carrera' => 'INGENIERÍA COMERCIAL', 'nombre' => 'Miguel Angel Tapia Quiroz', 'ci' => '3764540', 'telefono' => '70394209'],
            ['email' => 'rangulo_dmedgua@unitepc.edu.bo', 'sede' => 'GUAYARAMERIN', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'MEDICINA', 'nombre' => 'RAMIRO ALEJANDRO ANGULO ROJAS', 'ci' => '8795749', 'telefono' => '68584121'],
            ['email' => 'klozada_dbyfgua@unitepc.edu.bo', 'sede' => 'GUAYARAMERIN', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'BIOQUÍMICA Y FARMACIA', 'nombre' => 'Karla Paola Lozada Dorado', 'ci' => '7619812', 'telefono' => '72838562'],
            ['email' => 'rarteaga_virgua@unitepc.edu.bo', 'sede' => 'GUAYARAMERIN', 'cargo' => 'VICERRECTOR', 'carrera' => '', 'nombre' => 'Rocio del Carmen Arteaga Suarez', 'ci' => '5594320', 'telefono' => '73948246'],

            // IVIRGARZAMA
            ['email' => 'mgibson_vcivi@unitepc.edu.bo', 'sede' => 'IVIRGARZAMA', 'cargo' => 'VICERRECTOR', 'carrera' => '', 'nombre' => 'MOISES GIBSON VARGAS CRISPIN', 'ci' => '3617363', 'telefono' => '71788007'],
            ['email' => 'lquiroga@unitepc.edu.bo', 'sede' => 'IVIRGARZAMA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'MEDICINA VETERINARIA Y ZOOTECNIA', 'nombre' => 'LUIS CARLOS QUIROGA FERREL', 'ci' => '79299658', 'telefono' => '72719822'],
            ['email' => 'carloselfen@unitepc.edu.bo', 'sede' => 'IVIRGARZAMA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'ENFERMERÍA', 'nombre' => 'Carlos Rodrigo Elfen Ortiz', 'ci' => '5767508', 'telefono' => '71705694'],
            ['email' => 'felipesaravia@unitepc.edu.bo', 'sede' => 'IVIRGARZAMA', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'FACEFA', 'nombre' => 'FELIPE ROLANDO SARAVIA ZEBALLOS', 'ci' => '7273002', 'telefono' => '70430163'],
            ['email' => 'javierfelipe@unitepc.edu.bo', 'sede' => 'IVIRGARZAMA', 'cargo' => 'DIRECTOR ACADEMICO', 'carrera' => 'INGENIERÍA DE SISTEMAS', 'nombre' => 'Javier Felipe Mamani', 'ci' => '7928544', 'telefono' => '70760469'],
            
            // LA PAZ
            ['email' => 'juanbernal@unitepc.edu.bo', 'sede' => 'LA PAZ', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'MEDICINA', 'nombre' => 'JUAN MANUEL BERNAL MENDOZA', 'ci' => '4900111', 'telefono' => '70533996'],
            ['email' => 'pedrobeltran@unitepc.edu.bo', 'sede' => 'LA PAZ', 'cargo' => 'DIRECTOR ACADEMICO', 'carrera' => '', 'nombre' => 'PEDRO ANTONIO BELTRAN GUZMAN', 'ci' => '5232715', 'telefono' => '70306017'],
            ['email' => 'ginaosa@unitepc.edu.bo', 'sede' => 'LA PAZ', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'ODONTOLOGÍA', 'nombre' => 'GINA IVON OÑA MEZZA', 'ci' => '4893591', 'telefono' => '67041406'],
            ['email' => 'gustavotarqui@unitepc.edu.bo', 'sede' => 'LA PAZ', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'COMPLEMENTARIA DE ADMINISTRACIÓN DE EMPRESAS', 'nombre' => 'Gustavo Tarqui Mariaca', 'ci' => '3487243', 'telefono' => '72549293'],
            ['email' => 'gustavotarqui@unitepc.edu.bo', 'sede' => 'LA PAZ', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'COMPLEMENTARIA DE CONTADURÍA PÚBLICA', 'nombre' => 'Gustavo Tarqui Mariaca', 'ci' => '3487243', 'telefono' => '72549293'],
            ['email' => 'josesalinas@unitepc.edu.bo', 'sede' => 'LA PAZ', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'INGENIERÍA DE SONIDO', 'nombre' => 'José Benjamín Salinas Vega Pereyra', 'ci' => '4811080', 'telefono' => '70761465'],
            ['email' => 'gustavotarqui@unitepc.edu.bo', 'sede' => 'LA PAZ', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'COMPLEMENTARIA DE INGENIERÍA COMERCIAL', 'nombre' => 'Gustavo Tarqui Mariaca', 'ci' => '3487243', 'telefono' => '72549293'],
            ['email' => 'mireacordero@unitepc.edu.bo', 'sede' => 'LA PAZ', 'cargo' => 'VICERRECTOR', 'carrera' => '', 'nombre' => 'Mirea Amparo Cordero Altamirano', 'ci' => '2217369', 'telefono' => '70164370'],

            // PUERTO QUIJARRO
            ['email' => 'juabeliavaldivia@unitepc.edu.bo', 'sede' => 'PUERTO QUIJARRO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'DERECHO', 'nombre' => 'JUABELIA VALDIVIA DE ARAOZ', 'ci' => '7799394', 'telefono' => '74679514'],
            ['email' => 'rvega_diracadpto@unitepc.edu.bo', 'sede' => 'PUERTO QUIJARRO', 'cargo' => 'DIRECTOR ACADEMICO', 'carrera' => '', 'nombre' => 'ROLANDO ARTURO VEGA PORTALES', 'ci' => '11642662', 'telefono' => '72893200'],
            ['email' => 'carlosrivas@unitepc.edu.bo', 'sede' => 'PUERTO QUIJARRO', 'cargo' => 'VICERRECTOR', 'carrera' => '', 'nombre' => 'Carlos Rivas Moreno', 'ci' => '4417909', 'telefono' => '72180019'],
            ['email' => 'ptordoya_disispto@unitepc.edu.bo', 'sede' => 'PUERTO QUIJARRO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'INGENIERÍA DE SISTEMAS', 'nombre' => 'PABLO REYNALDO TORDOYA SALVATIERRA', 'ci' => '7866792', 'telefono' => '62696001'],
            ['email' => 'dguerero_medpto@unitepc.edu.bo', 'sede' => 'PUERTO QUIJARRO', 'cargo' => 'JEFE DE DEPARTAMENTO', 'carrera' => 'MEDICINA', 'nombre' => 'DONALD ARTURO GUERRERO GUEVARA', 'ci' => '10756790', 'telefono' => '71753801'],
            ['email' => 'josepedriel@unitepc.edu.bo', 'sede' => 'PUERTO QUIJARRO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'MEDICINA', 'nombre' => 'JOSÉ ALFREDO PEDRIEL MAKOSKY', 'ci' => '12855243', 'telefono' => '68766347'],
            ['email' => 'roxanautoja@unitepc.edu.bo', 'sede' => 'PUERTO QUIJARRO', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'BIOQUÍMICA Y FARMACIA', 'nombre' => 'ROXANA YANETH UNTOJA VILLCA', 'ci' => '4154256', 'telefono' => '68765296'],

            // SANTA CRUZ
            ['email' => 'richardvargas@unitepc.edu.bo', 'sede' => 'SANTA CRUZ', 'cargo' => 'VICERRECTOR', 'carrera' => 'ENFERMERÍA', 'nombre' => 'Mario Richard Vargas Dominguez', 'ci' => '3190981', 'telefono' => '70042218'],
            ['email' => 'andresrivera@unitepc.edu.bo', 'sede' => 'SANTA CRUZ', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'MEDICINA', 'nombre' => 'Andres Valentin Rivera Torres', 'ci' => '7822664', 'telefono' => '60041012'],
            ['email' => 'edsonjimenez@unitepc.edu.bo', 'sede' => 'SANTA CRUZ', 'cargo' => 'DIRECTOR DE CARRERA', 'carrera' => 'FONOAUDIOLOGÍA', 'nombre' => 'Edson Trifon Jimenez Encinas', 'ci' => '9213175', 'telefono' => '60126852'],
        ];

        
        $report = [];

        foreach ($usersData as $userData) {
            $nombre = trim($userData['nombre']);
            $apellido = '';
            
            $parts = explode(' ', $nombre);
            if (count($parts) > 2) {
                 $apellido = array_pop($parts);
                 $apellido = array_pop($parts) . ' ' . $apellido;
                 $nombre = implode(' ', $parts);
            } elseif (count($parts) == 2) {
                $apellido = array_pop($parts);
                $nombre = implode(' ', $parts);
            } else {
                $apellido = ' '; 
                $nombre = $userData['nombre'];
            }

            $ci = preg_replace('/\D/', '', $userData['ci']);
            if (empty($ci)) continue;

            $roleName = strtoupper(trim($userData['cargo']));
            $rolId = 6; 
            
            if ($roleName == 'VICERRECTOR') {
                 $rolId = 8; 
            } elseif ($roleName == 'DIRECTOR ACADEMICO') {
                 $rolId = 4;
            } elseif (str_contains($roleName, 'DIRECTOR DE CARRERA') || str_contains($roleName, 'JEFE DE DEPARTAMENTO')) {
                 $rolId = 5; 
            }

            $sedeName = trim($userData['sede']);
            $sedeNameLookup = match(strtoupper($sedeName)) {
                'COBIJA' => 'Cobija',
                'COCHABAMBA' => 'Cochabamba', 
                'EL ALTO' => 'El Alto',
                'GUAYARAMERIN' => 'Guayaramerin',
                'IVIRGARZAMA' => 'Ivirgarzama',
                'LA PAZ' => 'La Paz',
                'PUERTO QUIJARRO' => 'Puerto Quijarro',
                'SANTA CRUZ' => 'Santa Cruz',
                default => $sedeName
            };

            $sede = Sede::where('nombre', 'LIKE', "%{$sedeNameLookup}%")->first();
            
            if (!$sede) {
                 $sede = Sede::create([
                     'nombre' => $sedeNameLookup, 
                     'codigo' => strtoupper(substr($sedeNameLookup, 0, 3)), 
                     'ciudad' => $sedeNameLookup,
                     'activo' => true
                 ]);
            }

             if ($sede->nombre == 'Cochabamba' && ($rolId == 5 || $rolId == 4)) {
                 if (str_contains($roleName, 'DIRECTOR DE CARRERA')) {
                     continue;
                 }
             }

            $username = $ci;
            $password = $ci; 
            
            $user = User::where('ci', $ci)->first();
            
            if ($user && $user->rol_id != $rolId) {
                $username = $ci . '1';
                $password = $ci . '1';
                
                $userVersion1 = User::where('username', $username)->first();
                
                if (!$userVersion1) {
                    $email = $userData['email'];
                    // Ensure email unique
                    if (User::where('email', $email)->exists()) {
                        // Append 1 to email user part
                        $parts = explode('@', $email);
                        $email = $parts[0] . '1@' . $parts[1];
                    }
                    
                     $user = User::create([
                        'username' => $username,
                        'email' => $email,
                        'password' => Hash::make($password),
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'ci' => $username, 
                        'telefono' => $userData['telefono'],
                        'sede_id' => $sede->id,
                        'rol_id' => $rolId,
                        'estado' => true
                    ]);
                } else {
                    $user = $userVersion1;
                }
            } elseif (!$user) {
                $email = $userData['email'];
                 // Ensure email unique (in case same email used for different CI? unlikely in this list but safe to check)
                 if (User::where('email', $email)->exists()) {
                     // Check if it belongs to someone else
                     $existing = User::where('email', $email)->first();
                     if ($existing->ci != $ci) {
                         $parts = explode('@', $email);
                         $email = $parts[0] . '1@' . $parts[1];
                     } else {
                         // Same user, so we found user by email but not by CI? 
                         // That means DB has email but wrong CI? Or something?
                         // Just use checking email.
                         $user = $existing; // Treat as found
                     }
                 }
                
                if (!$user) {
                    $user = User::create([
                        'username' => $username,
                        'email' => $email,
                        'password' => Hash::make($password),
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'ci' => $ci,
                        'telefono' => $userData['telefono'],
                        'sede_id' => $sede->id,
                        'rol_id' => $rolId,
                        'estado' => true
                    ]);
                }
            }
            
            $roleNameStr = $user->rol ? $user->rol->nombre : 'N/A';
             $report[] = [
                'Nombre' => $user->nombre . ' ' . $user->apellido,
                'Sede' => $sede->nombre,
                'Rol' => $roleNameStr,
                'CI/Usuario' => $user->username,
                'Password' => $user->username
            ];
            
            if (($rolId == 5 || $rolId == 4) && !empty($userData['carrera'])) {
                $carreraName = trim($userData['carrera']);
                
                // 1. Try Exact Match in Sede
                $carrera = Carrera::where('nombre', $carreraName)
                                   ->whereHas('sedes', function($q) use ($sede) {
                                       $q->where('sedes.id', $sede->id);
                                   })->first();

                // 2. Try Exact Match (Global - maybe not linked to Sede yet)
                if (!$carrera) {
                     $carrera = Carrera::where('nombre', $carreraName)->first();
                }

                // 3. Try LIKE (Fuzzy) - CAREFUL with "Medicina" matching "Medicina Veterinaria"
                if (!$carrera) {
                     // Verify it's not a generic name matching a longer specific name incorrectly
                     // We can try to match "starts with" or ensure strict containment?
                     // For now, let's stick to strict first. If strict fails, try LIKE but log warning?
                     // The user issue was specifically Medicina matching Medicina Veterinaria.
                     // Making the LIKE query more restrictive or skipping it avoids the error?
                     // Let's try LIKE but exclude if the name is significantly longer?
                     // Or just rely on the fact that the input list names seem to match DB names closely usually.
                     // The error happened because 'MEDICINA' is a substring of 'MEDICINA VETERINARIA'.
                     // Let's NOT use % wildcards around standard lookups if strict failed, unless necessary.
                     // Or use 'LIKE name' (no wildcards) which is same as equals.
                     // Let's try 'LIKE %name%' ONLY if the string is long enough?
                     
                     // Fallback: Try valid LIKE but order by length (ascending) to get shortest match? 
                     // e.g. "MEDICINA" matches "MEDICINA" (len 8) and "MEDICINA VET" (len 12). Shortest is likely correct?
                     $carrera = Carrera::where('nombre', 'LIKE', "%{$carreraName}%")
                                        ->orderByRaw('LENGTH(nombre) ASC')
                                        ->first();
                }
                 
                 if ($carrera) {
                     // Check if Director record exists for this user
                     $director = Director::where('user_id', $user->id)->first();
                     
                     if (!$director) {
                         $director = Director::create([
                             'user_id' => $user->id,
                             'carrera_id' => $carrera->id, // Set primary/first carrera
                             'sede_id' => $sede->id,
                             'nombres' => $user->nombre,
                             'apellidos' => $user->apellido,
                             'titulo' => 'Lic.',
                         ]);
                     } else {
                        // Director exists.
                        // We should ensure this director record is valid.
                        // If the existing record points to a WRONG carrera (e.g. Veterinaria), we definitely want to update it to the correct one (Medicina).
                        // How to know which is "correct"? The current $userData['carrera'] is what we are processing.
                        // So we should update 'carrera_id' to $carrera->id?
                        // But what if they have 2 valid careers?
                        // If we update, we overwrite the previous one.
                        // Given the previous error (Veterinaria assigned to Medicina person), updating IS the fix.
                        // For the multi-career person (Gustavo Tarqui), he will end up with ONE of them in the director table, but BOTH in the carreras table (linked via director_id).
                        // This accepts the limitation of the directors table having only 1 carrera_id.
                        $director->update([
                            'carrera_id' => $carrera->id,
                            'sede_id' => $sede->id,
                        ]);
                     }
                     
                     $carrera->director_id = $director->id; 
                     $carrera->save();
                 } else {
                     $this->command->warn("Carrera not found for user {$userData['email']}: {$carreraName}");
                 }
            }
        }
        
         $this->command->table(
            ['Nombre', 'Sede', 'Rol', 'CI/Usuario', 'Password'],
            array_unique($report, SORT_REGULAR)
        );
    }
}
