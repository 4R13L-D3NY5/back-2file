<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VirtualExamSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'rol_examen_id',
        'public_token',
        'estado',
        'cantidad_variantes',
        'duracion_minutos',
        'generado_en',
        'iniciado_en',
        'finaliza_en',
        'cerrado_en',
        'generado_por',
        'iniciado_por',
        'configuracion',
    ];

    protected $casts = [
        'cantidad_variantes' => 'integer',
        'duracion_minutos' => 'integer',
        'generado_en' => 'datetime',
        'iniciado_en' => 'datetime',
        'finaliza_en' => 'datetime',
        'cerrado_en' => 'datetime',
        'configuracion' => 'array',
    ];

    public function rolExamen(): BelongsTo
    {
        return $this->belongsTo(RolExamen::class);
    }

    public function roster(): HasMany
    {
        return $this->hasMany(VirtualExamRoster::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(VirtualExamAttempt::class);
    }
}
