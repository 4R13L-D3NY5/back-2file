<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanningSyncLog extends Model
{
    protected $table = 'planning_sync_log';

    protected $fillable = [
        'gestion',
        'sede_api_id',
        'carrera_codigo',
        'total_registros',
        'total_carreras',
        'user_id',
        'status',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'sede_api_id' => 'integer',
        'total_registros' => 'integer',
        'total_carreras' => 'integer',
        'user_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
