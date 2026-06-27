<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VirtualExamAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'virtual_exam_session_id',
        'virtual_exam_roster_id',
        'codigo_estudiante',
        'nombre_estudiante',
        'variante',
        'estado',
        'access_token',
        'download_token',
        'ingresado_en',
        'finalizado_en',
        'expira_en',
        'respuestas_total',
        'correctas_total',
        'patron_estudiante',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'ingresado_en' => 'datetime',
        'finalizado_en' => 'datetime',
        'expira_en' => 'datetime',
        'respuestas_total' => 'integer',
        'correctas_total' => 'integer',
        'patron_estudiante' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(VirtualExamSession::class, 'virtual_exam_session_id');
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(VirtualExamRoster::class, 'virtual_exam_roster_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(VirtualExamAnswer::class, 'virtual_exam_attempt_id');
    }
}
