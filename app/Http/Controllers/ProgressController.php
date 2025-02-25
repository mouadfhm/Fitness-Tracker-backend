<?php

namespace App\Http\Controllers;

use App\Models\Progress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProgressController extends Controller
{
    // List all progress entries for the authenticated user
    public function index()
    {
        $progress = Progress::where('user_id', Auth::id())
            ->orderBy('date', 'asc')
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

        return response()->json([
            'message'  => 'Progress logged successfully.',
            'progress' => $progress,
        ], 201);
    }
}
