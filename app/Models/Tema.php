<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tema extends Model
{
    use HasFactory;

    protected $fillable = [
        'titulo',
        'orden',
        'resultado_aprendizaje',
        'horas_practicas',
        'horas_teoricas',
        'unidad_id',
        'tipo', // 'TEORIA', 'PRACTICA' or null
        // Textos ricos y JSONs
        'contenido_conceptual',
        'contenido_procedimental',
        'contenido_actitudinal',
        'estrategias_metodologicas',
        'estrategias_aprendizaje',
        'estrategias_recursos', // cast: array
        'evaluacion_formativa', // cast: array
        'evaluacion_sumativa', // cast: array
        'orden', // Agregado para ordenamiento
    ];

    protected $casts = [
        'contenido_conceptual' => 'array',
        'contenido_procedimental' => 'array',
        'contenido_actitudinal' => 'array',
        'estrategias_recursos' => 'array',
        'evaluacion_formativa' => 'array',
        'evaluacion_sumativa' => 'array',
    ];

    public function unidad()
    {
        return $this->belongsTo(Unidad::class);
    }

    public function logros()
    {
        return $this->hasMany(LogroEsperado::class);
    }



    public function secuencias()
    {
        return $this->hasMany(SecuenciaTema::class);
    }

    public function bibliografias()
    {
        return $this->belongsToMany(Bibliografia::class, 'tema_bibliografia')
            ->withPivot(['pagina_desde', 'pagina_hasta'])
            ->withTimestamps();
    }

    public function planificacionPersonal()
    {
        return $this->hasOne(PlanificacionPersonal::class);
    }

    public function scopeForGroup($query, $grupo)
    {
        if (!$grupo) return $query;
        return $query->where(function ($q) use ($grupo) {
            $q->where('tipo', $grupo->tipo)
                ->orWhereNull('tipo');
        });
    }
}
