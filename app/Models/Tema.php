<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tema extends Model
{
    use HasFactory;

    protected $fillable = [
        'titulo',
        // 'descripcion', // removed column
        'contenido_items', // Array de items de contenido
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
        'contenido_items' => 'array',
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

    public function planificacionesPersonales()
    {
        return $this->hasMany(PlanificacionPersonal::class);
    }

    public function cronogramas()
    {
        return $this->belongsToMany(Cronograma::class, 'cronograma_tema');
    }

    public function scopeForGroup($query, $grupo)
    {
        if (!$grupo) return $query;
        return $query->where(function ($q) use ($grupo) {
            $q->where('tipo', $grupo->tipo)
                ->orWhereNull('tipo');
        });
    }

    /**
     * Obtiene o crea la planificación personal para un usuario
     * Si no existe, copia las secuencias de la plantilla general
     */
    public function getPlanificacionPersonalParaUsuario($userId)
    {
        $planificacion = PlanificacionPersonal::firstOrNew([
            'tema_id' => $this->id,
            'user_id' => $userId
        ]);

        // Si es nueva y no tiene secuencias, copiar de la plantilla
        if (!$planificacion->exists && $this->secuencias->isNotEmpty()) {
            $planificacion->secuencia_didactica = $this->secuencias->map(function ($sec) {
                return [
                    'momento' => $sec->momento,
                    'descripcion' => $sec->descripcion,
                    'duracion_minutos' => $sec->duracion_minutos
                ];
            })->toArray();
        }

        return $planificacion;
    }
}
