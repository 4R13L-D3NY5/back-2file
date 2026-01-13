<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tema extends Model
{
    use HasFactory;

    protected $fillable = [
        'titulo',
        'horas_practicas',
        'horas_teoricas',
        'unidad_id',
        // Textos ricos y JSONs
        'contenido_conceptual',
        'contenido_procedimental',
        'contenido_actitudinal',
        'estrategias_metodologicas',
        'estrategias_aprendizaje',
        'estrategias_recursos', // cast: array
        'evaluacion_formativa', // cast: array
        'evaluacion_sumativa', // cast: array
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

    // New Relationships (Rich Content)
    public function logreseEperados()
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
}
