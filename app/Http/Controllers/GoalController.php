<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;



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
        // Example using a basic BMR formula (Mifflin-St Jeor)
        $bmr = 10 * $user->weight + 6.25 * $user->height - 5 * $user->age + ($user->gender === 'male' ? 5 : -161);

        // Adjust for activity level
        $activityMultiplier = match ($user->activity_level) {
            'sedentary' => 1.2,
            'moderate'  => 1.55,
            'active'    => 1.725,
            default     => 1.2,
        };
        $dailyCalories = $bmr * $activityMultiplier;

        // Adjust calories based on fitness goal
        if ($user->fitness_goal === 'weight_loss') {
            $dailyCalories -= 500;
        } elseif ($user->fitness_goal === 'muscle_gain') {
            $dailyCalories += 300;
        }

        // Calculate macros using simplified ratios
        $protein = $user->weight * 1.2; // grams per kg of body weight
        $fat     = ($dailyCalories * 0.25) / 9;
        $carbs   = ($dailyCalories - ($protein * 4 + $fat * 9)) / 4;

        return [
            'calories' => round($dailyCalories),
            'protein'  => round($protein),
            'carbs'    => round($carbs),
            'fats'     => round($fat),
        ];
    }
}
