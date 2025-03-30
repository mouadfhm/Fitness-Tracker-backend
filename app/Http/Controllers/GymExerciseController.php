<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\GymExercise;

class GymExerciseController extends Controller
{
    public function index()
    {
        // Retrieve all gym exercises from the database.
        $gymExercises = GymExercise::all();
        return response()->json(['gym_exercises' => $gymExercises]);
    }

    public function show($id)
    {
        // Find the gym exercise by its ID.
        $gymExercise = GymExercise::find($id);
        if (!$gymExercise) {
            return response()->json(['message' => 'Gym Exercise not found.'], 404);
        }
        return response()->json(['gym_exercise' => $gymExercise]);
    }

    public function search(Request $request)
    {
        try {
            $query = GymExercise::query();

            $searchParams = [
                'name',
                'description',
                'body_part',
                'equipment',
                'level',
            ];

            $hasSearchParams = false;
            foreach ($searchParams as $param) {
                $value = $request->input($param);
                if ($value) {
                    $query->where($param, 'like', "%{$value}%");
                    $hasSearchParams = true;
                }
            }

            if (!$hasSearchParams) {
                return response()->json(['gym_exercises' => GymExercise::all()]);
            }

            $results = $query->get();

            return response()->json(['gym_exercises' => $results]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
