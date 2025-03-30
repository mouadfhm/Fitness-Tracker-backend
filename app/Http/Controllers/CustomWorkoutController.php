<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CustomWorkout;
use Illuminate\Support\Facades\Auth;

class CustomWorkoutController extends Controller
{
    // POST /api/custom-workouts - create a custom workout
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => 'required|string',
                'description' => 'nullable|string',
                'gym_exercises' => 'required|array', // array of exercises with sets/reps/etc.
            ]);
            $customWorkout = CustomWorkout::create([
                'user_id' => auth::id(),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            // Attach exercises (assuming each item has exercise_id, sets, reps, etc.)
            $customWorkout->gym_exercises()->attach($data['gym_exercises']);
            return response()->json($customWorkout, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    // PUT /api/custom-workouts/{id} - update a custom workout
    public function update(Request $request, $id)
    {
        $customWorkout = CustomWorkout::where('user_id', auth::id())->findOrFail($id);
        try {
            $data = $request->validate([
                'name' => 'required|string',
                'description' => 'nullable|string',
                'gym_exercises' => 'required|array', // array of exercises with sets/reps/etc.
            ]);
            $customWorkout->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            $customWorkout->gym_exercises()->sync($data['gym_exercises']);
            return response()->json($customWorkout, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // GET /api/custom-workouts - list user custom workouts
    public function index()
    {
        $workouts = CustomWorkout::with('gym_exercises')->where('user_id', auth::id())->get();
        return response()->json($workouts);
    }

    public function show($id)
    {
        $workout = CustomWorkout::with('gym_exercises')->where('user_id', auth::id())->findOrFail($id);
        return response()->json($workout);
    }

}
