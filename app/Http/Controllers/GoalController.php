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
            $user = \App\Models\User::find(Auth::id());
            $user->fitness_goal = $validatedData['fitness_goal'];
            $user->save();

            // Recalculate macros based on the updated goal
            $macros = $this->calculateMacros($user);
            if ($macros === null) {
                return response()->json([
                    'message' => 'Fitness goal updated successfully, but macros cannot be calculated until weight, height, and age are set.',
                    'user'    => $user,
                ]);
            }

            return response()->json([
                'message' => 'Fitness goal updated successfully.',
                'user'    => $user,
                'macros'  => $macros,
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e, 'error', 500);
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $macros = $this->calculateMacros($user);
        if ($macros === null) {
            return response()->json(['message' => 'Profile incomplete. Please set weight, height, and age.'], 422);
        }
        return response()->json([
            'user'   => $user,
            'macros' => $macros,
        ]);
    }

    // Helper method for calculating daily macronutrient needs
    protected function calculateMacros($user)
    {
        if (is_null($user->weight) || is_null($user->height) || is_null($user->age)) {
            return null;
        }

        // BMR (Mifflin-St Jeor), the activity multiplier and the per-goal
        // adjustment now live on the User model. They moved because the daily
        // reminders quote this number back at the user ("480 kcal under your
        // goal"), and a second copy of the formula would eventually disagree
        // with this screen. Same arithmetic, one home.
        $bmr = $user->basalMetabolicRate();
        $tdee = $user->totalDailyEnergyExpenditure();
        $dailyCalories = $user->dailyCalorieTarget();

        // Protein per kg: most while cutting (keeps muscle), a little less while
        // gaining, 1.6 g/kg to maintain. Counted against the weight at a BMI of
        // 25 at most, since extra fat mass needs no extra protein: 2 g per kg
        // of a 130 kg body asked for 260 g a day.
        $proteinPerKg = match ($user->fitness_goal) {
            'weight_loss' => 2.0,
            'muscle_gain' => 1.8,
            default       => 1.6,
        };
        $heightM = $user->height / 100;
        $proteinWeight = min($user->weight, 25 * $heightM * $heightM);

        $protein = $proteinWeight * $proteinPerKg;
        $fat     = ($dailyCalories * 0.25) / 9; // 25% of total calories from fat, divided by 9 calories per gram
        $carbs   = max(0, ($dailyCalories - ($protein * 4 + $fat * 9)) / 4);

        return [
            'bmr'      => $bmr,
            // What they burn on a typical day; 'calories' is what to eat.
            'tdee'     => $tdee,
            'calories' => round($dailyCalories),
            'protein'  => round($protein),
            'carbs'    => round($carbs),
            'fats'     => round($fat),
        ];
    }
}
