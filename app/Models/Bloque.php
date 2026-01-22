<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bloque extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['nombre', 'sede_id'];

    public function sede()
    {
        return $this->belongsTo(Sede::class); // Assuming Sede model exists or will exist
    }

    public function aulas()
    {
        return $this->hasMany(Aula::class);
    }
}
