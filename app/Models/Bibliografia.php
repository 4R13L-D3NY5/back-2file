<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bibliografia extends Model
{
    protected $fillable = [
        'titulo', 'autor', 'editorial', 'edicion', 'anio', 'tipo', 'isbn', 'paginas', 'asignatura_id'
    ];

    public function asignatura(): BelongsTo
    {
        return $this->belongsTo(Asignatura::class);
    }
}
