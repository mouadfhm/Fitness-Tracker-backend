<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ScheduledWorkout;
use Illuminate\Support\Facades\Auth;

class ScheduledWorkoutController extends Controller
{
    // POST /api/scheduled-workouts - schedule a workout
    public function store(Request $request)
    {
        $data = $request->validate([
            'scheduled_at' => 'required|date',
            'workout_id' => 'nullable|integer', // plan or custom workout ID

        ]);
        $schedule = ScheduledWorkout::create(array_merge($data, ['user_id' => auth::id()]));
        return response()->json($schedule, 201);
    }

    // GET /api/scheduled-workouts - list scheduled workouts
    public function index()
    {
        $schedules = ScheduledWorkout::where('user_id', auth::id())->get();
        return response()->json($schedules);
    }
    // PUT /api/scheduled-workouts
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'scheduled_at' => 'sometimes|date',
            'workout_id'   => 'sometimes|integer',
        ]);

        $schedule = ScheduledWorkout::where('id', $id)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $schedule->update($data);

        return response()->json($schedule);
    }

    public function delete($id)
    {
        $schedule = ScheduledWorkout::where('id', $id)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $schedule->delete();

        return response()->json(['message' => 'Scheduled workout deleted successfully.'], 200);
    }
}
