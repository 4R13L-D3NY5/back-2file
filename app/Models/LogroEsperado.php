<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LogroEsperado extends Model
{
    use HasFactory;

    protected $table = 'logros_esperados';

    protected $fillable = [
        'descripcion',
        'tipo_logro',
        'periodo', // New field
        'tema_id'
    ];

    public function tema()
    {
        return $this->belongsTo(Tema::class);
    }

    public function indicadores()
    {
        return $this->hasMany(Indicador::class);
    }

    public function bancoPreguntas()
    {
        return $this->hasMany(BancoPregunta::class);
    }
}
