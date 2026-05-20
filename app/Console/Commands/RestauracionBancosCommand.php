<?php

namespace App\Console\Commands;

use App\Models\Asignatura;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RestauracionBancosCommand extends Command
{
    protected $signature = 'restauracion:bancos
                            {--dry-run : Solo previsualizar, no modificar nada}
                            {--parcial=2do Parcial : Filtrar por parcial}
                            {--sede= : Filtrar por sede_id}
                            {--carrera= : Filtrar por carrera_id}
                            {--materia= : Filtrar por codigo de materia}
                            {--plan= : Plan de estudios (N o A). Usar con --materia}
                            {--limit= : Limitar cantidad de preguntas}';

    protected $description = 'Restaurar bancos de preguntas huérfanos o mal asignados';

    const BACKUP_DBS = ['academicolunes','academicomartes','academicomiercoles','academicojueves','academicoviernes'];
    const CURRENT_DB = 'academico';
    const CORTE_2DO_PARCIAL = '2026-05-08';

    protected int $restauradas = 0;
    protected int $sinConsenso = 0;
    protected int $yaCorrectas = 0;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $parcial = $this->option('parcial');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $filters = $this->buildFilters();

        $this->info('=== RESTAURACIÓN DE BANCOS DE PREGUNTAS ===');
        $this->info('Modo: ' . ($dryRun ? 'DRY-RUN' : 'EJECUCIÓN REAL'));
        $this->info("Parcial: {$parcial}");
        if ($filters) $this->info('Filtros: ' . json_encode($filters));
        $this->newLine();

        $huerfanas = $this->findOrphanedQuestions($parcial, $filters, $limit);
        $this->info("Huérfanas: {$huerfanas->count()}");
        $malAsignadas = $this->findMisassignedQuestions($parcial, $filters, $limit);
        $this->info("Mal asignadas: {$malAsignadas->count()}");

        $ids = $huerfanas->pluck('id')->merge($malAsignadas->pluck('id'))->unique()->values();
        $total = $ids->count();
        $this->info("Total: {$total}");
        $this->newLine();
        if ($total === 0) { $this->info('Nada que procesar.'); return 0; }

        $bar = $this->output->createProgressBar($total); $bar->start();
        $cambios = collect();
        foreach ($ids as $pid) { $r = $this->restaurarPregunta($pid, $parcial, $dryRun); if ($r) $cambios->push($r); $bar->advance(); }
        $bar->finish();
        $this->newLine(2);

        $this->info("Total: {$total} | Restauradas: {$this->restauradas} | Correctas: {$this->yaCorrectas} | Sin consenso: {$this->sinConsenso}");
        if ($dryRun && $cambios->isNotEmpty()) $this->warn('DRY-RUN: cambios NO aplicados.');
        if ($cambios->isNotEmpty()) $this->table(['ID','Parcial','Old Asig','New Asig','Acciones'], $cambios->map(fn($c) => [$c['pregunta_id'],$c['parcial'],$c['old_asignatura']??'N/A',$c['new_asignatura'],$c['acciones']]));
        return 0;
    }

    private function buildFilters(): array
    {
        $f = [];
        if ($this->option('sede')) $f['sede_id'] = (int) $this->option('sede');
        if ($this->option('carrera')) $f['carrera_id'] = (int) $this->option('carrera');
        if ($this->option('materia')) $f['codigo'] = $this->option('materia');
        if ($this->option('plan')) $f['plan_estudios'] = $this->option('plan');
        return $f;
    }

    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['sede_id'])) $query->where('bp.sede_id', $filters['sede_id']);
        if (!empty($filters['codigo'])) { $query->where('a.codigo', $filters['codigo']); if (!empty($filters['plan_estudios'])) $query->where('a.plan_estudios', $filters['plan_estudios']); }
        if (!empty($filters['carrera_id'])) $query->join('asignatura_carrera AS acf', function ($join) { $join->on('acf.asignatura_id','=','bp.asignatura_id')->on('acf.sede_id','=','bp.sede_id'); })->where('acf.carrera_id', $filters['carrera_id']);
    }

    private function applyParcialFilter($query, string $parcial): void
    {
        if ($parcial === '2do Parcial') {
            $query->where(function ($q) {
                $q->where('bp.parcial', '2do Parcial')
                  ->orWhere(function ($q2) {
                      $q2->where('bp.parcial', '1er Parcial')
                         ->where('bp.created_at', '>=', self::CORTE_2DO_PARCIAL);
                  });
            });
        } else {
            $query->where('bp.parcial', $parcial);
        }
    }

    private function findOrphanedQuestions(string $parcial, array $filters, ?int $limit)
    {
        $q = DB::table(self::CURRENT_DB.'.banco_preguntas AS bp')->join(self::CURRENT_DB.'.asignaturas AS a','a.id','=','bp.asignatura_id')->whereNotNull('a.deleted_at');
        $this->applyParcialFilter($q, $parcial);
        $this->applyFilters($q, $filters); if ($limit) $q->limit($limit);
        return $q->select('bp.id')->get();
    }

    private function findMisassignedQuestions(string $parcial, array $filters, ?int $limit)
    {
        $ids = collect();
        foreach (self::BACKUP_DBS as $db) {
            $q = DB::table(self::CURRENT_DB.'.banco_preguntas AS bp')->join(self::CURRENT_DB.'.asignaturas AS a','a.id','=','bp.asignatura_id')->join("{$db}.banco_preguntas AS bl",'bl.id','=','bp.id')->join("{$db}.asignaturas AS al",'al.id','=','bl.asignatura_id')->whereNull('a.deleted_at')->whereNull('al.deleted_at')->whereColumn('a.codigo','!=','al.codigo');
            $this->applyParcialFilter($q, $parcial);
            $this->applyFilters($q, $filters); if ($limit) $q->limit($limit);
            foreach ($q->select('bp.id')->get() as $c) { if (!$ids->contains($c->id)) $ids->push($c->id); }
        }
        return $ids->isNotEmpty() ? DB::table(self::CURRENT_DB.'.banco_preguntas')->whereIn('id',$ids)->select('id')->get() : collect();
    }

    private function getOldestValidBackup(int $preguntaId): array
    {
        foreach (self::BACKUP_DBS as $db) {
            try {
                $row = DB::table("{$db}.banco_preguntas AS bp")->join("{$db}.asignaturas AS a",'a.id','=','bp.asignatura_id')->where('bp.id',$preguntaId)->whereNull('a.deleted_at')->select('bp.asignatura_id','a.codigo','a.plan_estudios')->first();
                if ($row && $row->codigo) return ['encontrado'=>true,'codigo'=>$row->codigo,'plan'=>$row->plan_estudios,'asignatura_id'=>$row->asignatura_id,'backup'=>$db];
            } catch (\Throwable $e) {}
        }
        return ['encontrado'=>false];
    }

    private function resolverAsignaturaCorrecta(int $preguntaId, $currentAsig, int $sedeId): ?Asignatura
    {
        $backup = $this->getOldestValidBackup($preguntaId);
        if ($backup['encontrado']) { $codigo=$backup['codigo']; $plan=$backup['plan']??'N'; }
        elseif ($currentAsig && $currentAsig->codigo) { $codigo=$currentAsig->codigo; $plan=$currentAsig->plan_estudios?:'N'; }
        else return null;

        $a = Asignatura::where('codigo',$codigo)->where('plan_estudios',$plan)->whereNull('deleted_at')->first();
        if (!$a) { $r = Asignatura::withTrashed()->where('codigo',$codigo)->where('plan_estudios',$plan)->whereNotNull('deleted_at')->first(); if ($r) { $r->deleted_at=null; $r->save(); $a=$r; } }
        if (!$a) $a = Asignatura::where('codigo',$codigo)->whereNull('deleted_at')->first();
        if (!$a) return null;
        $this->asegurarPivoteSede($a,$sedeId,$codigo);
        return $a;
    }

    private function asegurarPivoteSede(Asignatura $a, int $sedeId, string $codigo): void
    {
        if (DB::table('asignatura_carrera')->where('asignatura_id',$a->id)->where('sede_id',$sedeId)->exists()) return;
        $ph = DB::table('asignatura_carrera AS ac')->join('asignaturas AS as2','as2.id','=','ac.asignatura_id')->where('as2.codigo',$codigo)->where('as2.id','!=',$a->id)->whereNull('as2.deleted_at')->where('ac.sede_id',$sedeId)->select('ac.carrera_id','ac.semestre')->first();
        if ($ph) DB::table('asignatura_carrera')->insert(['asignatura_id'=>$a->id,'carrera_id'=>$ph->carrera_id,'sede_id'=>$sedeId,'semestre'=>$ph->semestre,'created_at'=>now(),'updated_at'=>now()]);
    }

    private function debeSer2doParcial($p): bool
    {
        return $p->created_at && $p->created_at >= self::CORTE_2DO_PARCIAL && $p->parcial === '1er Parcial';
    }

    private function restaurarPregunta(int $preguntaId, string $parcial, bool $dryRun): ?array
    {
        $p = DB::table(self::CURRENT_DB.'.banco_preguntas')->where('id',$preguntaId)->select('id','asignatura_id','parcial','docente_id','sede_id','grupoTeorico','created_at')->first();
        if (!$p) return null;

        $ca = DB::table(self::CURRENT_DB.'.asignaturas')->where('id',$p->asignatura_id)->select('id','codigo','plan_estudios','nombre','deleted_at')->first();
        $ac = $this->resolverAsignaturaCorrecta($preguntaId,$ca,$p->sede_id);
        if (!$ac) { $this->sinConsenso++; return null; }

        $auto2do = $this->debeSer2doParcial($p);
        $necesitaAsig = $p->asignatura_id != $ac->id;

        if (!$necesitaAsig && !$auto2do) { $this->yaCorrectas++; return null; }

        $old = $ca ? "{$ca->codigo} ({$ca->id})" : "{$p->asignatura_id}";
        $src = $this->getOldestValidBackup($preguntaId);
        $srcName = $src['backup'] ?? ($ca->deleted_at ? 'restaurada' : 'actual');
        $acciones = [];
        if ($necesitaAsig) $acciones[] = 'asignatura';
        if ($auto2do) $acciones[] = 'parcial';

        if (!$dryRun) {
            $data = [];
            if ($necesitaAsig) $data['asignatura_id'] = $ac->id;
            if ($auto2do) $data['parcial'] = '2do Parcial';
            DB::table(self::CURRENT_DB.'.banco_preguntas')->where('id',$preguntaId)->update($data);
            if ($necesitaAsig) $this->migrarConfiguracionesSeguro($p->asignatura_id,$ac->id,$parcial);
        }

        $this->restauradas++;
        return ['pregunta_id'=>$preguntaId,'parcial'=>$p->parcial,'old_asignatura'=>$old,'new_asignatura'=>$necesitaAsig ? "{$ac->codigo} ({$ac->id})" : '—','acciones'=>implode(' + ',$acciones),'source'=>$srcName];
    }

    private function migrarConfiguracionesSeguro(int $oldId, int $newId, string $parcial): void
    {
        $cfgs = DB::table(self::CURRENT_DB.'.banco_preguntas_configuraciones')->where('asignatura_id',$oldId)->where('parcial',$parcial)->get();
        foreach ($cfgs as $c) { if (DB::table(self::CURRENT_DB.'.banco_preguntas_configuraciones')->where('asignatura_id',$newId)->where('sede_id',$c->sede_id)->where('grupo_teorico',$c->grupo_teorico)->where('parcial',$parcial)->exists()) continue; DB::table(self::CURRENT_DB.'.banco_preguntas_configuraciones')->where('id',$c->id)->update(['asignatura_id'=>$newId]); }
    }
}
