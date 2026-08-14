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
            $data = $request->validate($this->gymExercisesValidationRules());
            $customWorkout = CustomWorkout::create([
                'user_id' => Auth::id(),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            $customWorkout->gym_exercises()->attach($this->pivotData($data['gym_exercises']));
            return response()->json($customWorkout, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    // PUT /api/custom-workouts/{id} - update a custom workout
    public function update(Request $request, $id)
    {
        $customWorkout = CustomWorkout::where('user_id', Auth::id())->findOrFail($id);
        try {
            $data = $request->validate($this->gymExercisesValidationRules());
            $customWorkout->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            $customWorkout->gym_exercises()->sync($this->pivotData($data['gym_exercises']));
            return response()->json($customWorkout, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // Shared validation rules for store/update: gym_exercises is an array of
    // {gym_exercise_id, sets, reps, duration, rest} objects, matching the
    // custom_workout_exercise pivot columns.
    private function gymExercisesValidationRules(): array
    {
        return [
            'name'                             => 'required|string',
            'description'                      => 'nullable|string',
            'gym_exercises'                    => 'required|array',
            'gym_exercises.*.gym_exercise_id'  => 'required|integer|exists:gym_exercises,id',
            'gym_exercises.*.sets'             => 'nullable|integer',
            'gym_exercises.*.reps'             => 'nullable|integer',
            'gym_exercises.*.duration'         => 'nullable|integer',
            'gym_exercises.*.rest'             => 'nullable|integer',
        ];
    }

    // Reshapes validated gym_exercises into the [gym_exercise_id => pivotAttributes]
    // form attach()/sync() need to actually write sets/reps/duration/rest.
    private function pivotData(array $gymExercises): array
    {
        return collect($gymExercises)->mapWithKeys(fn ($exercise) => [
            $exercise['gym_exercise_id'] => [
                'sets'     => $exercise['sets'] ?? null,
                'reps'     => $exercise['reps'] ?? null,
                'duration' => $exercise['duration'] ?? null,
                'rest'     => $exercise['rest'] ?? null,
            ],
        ])->all();
    }

    // GET /api/custom-workouts - list user custom workouts
    public function index()
    {
        $workouts = CustomWorkout::with('gym_exercises')->where('user_id', Auth::id())->get();
        return response()->json($workouts);
    }

    public function show($id)
    {
        $workout = CustomWorkout::with('gym_exercises')->where('user_id', Auth::id())->findOrFail($id);
        return response()->json($workout);
    }

}
