<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unidad extends Model
{
    protected $table = 'unidades';

    protected $fillable = ['numero', 'titulo', 'objetivo', 'contenido_minimo', 'asignatura_id', 'elemento_competencia', 'tipo'];

    public function asignatura(): BelongsTo
    {
        return $this->belongsTo(Asignatura::class);
    }

    public function temas(): HasMany
    {
        return $this->hasMany(Tema::class);
    }

    /**
     * Scope para filtrar unidades según el tipo de grupo (TEORIA/PRACTICA).
     */
    public function scopeForGroup($query, $grupo)
    {
        if (!$grupo) return $query;
        // Si el grupo es TEORIA, mostramos unidades TEORIA o NULL (comunes)
        // Si el grupo es PRACTICA, mostramos unidades PRACTICA o NULL (comunes)
        return $query->where(function ($q) use ($grupo) {
            $q->where('tipo', $grupo->tipo)
                ->orWhereNull('tipo');
        });
    }
}
