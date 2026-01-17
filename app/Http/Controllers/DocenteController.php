<?php

namespace App\Http\Controllers;

use App\Models\Docente;
use Illuminate\Http\Request;

class DocenteController extends Controller
{
    public function index(Request $request)
    {
        $query = Docente::query();

        if ($request->has('search')) {
            $term = $request->search;
            $query->where('nombre_completo', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        }

        return response()->json($query->with(['sede', 'asignaturas'])->orderBy('nombre_completo')->get());
    }
}
