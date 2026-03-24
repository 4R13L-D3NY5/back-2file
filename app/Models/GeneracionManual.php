<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GeneracionManual extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'generaciones_manuales';

    protected $fillable = [
        'user_id',
        'sede_id',
        'carrera_id',
        'asignatura_id',
        'docente_id',
        'sede_nombre',
        'carrera_nombre',
        'asignatura_nombre',
        'docente_nombre',
        'parcial',
        'gestion',
        'grupo',
        'hora',
        'cant_variantes',
        'fecha_examen',
        'motivo',
        'estado',
        'patron_respuestas_json',
        'configuracion_json',
        'archivo_examen',
        'archivo_patron_pdf',
        'archivos_patron_xlsx',
    ];

    protected $casts = [
        'fecha_examen' => 'date',
        'patron_respuestas_json' => 'array',
        'configuracion_json' => 'array',
        'cant_variantes' => 'integer',
        'archivos_patron_xlsx' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sede()
    {
        return $this->belongsTo(Sede::class);
    }

    public function carrera()
    {
        return $this->belongsTo(Carrera::class);
    }

    public function asignatura()
    {
        return $this->belongsTo(Asignatura::class);
    }

    public function docente()
    {
        return $this->belongsTo(Docente::class);
    }
}
