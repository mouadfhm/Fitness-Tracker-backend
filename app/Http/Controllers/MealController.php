<?php

namespace App\Http\Controllers;

use App\Models\Food;
use App\Models\Meal;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Services\AchievementService;

class MealController extends Controller
{
    // List all meals for the authenticated user
    public function index(Request $request)
    {
        $query = Meal::query();
        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }
        if ($request->has('meal_time')) {
            $query->where('meal_time', $request->meal_time);
        }
        $meals = $query->with('foods')->where('user_id', Auth::id())->get();
        return response()->json($meals);
    }
    // meals total macros
    public function totalMacros()
    {
        $meals = Auth::user()->meals()->with('foods')->whereDate('date', now()->toDateString())->get();
        $totalCalories = 0;
        $totalProtein = 0;
        $totalFat = 0;
        $totalCarbs = 0;
        foreach ($meals as $meal) {
            foreach ($meal->foods as $food) {
                $quantity = $food->pivot->quantity / 100; // Get the quantity for this food in the meal
                $totalCalories += $food->calories * $quantity;
                $totalProtein += $food->protein * $quantity;
                $totalFat += $food->fats * $quantity;
                $totalCarbs += $food->carbs * $quantity;
            }
        }
        return response()->json([
            'totalCalories' => $totalCalories,
            'totalProtein' => $totalProtein,
            'totalFat' => $totalFat,
            'totalCarbs' => $totalCarbs,
        ]);
    }
    // Log a new meal with foods and their quantities
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'meal_time' => 'required|string',
                'date'      => 'required|date',
                'foods'     => 'required|array',
                'foods.*.name' => 'string',
                'foods.*.food_id'   => 'exists:foods,id',
                'foods.*.quantity'  => 'required|numeric',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
        try {
            $meal = Meal::create([
                'user_id'   => Auth::id(),
                'date'      => $validatedData['date'],
                'meal_time' => $validatedData['meal_time'],
            ]);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
        try {
            // Attach foods with pivot data (quantity)
            foreach ($validatedData['foods'] as $foodItem) {
                if (!isset($foodItem['food_id'])) {
                    $foodItem['food_id'] = Food::where('name', $foodItem['name'])->value('id');
                }
                $meal->foods()->attach($foodItem['food_id'], ['quantity' => $foodItem['quantity']]);
            }
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
        try {
            //count how many meals the user logged every days in row
            $achievementService = new AchievementService();
            $achievementService->checkAndUnlock(Auth::user(), 'meals', 1);
            $currentDate = now()->toDateString();
            $consecutiveDays = 0;
            for ($i = 0; $i <= 30; $i++) { // Check up to 30 days back
                $date = now()->subDays($i)->toDateString();
                $hasMeal = Auth::user()->meals()->whereDate('date', $date)->exists();

                if (!$hasMeal) {
                    break;
                }
                $consecutiveDays++;
            }
            $achievementService->checkAndUnlock(Auth::user(), 'streak', $consecutiveDays);
            // count how many calories the user logged in this days
            $caloriesToday = Auth::user()->meals()
                ->whereDate('date', now()->toDateString())
                ->with('foods')
                ->get()
                ->sum(function ($meal) {
                    return $meal->foods->sum(function ($food) {
                        return $food->calories * $food->pivot->quantity / 100;
                    });
                });
            $achievementService->checkAndUnlock(Auth::user(), 'calories', $caloriesToday);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
        return response()->json([
            'message' => 'Meal logged successfully.',
            'meal'    => $meal->load('foods'),
        ], 201);
    }

    // Display a specific meal for the authenticated user
    public function show($id)
    {
        $meal = Meal::with('foods')
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        return response()->json($meal);
    }

    // Update meal details and food items
    public function update(Request $request, $id)
    {
        $meal = Meal::where('user_id', Auth::id())->findOrFail($id);
        $validatedData = $request->validate([
            'meal_time' => 'sometimes|date',
            'date'      => 'sometimes|date',
            'foods'     => 'sometimes|array',
            'foods.*.food_id'   => 'required_with:foods|exists:foods,id',
            'foods.*.quantity'  => 'required_with:foods|numeric',
        ]);

        if (isset($validatedData['meal_time'])) {
            $meal->meal_time = $validatedData['meal_time'];
            $meal->save();
        }

        if (isset($validatedData['foods'])) {
            // Prepare data for pivot table sync: [food_id => ['quantity' => value]]
            $foodData = [];
            foreach ($validatedData['foods'] as $foodItem) {
                $foodData[$foodItem['food_id']] = ['quantity' => $foodItem['quantity']];
            }
            $meal->foods()->sync($foodData);
        }

        return response()->json([
            'message' => 'Meal updated successfully.',
            'meal'    => $meal->load('foods'),
        ]);
    }

    // Delete a meal
    public function destroy($id)
    {
        $meal = Meal::where('user_id', Auth::id())->findOrFail($id);
        $meal->delete();

        return response()->json([
            'message' => 'Meal deleted successfully.'
        ]);
    }
}
