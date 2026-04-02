<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    protected $fillable = [
        'sede_id',
        'carrera',
        'codigo_asignatura',
        'plan_estudios',
        'gestion',
        'modo',
        'estado',
        'total_registros',
        'docentes_creados',
        'grupos_creados',
        'horarios_actualizados',
        'error_mensaje',
        'diff_data',
        'duracion_segundos',
        'user_id',
    ];

    protected $casts = [
        'total_registros'      => 'integer',
        'docentes_creados'     => 'integer',
        'grupos_creados'       => 'integer',
        'horarios_actualizados'=> 'integer',
        'duracion_segundos'    => 'float',
        'diff_data'            => 'array',
    ];

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
