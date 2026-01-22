<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Aula extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'nombre',
        'bloque_id',
        'capacidad',
        'pupitres'
    ];

    public function bloque()
    {
        return $this->belongsTo(Bloque::class);
    }

    public function horarios()
    {
        return $this->hasMany(Horario::class);
    }
}
