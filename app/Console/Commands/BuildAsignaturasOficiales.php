<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildAsignaturasOficiales extends Command
{
    protected $signature   = 'academic:build-oficiales';
    protected $description = 'Extrae asignaturas únicas de la tabla grupos y las guarda en asignaturas_oficiales';

    public function handle(): int
    {
        $this->info('Iniciando construcción de Asignaturas Oficiales desde Grupos...');

        // Source: grupos JOIN asignaturas JOIN asignatura_carrera (pivot for semestre)
        // Only official-format codes like DER-111, ENF-212 (2-5 uppercase letters + dash + 3 digits)
        $combinations = DB::table('grupos')
            ->join('asignaturas', 'grupos.asignatura_id', '=', 'asignaturas.id')
            ->leftJoin('asignatura_carrera', function ($join) {
                $join->on('asignatura_carrera.asignatura_id', '=', 'asignaturas.id')
                     ->on('asignatura_carrera.carrera_id', '=', 'grupos.carrera_id');
            })
            ->whereNull('grupos.deleted_at')
            ->whereNotNull('grupos.carrera_id')
            ->whereNotNull('grupos.sede_id')
            ->whereRaw("asignaturas.codigo REGEXP '^[A-Z]{2,5}-[0-9]{3}$'")
            ->select([
                'asignaturas.codigo',
                'asignaturas.nombre',
                'asignatura_carrera.semestre',
                'grupos.carrera_id',
                'grupos.sede_id',
                DB::raw("COALESCE(grupos.plan_estudios, 'N') as plan_estudios"),
            ])
            ->groupBy('asignaturas.codigo', 'asignaturas.nombre', 'asignatura_carrera.semestre', 'grupos.carrera_id', 'grupos.sede_id', 'grupos.plan_estudios')
            ->get();

        $this->info("Encontradas {$combinations->count()} combinaciones únicas.");

        $bar = $this->output->createProgressBar($combinations->count());
        $bar->start();

        $inserted = 0;
        $updated  = 0;

        foreach ($combinations as $row) {
            $existing = DB::table('asignaturas_oficiales')
                ->where('codigo', $row->codigo)
                ->where('carrera_id', $row->carrera_id)
                ->where('sede_id', $row->sede_id)
                ->first();

            if (!$existing) {
                DB::table('asignaturas_oficiales')->insert([
                    'codigo'        => $row->codigo,
                    'nombre'        => $row->nombre,
                    'semestre'      => $row->semestre,
                    'carrera_id'    => $row->carrera_id,
                    'sede_id'       => $row->sede_id,
                    'plan_estudios' => $row->plan_estudios,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
                $inserted++;
            } else {
                DB::table('asignaturas_oficiales')
                    ->where('id', $existing->id)
                    ->update([
                        'nombre'        => $row->nombre,
                        'semestre'      => $row->semestre,
                        'plan_estudios' => $row->plan_estudios,
                        'updated_at'    => now(),
                    ]);
                $updated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('¡Construcción Completada!');
        $this->line("- Materias Nuevas Insertadas: $inserted");
        $this->line("- Materias Actualizadas: $updated");

        return Command::SUCCESS;
    }
}
