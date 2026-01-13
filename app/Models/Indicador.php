<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Indicador extends Model
{
    use HasFactory;

    protected $fillable = [
        'descripcion',
        'logro_esperado_id'
    ];

    public function logro()
    {
        return $this->belongsTo(LogroEsperado::class, 'logro_esperado_id');
    }
}
