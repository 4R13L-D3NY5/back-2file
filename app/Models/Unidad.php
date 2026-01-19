<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unidad extends Model
{
    protected $table = 'unidades';

    protected $fillable = ['numero', 'titulo', 'objetivo', 'contenido_minimo', 'asignatura_id', 'elemento_competencia'];

    public function asignatura(): BelongsTo
    {
        return $this->belongsTo(Asignatura::class);
    }

    public function temas(): HasMany
    {
        return $this->hasMany(Tema::class);
    }
}
