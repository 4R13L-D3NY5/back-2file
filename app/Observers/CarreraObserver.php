<?php

namespace App\Observers;

use App\Models\Carrera;
use Illuminate\Support\Facades\Log;

class CarreraObserver
{
    /**
     * Handle the Carrera "created" event.
     */
    public function created(Carrera $carrera): void
    {
        // Si se crea con director_id, sincronizar con tabla pivot
        if ($carrera->director_id) {
            $this->syncDirectorToPivot($carrera);
        }
    }

    /**
     * Handle the Carrera "updated" event.
     */
    public function updated(Carrera $carrera): void
    {
        // Si se actualizó director_id, sincronizar con tabla pivot
        if ($carrera->isDirty('director_id')) {
            $this->syncDirectorToPivot($carrera);
        }
        
        // Si se eliminó director_id, eliminar relación en pivot
        if ($carrera->director_id === null && $carrera->wasChanged('director_id')) {
            $this->removeDirectorFromPivot($carrera);
        }
    }

    /**
     * Handle the Carrera "deleted" event.
     */
    public function deleted(Carrera $carrera): void
    {
        // Las relaciones en tabla pivot se eliminarán automáticamente por CASCADE
    }

    /**
     * Handle the Carrera "restored" event.
     */
    public function restored(Carrera $carrera): void
    {
        //
    }

    /**
     * Handle the Carrera "force deleted" event.
     */
    public function forceDeleted(Carrera $carrera): void
    {
        //
    }

    /**
     * Sincroniza el director de la carrera con la tabla pivot.
     */
    private function syncDirectorToPivot(Carrera $carrera): void
    {
        try {
            // Obtener todos los directores actuales de la carrera en la tabla pivot
            $directoresActuales = $carrera->directores()->pluck('director_id')->toArray();
            
            // Si el director no está en la lista, agregarlo
            if (!in_array($carrera->director_id, $directoresActuales)) {
                $carrera->directores()->attach($carrera->director_id, [
                    'es_principal' => $this->shouldBePrincipal($carrera, $carrera->director_id),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // Si ya existe, actualizar si es necesario
                $carrera->directores()->updateExistingPivot($carrera->director_id, [
                    'es_principal' => $this->shouldBePrincipal($carrera, $carrera->director_id),
                    'updated_at' => now(),
                ]);
            }
            
            Log::info("Sincronizado director para carrera {$carrera->id}: director_id={$carrera->director_id}");
        } catch (\Exception $e) {
            Log::error("Error sincronizando director para carrera {$carrera->id}: " . $e->getMessage());
        }
    }

    /**
     * Determina si este director debería ser marcado como principal para esta carrera.
     * Regla: Si el director tiene esta carrera como su carrera_id, entonces es principal.
     */
    private function shouldBePrincipal(Carrera $carrera, int $directorId): bool
    {
        try {
            $director = \App\Models\Director::find($directorId);
            return $director && $director->carrera_id == $carrera->id;
        } catch (\Exception $e) {
            Log::error("Error determinando si director {$directorId} es principal para carrera {$carrera->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Elimina la relación del director de la tabla pivot cuando se elimina director_id.
     */
    private function removeDirectorFromPivot(Carrera $carrera): void
    {
        try {
            // Buscar si este director estaba marcado como principal y quitarlo
            // Nota: No eliminamos la relación completamente, solo quitamos la marca de principal
            // La relación podría permanecer si el director aún está asignado a través de otros medios
            $carrera->directores()
                ->wherePivot('es_principal', true)
                ->update(['director_carrera.es_principal' => false]);
                
            Log::info("Eliminada marca de director principal para carrera {$carrera->id}");
        } catch (\Exception $e) {
            Log::error("Error eliminando marca de director principal para carrera {$carrera->id}: " . $e->getMessage());
        }
    }
    
    /**
     * Método público para sincronizar desde la tabla pivot hacia el campo legacy.
     * Se llama cuando se actualizan relaciones a través de la tabla pivot.
     */
    public function syncFromPivot(Carrera $carrera): void
    {
        try {
            // Para mantener compatibilidad con el sistema actual (una carrera tiene un director),
            // buscamos el director marcado como principal en la tabla pivot
            $directorPrincipal = $carrera->directores()
                ->wherePivot('es_principal', true)
                ->first();
            
            // Sincronizar con el campo legacy
            if ($directorPrincipal && $carrera->director_id != $directorPrincipal->id) {
                $carrera->director_id = $directorPrincipal->id;
                $carrera->saveQuietly(); // saveQuietly para evitar loop infinito
                Log::info("Sincronizado campo legacy director_id para carrera {$carrera->id}: {$directorPrincipal->id}");
            }
            
            // Si no hay director principal en pivot pero hay directores, establecer el primero
            if (!$directorPrincipal && $carrera->directores()->count() > 0) {
                $primerDirector = $carrera->directores()->first();
                $carrera->directores()->updateExistingPivot($primerDirector->id, [
                    'es_principal' => true,
                    'updated_at' => now(),
                ]);
                $carrera->director_id = $primerDirector->id;
                $carrera->saveQuietly();
                Log::info("Establecido primer director como principal para carrera {$carrera->id}: {$primerDirector->id}");
            }
        } catch (\Exception $e) {
            Log::error("Error sincronizando desde pivot para carrera {$carrera->id}: " . $e->getMessage());
        }
    }
}