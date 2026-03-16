<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InformeSemanal extends Model
{
    use HasFactory;
    
    protected $table = 'informe_semanals';

    protected $fillable = [
        'grupo_id',
        'docente_id',
        'semana_inicio',
        'semana_fin',
        'criterios',
        'observaciones',
        'escala_alerta',
        'cumplimiento_porcentaje',
        'created_by',
        'es_propagado',
        'propagado_de_id'
    ];

    protected $casts = [
        'criterios' => 'array',
        'semana_inicio' => 'date',
        'semana_fin' => 'date',
        'es_propagado' => 'boolean',
    ];

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(Docente::class);
    }
    
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function propagadoDe(): BelongsTo
    {
        return $this->belongsTo(InformeSemanal::class, 'propagado_de_id');
    }

    public function propagados(): HasMany
    {
        return $this->hasMany(InformeSemanal::class, 'propagado_de_id');
    }
}
