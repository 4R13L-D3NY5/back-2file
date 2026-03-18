<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mapeo confirmado: nombre en BD → sigla API Planning
     * Solo carreras de Cochabamba (sede_id = 1 o pertenecen a sede 1)
     */
    private array $mapeo = [
        'COMPLEMENTARIA CONTADURÍA PÚBLICA'              => 'CARCCP',
        'LICENCIATURA EN ARTE Y ESCULTURA'               => 'CARAYE',
        'LICENCIATURA EN NUTRICIÓN Y DIETÉTICA'          => 'CARNYD',
        'LICENCIATURA EN ODONTOLOGÍA'                    => 'CARODO',
        'LICENCIATURA EN FISIOTERAPIA Y KINESIOLOGÍA'    => 'CARFIS',
        'LICENCIATURA EN FONOAUDIOLOGIA'                 => 'CARFON',
        'LICENCIATURA EN ENFERMERÍA'                     => 'CARENL',
        'LICENCIATURA EN INGENIERÍA DE SONIDO'           => 'CARSON',
        'LICENCIATURA EN INGENIERÍA COMERCIAL'           => 'CARICO',
        'LICENCIATURA EN MEDICINA'                       => 'CARMED',
        'COMPLEMENTARIA EN ADMINISTRACIÓN DE EMPRESAS'   => 'CARCAD',
        'LICENCIATURA EN CONTADURÍA PÚBLICA'             => 'CARCPU',
        'LICENCIATURA EN ECONOMÍA'                       => 'CARECO',
        'COMPLEMENTARIA INGENIERÍA COMERCIAL'            => 'CARCIC',
        'LICENCIATURA EN ADMINISTRACIÓN DE EMPRESAS'     => 'CARADM',
        'LICENCIATURA EN COMUNICACIÓN SOCIAL'            => 'CARCSO',
        'LICENCIATURA EN CINEMATOGRAFÍA'                 => 'CARCNE',
        'LICENCIATURA EN DERECHO'                        => 'CARDER',
        'LICENCIATURA EN INGENIERÍA ELECTRONICA'         => 'CARELE',
        'LICENCIATURA EN INGENIERÍA DE SISTEMAS'         => 'CARSIS',
        'LICENCIATURA EN INGENIERÍA BIOMÉDICA'           => 'CARIBI',
        'LICENCIATURA EN MEDICINA VETERINARIA Y ZOOTECNIA' => 'CARVET',
        'LICENCIATURA EN BIOQUÍMICA Y FARMACIA'          => 'CARBYF',
        'PROTESIS DENTAL'                                => 'CARPRO',
    ];

    public function up(): void
    {
        foreach ($this->mapeo as $nombre => $sigla) {
            DB::table('carreras')
                ->where('nombre', $nombre)
                ->update(['codigo' => strtolower($sigla)]);
        }
    }

    public function down(): void
    {
        // Revertir solo las que no tenían código previo
        // (carenl y carsis ya tenían, las demás se ponen a null)
        $conCodigoPrevio = ['carenl', 'carsis'];

        foreach ($this->mapeo as $nombre => $sigla) {
            if (!in_array(strtolower($sigla), $conCodigoPrevio)) {
                DB::table('carreras')
                    ->where('nombre', $nombre)
                    ->update(['codigo' => null]);
            }
        }
    }
};
