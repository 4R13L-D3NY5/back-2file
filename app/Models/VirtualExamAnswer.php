<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VirtualExamAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'virtual_exam_attempt_id',
        'numero_pregunta',
        'banco_pregunta_id',
        'respuesta_marcada',
        'respuesta_correcta',
        'es_correcta',
    ];

    protected $casts = [
        'numero_pregunta' => 'integer',
        'respuesta_marcada' => 'array',
        'respuesta_correcta' => 'array',
        'es_correcta' => 'boolean',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(VirtualExamAttempt::class, 'virtual_exam_attempt_id');
    }
}
