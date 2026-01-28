<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvaluacionCronograma extends Model
{
    use HasFactory;

    protected $table = 'evaluaciones_cronograma';

    protected $fillable = [
        'tipo',
        'actividades',
        'instrumentos',
        'evidencias',
        'cronograma_id'
    ];

    public function cronograma()
    {
        return $this->belongsTo(Cronograma::class);
    }
}
