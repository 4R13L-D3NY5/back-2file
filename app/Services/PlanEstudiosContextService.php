<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PlanEstudiosContextService
{
    public function resolverPlanes(int $carreraId, int $sedeId, ?string $gestion = null): array
    {
        $gestionesEquivalentes = $this->gestionesEquivalentes($gestion);
        $queryGrupos = DB::table('grupos as g')
            ->join('asignaturas as a', 'a.id', '=', 'g.asignatura_id')
            ->where('g.carrera_id', $carreraId)
            ->where('g.sede_id', $sedeId)
            ->where('g.estado', 'ACTIVO')
            ->whereNull('g.deleted_at')
            ->whereNull('a.deleted_at')
            ->where('a.estado', '!=', 'cancelado')
            ->selectRaw(
                "DISTINCT UPPER(TRIM(COALESCE(NULLIF(g.plan_estudios, ''), a.plan_estudios))) as plan"
            );

        if (! empty($gestionesEquivalentes)) {
            $queryGrupos->whereIn('g.gestion', $gestionesEquivalentes);
        }

        $planesGrupos = $queryGrupos
            ->pluck('plan')
            ->filter(fn ($plan) => in_array($plan, ['N', 'A'], true))
            ->values()
            ->all();

        if (! empty($planesGrupos)) {
            return $planesGrupos;
        }

        $planCarrera = DB::table('carreras')
            ->where('id', $carreraId)
            ->whereNull('deleted_at')
            ->value('plan_estudios');
        $planCarrera = strtoupper(trim((string) $planCarrera));

        if (in_array($planCarrera, ['N', 'A'], true)) {
            return [$planCarrera];
        }

        return DB::table('asignatura_carrera as ac')
            ->join('asignaturas as a', 'a.id', '=', 'ac.asignatura_id')
            ->where('ac.carrera_id', $carreraId)
            ->where('ac.sede_id', $sedeId)
            ->whereNull('a.deleted_at')
            ->where('a.estado', '!=', 'cancelado')
            ->whereIn('a.plan_estudios', ['N', 'A'])
            ->distinct()
            ->pluck('a.plan_estudios')
            ->map(fn ($plan) => strtoupper(trim((string) $plan)))
            ->filter(fn ($plan) => in_array($plan, ['N', 'A'], true))
            ->values()
            ->all();
    }

    private function gestionesEquivalentes(?string $gestion): array
    {
        $gestion = strtoupper(trim((string) $gestion));

        if ($gestion === '') {
            return [];
        }

        $equivalentes = [$gestion];

        if (preg_match('/^(\d{4})-(I|II)$/', $gestion, $matches)) {
            $periodo = $matches[2] === 'II' ? '2' : '1';
            $equivalentes[] = "{$periodo}-{$matches[1]}";
        } elseif (preg_match('/^([12])-(\d{4})$/', $gestion, $matches)) {
            $periodo = $matches[1] === '2' ? 'II' : 'I';
            $equivalentes[] = "{$matches[2]}-{$periodo}";
        }

        return array_values(array_unique($equivalentes));
    }
}
