<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WorkoutLog;
use Illuminate\Support\Facades\Auth;

class WorkoutLogController extends Controller
{
// POST /api/workout-logs - log a workout
public function store(Request $request)
{
    try{
    $data = $request->validate([
        'workout_date' => 'required|date',
        'duration' => 'nullable|integer',
        'calories_burned' => 'nullable|integer',
        'details' => 'nullable|json',
    ]);
    $log = WorkoutLog::create(array_merge($data, ['user_id' => auth::id()]));
    }catch(\Exception $e){
        return response()->json(['error' => $e->getMessage()], 400);
    }
    return response()->json($log, 201);
}

// GET /api/workout-logs - get user's workout history
public function index()
{
    $logs = WorkoutLog::where('user_id', auth::id())->get();
    return response()->json($logs);
}}
