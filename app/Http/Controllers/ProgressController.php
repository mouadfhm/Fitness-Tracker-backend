<?php

namespace App\Http\Controllers;

use App\Models\Progress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\AchievementService;

class ProgressController extends Controller
{
    // List all progress entries for the authenticated user
    public function index()
    {
        $progress = Progress::where('user_id', Auth::id())
            ->orderBy('date', 'desc')
            ->limit(10)
            ->get();

        return response()->json($progress);
    }

    // Log a new progress entry (e.g., daily weight)
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'date'   => 'required|date',
            'weight' => 'required|numeric',
        ]);

        $progress = Progress::create([
            'user_id' => Auth::id(),
            'date'    => $validatedData['date'],
            'weight'  => $validatedData['weight'],
        ]);
        $achievementService = new AchievementService();
        $achievementService->checkAndUnlock(Auth::user(), 'progress', 1);
        //count how many kg the user lost in total if the user objective is to lose weight
        $objective = Auth::user()->fitness_goal;
        if ($objective == 'weight_loss') {
            $weights = Progress::where('user_id', Auth::id())
                ->orderBy('date', 'asc')
                ->pluck('weight');
            if ($weights->count() >= 2) {
                $firstWeight = $weights->first();
                $lastWeight = $weights->last();
                $totalWeightLost = $firstWeight - $lastWeight;
                $achievementService->checkAndUnlock(Auth::user(), 'weight', $totalWeightLost);
            }
        } elseif ($objective == 'muscle_gain') {
            $weights = Progress::where('user_id', Auth::id())
                ->orderBy('date', 'asc')
                ->pluck('weight');
            if ($weights->count() >= 2) {
                $firstWeight = $weights->first();
                $lastWeight = $weights->last();
                $totalMuscleGained = $lastWeight - $firstWeight;
                $achievementService->checkAndUnlock(Auth::user(), 'muscle', $totalMuscleGained);
            }
        }
        return response()->json([
            'message'  => 'Progress logged successfully.',
            'progress' => $progress,
        ], 201);
    }
}
