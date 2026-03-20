<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvaluacionConfiguracion extends Model
{
    use HasFactory;

    protected $table = 'evaluacion_configuraciones';

    protected $fillable = [
        'nivel',
        'sede_id',
        'carrera_id',
        'configuracion'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'configuracion' => 'array',
    ];

    /**
     * Resuelve la jerarquía de configuraciones: Carrera > Sede > Nacional
     * Devolviendo la configuración más específica disponible.
     * 
     * @param int|null $sedeId
     * @param int|null $carreraId
     * @return array|null 
     */
    public static function obtenerConfiguracionEfectiva($sedeId, $carreraId)
    {
        // 1. Prioridad: Carrera
        if ($carreraId) {
            $configCarrera = self::where('nivel', 'carrera')
                                 ->where('carrera_id', $carreraId)
                                 ->first();
            if ($configCarrera) {
                $data = $configCarrera->configuracion;
                $data['_origen'] = 'carrera';
                return $data;
            }
        }

        // 2. Prioridad: Sede
        if ($sedeId) {
            $configSede = self::where('nivel', 'sede')
                              ->where('sede_id', $sedeId)
                              ->first();
            if ($configSede) {
                $data = $configSede->configuracion;
                $data['_origen'] = 'sede';
                return $data;
            }
        }

        // 3. Fallback: Nacional
        $configNacional = self::where('nivel', 'nacional')->first();
        if ($configNacional) {
            $data = $configNacional->configuracion;
            $data['_origen'] = 'nacional';
            return $data;
        }

        return null; // En caso de que no exista ni siquiera nacional
    }
}
