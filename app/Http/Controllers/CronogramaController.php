<?php

namespace App\Http\Controllers;

use App\Models\Cronograma;
use App\Models\EstrategiaDidactica;
use App\Models\Evaluacion;
use App\Models\EvaluacionCronograma;
use App\Models\SecuenciaDidactica;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CronogramaController extends Controller
{
    // Find or Create Cronograma for a specific Class + Date
    public function findOrCreate(Request $request)
    {
        // We assume 'clase_id' refers to 'grupo_id' or 'asignatura_id' depending on frontend.
        // Frontend sends: claseSeleccionada (which is an ID from clasesUnificadas lists).
        // Since frontend mocked data structure is complex (Unified Class -> Carreras -> Materia),
        // we need to know what ID the frontend is actually sending.
        // Assuming for now it sends a 'grupo_id' or 'asignatura_id' + 'fecha'.
        
        $request->validate([
            'asignatura_id' => 'required', // or grupo_id if using Groups
            'fecha' => 'required|date'
        ]);

        $cronograma = Cronograma::firstOrCreate(
            [
                'asignatura_id' => $request->asignatura_id,
                'fecha' => $request->fecha
            ],
            [
                'numero_sesion' => 1, // Default, logic to calc session number needed if strict
                'cumplido' => false
            ]
        );

        // Load relations
        $cronograma->load(['estrategiasDidacticas', 'evaluaciones', 'secuenciasDidacticas']);

        return response()->json($cronograma);
    }

    // Update Seguimiento (Monitoring Data)
    public function updateSeguimiento(Request $request, $id)
    {
        $cronograma = Cronograma::findOrFail($id);
        
        DB::beginTransaction();
        try {
            // Update Main Info
            $cronograma->update([
                'cumplido' => $request->temaCumplido ?? false,
                'observaciones' => $request->observacionesClase
            ]);

            // Update Strategies
            // We replace existing ones for simplicity or update if ID provided
            $cronograma->estrategiasDidacticas()->delete();
            if ($request->has('estrategias')) {
                foreach ($request->estrategias as $est) {
                    if ($est['cumplido']) { // Only save checked ones? Or save all with status?
                         // DB schema has 'metodologicas_docente' etc text fields, not verified bools per item easily.
                         // But frontend sends list of checked items.
                         // Let's store them as JSON or concatenated string in the text fields for now,
                         // OR create a new table 'estrategias_check' if granular tracking needed?
                         // Existing table: estrategias_didacticas (metodologicas_docente, aprendizaje_estudiante...)
                         // This doesn't match the "Checklist" format of frontend perfectly.
                         // Adapting: We will save the CHECKED names into 'metodologicas_docente' as a comma list.
                        EstrategiaDidactica::create([
                            'cronograma_id' => $cronograma->id,
                            'metodologicas_docente' => $est['nombre'] // Saving name as we don't have boolean column
                        ]);
                    }
                }
            }

            // Update Evaluations
            $cronograma->evaluaciones()->delete(); 
             if ($request->has('evaluacion')) {
                foreach ($request->evaluacion as $eva) {
                    if ($eva['cumplido']) {
                        EvaluacionCronograma::create([
                            'cronograma_id' => $cronograma->id,
                            'tipo' => $eva['nombre']
                        ]);
                    }
                }
            }

            // Update Sequences
            $cronograma->secuenciasDidacticas()->delete();
            if ($request->has('secuencia')) {
                foreach ($request->secuencia as $sec) {
                    if ($sec['cumplido']) {
                        SecuenciaDidactica::create([
                            'cronograma_id' => $cronograma->id,
                            'momento' => $sec['nombre']
                        ]);
                    }
                }
            }
            
            // Todo: Handle Evidence files (store paths)
            // Implementation skipped for brevity, requires file upload handling.

            DB::commit();
            return response()->json(['message' => 'Seguimiento guardado', 'data' => $cronograma]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
