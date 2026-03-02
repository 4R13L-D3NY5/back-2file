<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Seguimiento extends Model
{
    protected $fillable = [
        'cronograma_id',
        'grupo_id',
        'user_id',
        'fecha',
        'cumplido',
        'tema_cumplido',
        'estado_cumplimiento',
        'observaciones',
        'pedagogico',
        'es_examen',
        'tipo_examen',
        'georeferencia',
        'evidencias',
        'integracion_transversal',
    ];

    protected $casts = [
        'pedagogico' => 'array',
        'evidencias' => 'array',
        'integracion_transversal' => 'array',
        'georeferencia' => 'array',
        'cumplido' => 'boolean',
        'tema_cumplido' => 'boolean',
        'es_examen' => 'boolean',
        'fecha' => 'date',
    ];

    public function cronograma(): BelongsTo
    {
        return $this->belongsTo(Cronograma::class);
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
