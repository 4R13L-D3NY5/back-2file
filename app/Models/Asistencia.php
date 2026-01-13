<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asistencia extends Model
{
    protected $fillable = ['asistio', 'cronograma_id', 'matricula_id'];

    public function cronograma(): BelongsTo
    {
        return $this->belongsTo(Cronograma::class);
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }
}
