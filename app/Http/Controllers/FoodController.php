<?php

namespace App\Http\Controllers;

use App\Models\FavoriteFood;
use App\Models\Food;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FoodController extends Controller
{
    public function index(Request $request)
    {
        $userId = Auth::id();

        $query = Food::query()
            ->where(function ($query) use ($userId) {
                $query->where('added_by', $userId)
                    ->orWhereNull('added_by');
            });

        if ($request->filled('name')) {
            $query->where('name', 'like', "%{$request->name}%");
        }

        $foods = $query
            ->select(
                'foods.id',
                'foods.name',
                'foods.calories',
                'foods.protein',
                'foods.carbs',
                'foods.fats',
                'foods.added_by',
            )
            ->withCount([
                'meals as meals_count' => function ($query) use ($userId) {
                    $query->where('user_id', $userId);
                }
            ])
            ->addSelect([
                DB::raw('(SELECT COUNT(*) FROM favorite_foods WHERE favorite_foods.food_id = foods.id AND favorite_foods.user_id = ' . $userId . ') AS is_favorite ')
            ])
            ->orderByDesc('is_favorite')
            ->orderByDesc('meals_count')
            ->get();

        return response()->json($foods);
    }

    public function addFavorite(Request $request, int $foodId)
    {
        $user = Auth::user();
        $food = Food::findOrFail($foodId);

        $favoriteFood = FavoriteFood::firstOrCreate(
            [
                'food_id' => $food->id,
                'user_id' => $user->id,
            ],
            [
                'is_favorite' => true,
            ]
        );

        $favoriteFood->update(['is_favorite' => true]);

        return response()->json([
            'message' => 'Favorite food added successfully.',
            'food' => $food,
            'favorite_food' => $favoriteFood,
        ]);
    }
    public function removeFavorite(Request $request, int $foodId)
    {
        $user = Auth::user();
        $food = Food::findOrFail($foodId);

        $favoriteFood = FavoriteFood::where('food_id', $food->id)
            ->where('user_id', $user->id)
            ->update(['is_favorite' => false]);

        return response()->json([
            'message' => 'Favorite food removed successfully.',
            'food' => $food,
            'favorite_food' => $favoriteFood,
        ]);
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

        $exists = Food::where('name', $validatedData['name'])->value('id');
        
        if (isset($exists)) {
            return response()->json(
                ['message' => 'Food already exists.'],
                400
            );
        } else {
            $validatedData['added_by'] = Auth::id();
            // dd($validatedData);
            $food = Food::create($validatedData);
        }

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
