<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Models\Workout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\AchievementService;
use Exception;

class WorkoutController extends Controller
{
    // List all workouts for the authenticated user
    public function index(Request $request)
    {
        $query = Workout::query();
        if ($request->has('activity_type')) {
            $query->where('activity_type', $request->activity_type);
        }
        if ($request->has('workout_date')) {
            $query->where('workout_date', $request->workout_date);
        }
        $workouts = $query->where('user_id', Auth::id())->get();
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
            'calories_burned' => $exercise->caloriesPerKg * Auth::user()->weight * $validatedData['duration'] / 60,
            'workout_date'  => $validatedData['workout_date'],
        ]);
        try {
            $achievementService = new AchievementService();
            $achievementService->checkAndUnlock(Auth::user(), 'workouts', 1);
            //count how many days the user logged workouts in row
            $workoutStreak = Workout::where('user_id', Auth::id())
                ->orderBy('workout_date', 'desc')
                ->get()
                ->map(fn($workout) => $workout->workout_date)
                ->values()
                ->reduce(function ($streak, $date) use (&$previousDate) {
                    if (
                        !isset($previousDate) ||
                        strtotime($previousDate) - strtotime($date) == 86400
                    ) {
                        $previousDate = $date;
                        return $streak + 1;
                    }
                    return $streak;
                }, 0);
            $achievementService->checkAndUnlock(Auth::user(), 'workouts', $workoutStreak);

            //check if the user has logged some type of running workout
            $runningWorkouts = Workout::where('user_id', Auth::id())
                ->where('activity_type', 'Running')
                ->count();
            if ($runningWorkouts > 0) {
                $achievementService->checkAndUnlock(Auth::user(), 'cardio', 1,);
            }
            //count total calories burned by the user
            $caloriesBurned = Workout::where('user_id', Auth::id())->sum('calories_burned');
            $achievementService->checkAndUnlock(Auth::user(), 'calories', $caloriesBurned);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
        return response()->json([
            'message' => 'Workout logged successfully.',
            'workout' => $workout,
        ], 201);
    }
    public function caloriesBurned(Request $request)
    {
        $query = Workout::query();
        if ($request->has('workout_date')) {
            $query->where('workout_date', $request->workout_date);
        }
        $workouts = $query->where('user_id', Auth::id())->get();
        $caloriesBurned = $workouts->sum('calories_burned');
        return response()->json($caloriesBurned);
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
            'calories_burned' => 'sometimes|numeric',
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
