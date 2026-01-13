<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstrategiaDidactica extends Model
{
    protected $table = 'estrategias_didacticas';
    protected $fillable = ['metodologicas_docente', 'aprendizaje_estudiante', 'recursos', 'cronograma_id'];

    public function cronograma(): BelongsTo
    {
        return $this->belongsTo(Cronograma::class);
    }
}
