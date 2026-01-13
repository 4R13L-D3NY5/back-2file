<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evaluacion extends Model
{
    protected $table = 'evaluaciones';
    protected $fillable = ['tipo', 'actividades', 'instrumentos', 'evidencias', 'cronograma_id'];

    public function cronograma(): BelongsTo
    {
        return $this->belongsTo(Cronograma::class);
    }
}
