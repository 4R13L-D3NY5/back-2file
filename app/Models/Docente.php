<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Docente extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nombre_completo',
        'ci',
        'celular',
        'formacion',
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

    /**
     * Grupos en los que enseña este docente
     */
    public function grupos()
    {
        return $this->hasMany(Grupo::class);
    }

    /**
     * Asignaturas que enseña (a través de grupos)
     */
    public function asignaturas()
    {
        return $this->hasManyThrough(
            Asignatura::class,
            Grupo::class,
            'docente_id',    // FK en grupos
            'id',            // FK en asignaturas
            'id',            // PK en docentes
            'asignatura_id'  // Local key en grupos
        )->distinct();
    }
}
