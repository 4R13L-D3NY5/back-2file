<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MallaCurricularController extends Controller
{
    /**
     * GET /api/mallas-curriculares?sede_id=1&carrera_id=5&plan_estudios=N
     *
     * Consulta directamente desde asignaturas + asignatura_carrera (pivot).
     * Ya no depende de asignaturas_oficiales.
     */
    public function getMallas(Request $request)
    {
        $sedeId     = $request->input('sede_id');
        $carreraId  = $request->input('carrera_id');
        $planFiltro = $request->input('plan_estudios'); // null = todos

        if (!$sedeId || !$carreraId) {
            return response()->json(['success' => false, 'message' => 'sede_id y carrera_id son requeridos'], 422);
        }

        // Obtener asignaturas vinculadas a esta sede+carrera via el pivot asignatura_carrera
        $rows = DB::table('asignaturas')
            ->join('asignatura_carrera', 'asignatura_carrera.asignatura_id', '=', 'asignaturas.id')
            ->where('asignatura_carrera.sede_id', $sedeId)
            ->where('asignatura_carrera.carrera_id', $carreraId)
            ->where('asignaturas.estado', '!=', 'cancelado')
            ->whereNull('asignaturas.deleted_at')
            ->when($planFiltro, fn($q) => $q->where('asignaturas.plan_estudios', $planFiltro))
            ->orderBy('asignatura_carrera.semestre')
            ->orderBy('asignaturas.codigo')
            ->select([
                'asignaturas.id',
                'asignaturas.codigo',
                'asignaturas.nombre',
                'asignatura_carrera.semestre',
                DB::raw("COALESCE(asignaturas.plan_estudios, 'N') as plan_estudios"),
            ])
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // Lookup docentes: buscamos grupos en esta sede+carrera cuya asignatura_id coincida
        $asignaturaIds = $rows->pluck('id')->toArray();

        $docentesPorId = [];
        DB::table('grupos')
            ->join('docentes', 'grupos.docente_id', '=', 'docentes.id')
            ->where('grupos.sede_id', $sedeId)
            ->where('grupos.carrera_id', $carreraId)
            ->whereIn('grupos.asignatura_id', $asignaturaIds)
            ->whereNotNull('grupos.docente_id')
            ->whereNull('grupos.deleted_at')
            ->where('grupos.estado', 'ACTIVO')
            ->select('grupos.asignatura_id', 'docentes.nombre_completo as docente')
            ->get()
            ->each(function ($row) use (&$docentesPorId) {
                $id = $row->asignatura_id;
                if (!isset($docentesPorId[$id])) {
                    $docentesPorId[$id] = [];
                }
                if (!in_array($row->docente, $docentesPorId[$id])) {
                    $docentesPorId[$id][] = $row->docente;
                }
            });

        // Agrupar por plan
        $grouped = [];
        foreach ($rows as $r) {
            $planKey = ($r->plan_estudios === 'A') ? 'Plan Antiguo (A)' : 'Plan Nuevo (N)';
            $grouped[$planKey][] = [
                'id'           => $r->id,
                'codigo'       => $r->codigo,
                'nombre'       => $r->nombre,
                'semestre'     => $r->semestre,
                'plan_estudios'=> $r->plan_estudios,
                'docentes'     => isset($docentesPorId[$r->id])
                    ? implode(', ', $docentesPorId[$r->id])
                    : null,
            ];
        }

        // Ordenar: Plan N primero
        $ordered = [];
        if (isset($grouped['Plan Nuevo (N)']))   $ordered['Plan Nuevo (N)']   = $grouped['Plan Nuevo (N)'];
        if (isset($grouped['Plan Antiguo (A)'])) $ordered['Plan Antiguo (A)'] = $grouped['Plan Antiguo (A)'];

        return response()->json(['success' => true, 'data' => $ordered]);
    }
}
