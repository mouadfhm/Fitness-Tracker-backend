<?php

namespace App\Http\Controllers;

use App\Models\Meal;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class MealController extends Controller
{
    // List all meals for the authenticated user
    public function index()
    {
        $meals = Auth::user()->meals()->with('foods')->get();
        return response()->json($meals);
    }

    // Log a new meal with foods and their quantities
    public function store(Request $request)
    {
        try {
        $validatedData = $request->validate([
            'meal_time' => 'required|string',
            'foods'     => 'required|array',
            'foods.*.food_id'   => 'required|exists:foods,id',
            'foods.*.quantity'  => 'required|numeric',
        ]);
    } catch (ValidationException $e) {
        return response()->json(['message' => $e->getMessage()], 400);
    }
    try {
        $meal = Meal::create([
            'user_id'   => Auth::id(),
            'meal_time' => $validatedData['meal_time'],
        ]);
    } catch (Exception $e) {
        return response()->json(['message' => $e->getMessage()], 400);
    }
    try {
        // Attach foods with pivot data (quantity)
        foreach ($validatedData['foods'] as $foodItem) {
            $meal->foods()->attach($foodItem['food_id'], ['quantity' => $foodItem['quantity']]);
        }
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
