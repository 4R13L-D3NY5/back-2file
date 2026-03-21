<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Horario extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'id_horario_api',
        'grupo_id',
        'aula_id',
        'dia',
        'hora_inicio',
        'hora_fin',
        'modificado_localmente',
    ];

    public function grupo()
    {
        return $this->belongsTo(Grupo::class);
    }

    public function aula()
    {
        return $this->belongsTo(Aula::class);
    }
}
