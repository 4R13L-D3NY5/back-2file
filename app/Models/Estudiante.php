<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estudiante extends Model
{
    protected $fillable = ['codigo', 'nombres', 'apellidos'];

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }
}
