<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Docente extends Model
{
    protected $fillable = [
        'nombre_completo',
        'celular',
        'user_id',
        'email',
        'foto',
        'especialidad',
        'grado_academico',
        'tipo_dedicacion',
        'sede_id',
        'estado'
    ];

    protected $casts = [
        'estado' => 'boolean'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function asignaturas(): BelongsToMany
    {
        return $this->belongsToMany(Asignatura::class, 'asignatura_docente')
            ->withPivot('grupo')
            ->withTimestamps();
    }
}
