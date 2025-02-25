<?php

namespace App\Http\Controllers;

use App\Models\Food;
use Illuminate\Http\Request;

class FoodController extends Controller
{
    // List all food items
    public function index( Request $request)
    {
        $query = request('query');
        if($request->has('name')) {
            $foods = Food::where('name', 'like', '%' . $request->name . '%')->get();
            return response()->json($foods);
        } else {
            $foods = Food::all();
            return response()->json($foods);
        }
    }

    // Create a new food item
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name'     => 'required|string|max:255',
            'calories' => 'required|numeric',
            'protein'  => 'required|numeric',
            'carbs'    => 'required|numeric',
            'fats'     => 'required|numeric',
        ]);

        $food = Food::create($validatedData);

        return response()->json([
            'message' => 'Food item created successfully.',
            'food'    => $food,
        ], 201);
    }

    // Show details for a specific food item
    public function show($id)
    {
        $food = Food::findOrFail($id);
        return response()->json($food);
    }

    // Update a food item
    public function update(Request $request, $id)
    {
        $food = Food::findOrFail($id);
        $validatedData = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'calories' => 'sometimes|numeric',
            'protein'  => 'sometimes|numeric',
            'carbs'    => 'sometimes|numeric',
            'fats'     => 'sometimes|numeric',
        ]);

        $food->update($validatedData);

        return response()->json([
            'message' => 'Food item updated successfully.',
            'food'    => $food,
        ]);
    }

    // Delete a food item
    public function destroy($id)
    {
        $food = Food::findOrFail($id);
        $food->delete();

        return response()->json([
            'message' => 'Food item deleted successfully.'
        ]);
    }
}
