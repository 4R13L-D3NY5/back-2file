<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsignaturaOficial extends Model
{
    protected $table = 'asignaturas_oficiales';

    protected $fillable = [
        'codigo',
        'nombre',
        'semestre',
        'carrera_id',
        'sede_id',
        'plan_estudios',
    ];
}
