<?php

namespace App\Http\Controllers;

use App\Models\Docente;
use Illuminate\Http\Request;

class DocenteController extends Controller
{
    public function index(Request $request)
    {
        $query = Docente::query()->with(['grupos.asignatura.carreras', 'grupos.horarios']); // Eager load deep relationships

        if ($request->has('search')) {
            $term = $request->search;
            $query->where('nombre_completo', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        }

        $docentes = $query->orderBy('nombre_completo')->get()->map(function ($docente) {
            // Calcular estadísticas basades en Grupos
            $grupos = $docente->grupos;
            $materiasIds = $grupos->pluck('asignatura_id')->unique();

            // Calcular horas (ej: suma de minutos de horarios / 60)
            // Ojo: Esto es aproximado si no tenemos duración exacta por hora academica
            // Asumiremos que 'horarios' tiene start/end
            // Por simplicidad para el dashboard: Count de grupos * carga horaria asignatura?
            // O mejor: suma de horas de los horarios asignados.
            // Si no hay horarios cargados, usar default o 0.

            // Simplificación: Horas/Sem = Suma de horas de las asignaturas via grupos?
            // O count de grupos.
            // El usuario ve "40 Hrs/Sem".

            // Inferencia de Sede (Tomar del primer grupo)
            $sede = null;
            $firstGrupo = $grupos->first();
            if ($firstGrupo && $firstGrupo->asignatura) {
                // Try via pivot first (most accurate)
                $carrera = $firstGrupo->asignatura->carreras->first();
                if ($carrera) {
                    // We need Sede name. In controller we eager loaded carreras... but we need Sede model.
                    // Let's lazy load/fetch Sede if needed or use ID.
                    // Optimization: Eager load 'grupos.asignatura.carreras.sede' in query above.
                    $sede = $carrera->sede;
                }
            }

            // Si tiene asignada un sede directa en tabla docentes (legacy?), usar esa.
            // Pero el modelo Docente no tiene sede_id segun schema nuevo?
            // User seeder uses 'sede_id' on USER, but Docente is linked to User.
            // Let's stick to inferred from Grupos for "Academic Sede".

            // Merge stats into full model array to preserve all fields (estado, celular, etc.)
            // and relationships (grupos) needed by frontend
            $data = $docente->toArray();

            // Override or append calculated fields
            $data['materias_count'] = $materiasIds->count();
            $data['grupos_count'] = $grupos->count();
            $data['horas_semanales'] = 0; // TODO: Calcular real

            // Inferred Sede Override (if model Sede is empty/null, use inferred)
            if (empty($data['sede']) && $sede) {
                $data['sede'] = ['nombre' => $sede->nombre];
                $data['sede_id'] = $sede->id; // Ensure foreign key match
            }

            return $data;
        });

        return response()->json($docentes);
    }
}
