<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VirtualExamRoster extends Model
{
    use HasFactory;

    protected $fillable = [
        'virtual_exam_session_id',
        'codigo_estudiante',
        'nombre_estudiante',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(VirtualExamSession::class, 'virtual_exam_session_id');
    }

    public function attempt(): HasOne
    {
        return $this->hasOne(VirtualExamAttempt::class, 'virtual_exam_roster_id');
    }
}
