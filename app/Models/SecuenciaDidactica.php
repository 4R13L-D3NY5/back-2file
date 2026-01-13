<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecuenciaDidactica extends Model
{
    protected $table = 'secuencias_didacticas';
    protected $fillable = ['momento', 'actividad', 'duracion', 'cronograma_id'];

    public function cronograma(): BelongsTo
    {
        return $this->belongsTo(Cronograma::class);
    }
}
