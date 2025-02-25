<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Food;
use App\Models\Meal;
use App\Models\Progress;

class AdminController extends Controller
{
    // Dashboard endpoint to view aggregated statistics
    public function dashboard()
    {
        $totalUsers    = User::count();
        $totalMeals    = Meal::count();
        $totalFoods    = Food::count();
        $averageWeight = Progress::avg('weight');

        return response()->json([
            'total_users'   => $totalUsers,
            'total_meals'   => $totalMeals,
            'total_foods'   => $totalFoods,
            'average_weight'=> round($averageWeight, 2),
        ]);
    }
}
