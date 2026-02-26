<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Auditoria extends Model
{
    protected $fillable = [
        'asignatura_id',
        'docente_id',
        'auditor_id',
        'semana',
        'tipo',
        'criterios',
        'observaciones',
        'acciones_correctivas',
        'semaforo',
    ];

    protected $casts = [
        'criterios' => 'array',
    ];

    public function asignatura()
    {
        return $this->belongsTo(Asignatura::class);
    }

    public function docente()
    {
        return $this->belongsTo(Docente::class);
    }

    public function auditor()
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }
}
