<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Verificar que la tabla pivot existe
        if (!Schema::hasTable('director_carrera')) {
            throw new \Exception('La tabla director_carrera no existe. Ejecute primero la migración 2026_03_21_000000_create_director_carrera_table');
        }

        // Paso 1: Migrar datos de carreras.director_id (muchas carreras pueden apuntar a un director)
        $carrerasConDirector = DB::table('carreras')
            ->whereNotNull('director_id')
            ->select('id as carrera_id', 'director_id')
            ->get();

        foreach ($carrerasConDirector as $registro) {
            // Verificar si el director existe antes de intentar asociarlo
            $directorExiste = DB::table('directors')->where('id', $registro->director_id)->exists();
            if (!$directorExiste) {
                continue;
            }

            // Verificar si ya existe en la tabla pivot (por si acaso)
            $existe = DB::table('director_carrera')
                ->where('director_id', $registro->director_id)
                ->where('carrera_id', $registro->carrera_id)
                ->exists();

            if (!$existe) {
                DB::table('director_carrera')->insert([
                    'director_id' => $registro->director_id,
                    'carrera_id' => $registro->carrera_id,
                    'es_principal' => false, // Se marcará como principal en el paso 2 si corresponde
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Paso 2: Migrar datos de directors.carrera_id (carrera principal del director)
        $directoresConCarreraPrincipal = DB::table('directors')
            ->whereNotNull('carrera_id')
            ->select('id as director_id', 'carrera_id')
            ->get();

        foreach ($directoresConCarreraPrincipal as $registro) {
            // Verificar si la carrera existe antes de intentar asociarla
            $carreraExiste = DB::table('carreras')->where('id', $registro->carrera_id)->exists();
            if (!$carreraExiste) {
                continue;
            }

            // Verificar si ya existe en la tabla pivot
            $existe = DB::table('director_carrera')
                ->where('director_id', $registro->director_id)
                ->where('carrera_id', $registro->carrera_id)
                ->exists();

            if ($existe) {
                // Si ya existe, actualizar para marcar como principal
                DB::table('director_carrera')
                    ->where('director_id', $registro->director_id)
                    ->where('carrera_id', $registro->carrera_id)
                    ->update(['es_principal' => true]);
            } else {
                // Si no existe, insertar como principal
                DB::table('director_carrera')->insert([
                    'director_id' => $registro->director_id,
                    'carrera_id' => $registro->carrera_id,
                    'es_principal' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Paso 3: Para directores que tienen carreras vía carreras.director_id pero no tienen carrera_id,
        // establecer la primera carrera como principal
        $directoresSinCarreraPrincipal = DB::table('directors as d')
            ->leftJoin('director_carrera as dc', function ($join) {
                $join->on('d.id', '=', 'dc.director_id')
                     ->where('dc.es_principal', '=', true);
            })
            ->whereNull('dc.id')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                      ->from('director_carrera as dc2')
                      ->whereColumn('dc2.director_id', 'd.id');
            })
            ->select('d.id as director_id')
            ->get();

        foreach ($directoresSinCarreraPrincipal as $director) {
            // Obtener la primera carrera del director (ordenada por ID)
            $primeraCarrera = DB::table('director_carrera')
                ->where('director_id', $director->director_id)
                ->orderBy('carrera_id')
                ->first();

            if ($primeraCarrera) {
                DB::table('director_carrera')
                    ->where('director_id', $director->director_id)
                    ->where('carrera_id', $primeraCarrera->carrera_id)
                    ->update(['es_principal' => true]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Al revertir, simplemente vaciamos la tabla pivot
        // Los campos legacy (directors.carrera_id, carreras.director_id) mantienen sus valores
        DB::table('director_carrera')->truncate();
    }
};