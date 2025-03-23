<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\Workout;



class GoalController extends Controller
{
    // Update the user's fitness goal and return new macro recommendations
    public function update(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'fitness_goal' => 'required|in:weight_loss,muscle_gain,maintenance',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
        try {
            $user = Auth::user();
            $user->fitness_goal = $validatedData['fitness_goal'];
            $user->save();

            // Recalculate macros based on the updated goal
            $macros = $this->calculateMacros($user);

            return response()->json([
                'message' => 'Fitness goal updated successfully.',
                'user'    => $user,
                'macros'  => $macros,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $macros = $this->calculateMacros($user);
        return response()->json([
            'user'    => $user,
            'macros'  => $macros,
        ]);
    }

    // Helper method for calculating daily macronutrient needs
    protected function calculateMacros($user)
    {
        // Fetch total workout calories burned today
        $workouts = Workout::where('user_id', $user->id)
            ->whereDate('workout_date', today())
            ->sum('calories_burned');
    
        // Base activity level multiplier
        $baseActivityMultiplier = match ($user->activity_level) {
            'sedentary' => 1.2,  
            'moderate'  => 1.4,  
            'active'    => 1.6,  
            default     => 1.2,
        };
    
        // Calculate BMR (Mifflin-St Jeor)
        $bmr = 10 * $user->weight + 6.25 * $user->height - 5 * $user->age + ($user->gender === 'male' ? 5 : -161);
    
        // Calculate TDEE with dynamic workout calories
        $dailyCalories = ($bmr * $baseActivityMultiplier) + $workouts;
    
        // Adjust calories based on fitness goal
        if ($user->fitness_goal === 'weight_loss') {
            $dailyCalories -= 500;
        } elseif ($user->fitness_goal === 'muscle_gain') {
            $dailyCalories += 300;
        }
    
        // Adjust macros dynamically
        $protein = $user->weight * ($workouts > 300 ? 1.5 : 1.2); // More protein on high workout days
        $fat     = ($dailyCalories * 0.25) / 9;
        $carbs   = ($dailyCalories - ($protein * 4 + $fat * 9)) / 4;
    
        return [
            'bmr'      => $bmr,
            'tdee'     => $dailyCalories,
            'calories' => round($dailyCalories),
            'protein'  => round($protein),
            'carbs'    => round($carbs),
            'fats'     => round($fat),
        ];
    }
    }
