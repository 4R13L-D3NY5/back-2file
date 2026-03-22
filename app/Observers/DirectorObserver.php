<?php

namespace App\Observers;

use App\Models\Director;
use Illuminate\Support\Facades\Log;

class DirectorObserver
{
    /**
     * Handle the Director "created" event.
     */
    public function created(Director $director): void
    {
        // Si se crea con carrera_id, sincronizar con tabla pivot
        if ($director->carrera_id) {
            $this->syncCarreraPrincipalToPivot($director);
        }
    }

    /**
     * Handle the Director "updated" event.
     */
    public function updated(Director $director): void
    {
        // Si se actualizó carrera_id, sincronizar con tabla pivot
        if ($director->isDirty('carrera_id')) {
            $this->syncCarreraPrincipalToPivot($director);
        }
        
        // Si se eliminó carrera_id, eliminar relación principal en pivot
        if ($director->carrera_id === null && $director->wasChanged('carrera_id')) {
            $this->removeCarreraPrincipalFromPivot($director);
        }
    }

    /**
     * Handle the Director "deleted" event.
     */
    public function deleted(Director $director): void
    {
        // Las relaciones en tabla pivot se eliminarán automáticamente por CASCADE
    }

    /**
     * Handle the Director "restored" event.
     */
    public function restored(Director $director): void
    {
        //
    }

    /**
     * Handle the Director "force deleted" event.
     */
    public function forceDeleted(Director $director): void
    {
        //
    }

    /**
     * Sincroniza la carrera principal del director con la tabla pivot.
     * Marca la carrera como principal (es_principal = true).
     */
    private function syncCarreraPrincipalToPivot(Director $director): void
    {
        try {
            // Obtener todas las carreras actuales del director en la tabla pivot
            $carrerasActuales = $director->carreras()->pluck('carrera_id')->toArray();
            
            // Si la carrera principal no está en la lista, agregarla
            if (!in_array($director->carrera_id, $carrerasActuales)) {
                $director->carreras()->attach($director->carrera_id, [
                    'es_principal' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // Si ya existe, asegurarse de que esté marcada como principal
                // y que las otras no lo estén
                $director->carreras()->updateExistingPivot($director->carrera_id, [
                    'es_principal' => true,
                    'updated_at' => now(),
                ]);
                
                // Marcar otras carreras como no principales
                $director->carreras()
                    ->where('carrera_id', '!=', $director->carrera_id)
                    ->update(['director_carrera.es_principal' => false]);
            }
            
            Log::info("Sincronizada carrera principal para director {$director->id}: carrera_id={$director->carrera_id}");
        } catch (\Exception $e) {
            Log::error("Error sincronizando carrera principal para director {$director->id}: " . $e->getMessage());
        }
    }

    /**
     * Elimina la relación principal de la tabla pivot cuando se elimina carrera_id.
     */
    private function removeCarreraPrincipalFromPivot(Director $director): void
    {
        try {
            // Buscar si hay alguna carrera marcada como principal y quitarla
            $director->carreras()
                ->wherePivot('es_principal', true)
                ->update(['director_carrera.es_principal' => false]);
                
            Log::info("Eliminada marca de carrera principal para director {$director->id}");
        } catch (\Exception $e) {
            Log::error("Error eliminando marca de carrera principal para director {$director->id}: " . $e->getMessage());
        }
    }
    
    /**
     * Método público para sincronizar desde la tabla pivot hacia el campo legacy.
     * Se llama cuando se actualizan relaciones a través de la tabla pivot.
     */
    public function syncFromPivot(Director $director): void
    {
        try {
            // Buscar la carrera marcada como principal en la tabla pivot
            $carreraPrincipal = $director->carreras()
                ->wherePivot('es_principal', true)
                ->first();
            
            // Sincronizar con el campo legacy
            if ($carreraPrincipal && $director->carrera_id != $carreraPrincipal->id) {
                $director->carrera_id = $carreraPrincipal->id;
                $director->saveQuietly(); // saveQuietly para evitar loop infinito
                Log::info("Sincronizado campo legacy carrera_id para director {$director->id}: {$carreraPrincipal->id}");
            }
            
            // Si no hay carrera principal en pivot pero hay carreras, establecer la primera como principal
            if (!$carreraPrincipal && $director->carreras()->count() > 0) {
                $primeraCarrera = $director->carreras()->first();
                $director->carreras()->updateExistingPivot($primeraCarrera->id, [
                    'es_principal' => true,
                    'updated_at' => now(),
                ]);
                $director->carrera_id = $primeraCarrera->id;
                $director->saveQuietly();
                Log::info("Establecida primera carrera como principal para director {$director->id}: {$primeraCarrera->id}");
            }
        } catch (\Exception $e) {
            Log::error("Error sincronizando desde pivot para director {$director->id}: " . $e->getMessage());
        }
    }
}