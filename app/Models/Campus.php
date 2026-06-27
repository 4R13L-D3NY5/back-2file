<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campus extends Model
{
    use HasFactory;

    protected $table = 'campus';

    protected $fillable = [
        'nombre',
        'sede_id',
        'direccion',
        'activo'
    ];

    /**
     * Obtener la sede a la que pertenece el campus.
     */
    public function sede()
    {
        return $this->belongsTo(Sede::class);
    }

    public function carreras()
    {
        return $this->belongsToMany(Carrera::class, 'campus_carrera');
    }

    public function evaluadores()
    {
        return $this->belongsToMany(User::class, 'campus_user')->withTimestamps();
    }
}
