<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserAchievement;
use Illuminate\Support\Facades\Auth;

class AchievementController extends Controller
{
// app/Http/Controllers/AchievementController.php
public function getUserAchievements()
{
    $user = Auth::user();
    $achievements = UserAchievement::where('user_id', $user->id)->with('achievement')->get();

    return response()->json($achievements);
}
}
