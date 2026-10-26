<?php

namespace App\Http\Controllers;

use App\Models\FavoriteFood;
use App\Models\Food;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FoodController extends Controller
{
    /**
     * The whole food list (~17k rows), downloaded by the app on start. Kept
     * small for slow networks: one name per food, in the request's language,
     * and macros rounded to one decimal (MySQL FLOAT columns otherwise come
     * out as 1.100000023841858). The route adds an ETag, so an app that
     * already has this list gets a 304 instead.
     */
    public function index(Request $request)
    {
        $userId = Auth::id();
        $french = app()->getLocale() === 'fr';

        $query = Food::query()
            ->where(function ($query) use ($userId) {
                $query->where('added_by', $userId)
                    ->orWhereNull('added_by');
            })
            ->whereRaw("name REGEXP '[^0-9]'");

        if ($request->filled('name')) {
            $query->where('name', 'like', "%{$request->name}%");
        }

        // Same rule as Food::display_name.
        $displayName = $french
            ? "CASE WHEN foods.name_fr IS NOT NULL AND foods.name_fr <> '' THEN foods.name_fr ELSE COALESCE(foods.name_en, foods.name) END"
            : 'COALESCE(foods.name_en, foods.name)';

        $foods = $query
            ->select('foods.id', 'foods.calories', 'foods.protein', 'foods.carbs', 'foods.fats')
            ->selectRaw("{$displayName} AS display_name")
            ->withCount([
                'meals as meals_count' => function ($query) use ($userId) {
                    $query->where('user_id', $userId);
                }
            ])
            ->selectRaw('(SELECT COUNT(*) FROM favorite_foods WHERE favorite_foods.food_id = foods.id AND favorite_foods.is_favorite = 1 AND favorite_foods.user_id = ?) AS is_favorite', [$userId])
            ->orderByDesc('is_favorite')
            ->orderByDesc('meals_count')
            ->orderBy('foods.id')
            ->toBase()
            ->get()
            ->map(fn ($food) => [
                'id' => (int) $food->id,
                'display_name' => $food->display_name,
                'calories' => round((float) $food->calories, 1),
                'protein' => round((float) $food->protein, 1),
                'carbs' => round((float) $food->carbs, 1),
                'fats' => round((float) $food->fats, 1),
                'is_favorite' => (int) $food->is_favorite,
            ]);

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
        $food = Food::where('id', $id)->where('added_by', Auth::id())->firstOrFail();
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
        $food = Food::where('id', $id)->where('added_by', Auth::id())->firstOrFail();
        $food->delete();

        return response()->json([
            'message' => 'Food item deleted successfully.'
        ]);
    }
}
