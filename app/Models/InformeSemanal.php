<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'created_by'
    ];

    protected $casts = [
        'criterios' => 'array',
        'semana_inicio' => 'date',
        'semana_fin' => 'date',
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
}
