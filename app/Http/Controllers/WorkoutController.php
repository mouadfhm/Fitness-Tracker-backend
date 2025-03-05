<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Models\Workout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WorkoutController extends Controller
{
    // List all workouts for the authenticated user
    public function index()
    {
        $workouts = Workout::where('user_id', Auth::id())->get();
        return response()->json($workouts);
    }
    public function exercises(Request $request)
    {
        $exercises = Exercise::all();
        return response()->json($exercises);
    }
    // Log a new workout
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'activity_type'  => 'required|string',
            'duration'       => 'required|numeric', // in minutes
            'workout_date'   => 'required|date',
        ]);
        $exercise = Exercise::where('description', $validatedData['activity_type'])->first();
        $workout = Workout::create([
            'user_id'       => Auth::id(),
            'activity_type' => $validatedData['activity_type'],
            'duration'      => $validatedData['duration'],
            'calories_burned'=> $exercise->caloriesPerKg * Auth::user()->weight * $validatedData['duration'] / 60,
            'workout_date'  => $validatedData['workout_date'],
        ]);

        return response()->json([
            'message' => 'Workout logged successfully.',
            'workout' => $workout,
        ], 201);
    }

    // Show details for a specific workout
    public function show($id)
    {
        $workout = Workout::where('user_id', Auth::id())->findOrFail($id);
        return response()->json($workout);
    }

    // Update a workout entry
    public function update(Request $request, $id)
    {
        $workout = Workout::where('user_id', Auth::id())->findOrFail($id);
        $validatedData = $request->validate([
            'activity_type'  => 'sometimes|string',
            'duration'       => 'sometimes|numeric',
            'calories_burned'=> 'sometimes|numeric',
            'workout_date'   => 'sometimes|date',
        ]);
        $workout->update($validatedData);

        return response()->json([
            'message' => 'Workout updated successfully.',
            'workout' => $workout,
        ]);
    }

    // Delete a workout entry
    public function destroy($id)
    {
        $workout = Workout::where('user_id', Auth::id())->findOrFail($id);
        $workout->delete();

        return response()->json([
            'message' => 'Workout deleted successfully.'
        ]);
    }
}
