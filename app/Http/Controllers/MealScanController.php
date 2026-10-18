<?php

namespace App\Http\Controllers;

use App\Services\MealScan\FoodMatcher;
use App\Services\MealScan\FoodVision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * POST /api/meals/scan: one camera frame in, the foods on it out, each with
 * where it is in the frame, an estimated portion and macros per 100 g.
 *
 * Foods are matched to the foods table so the app logs them by id. A food
 * the table doesn't have comes back with id 0 and the model's own estimate
 * per 100 g, which the app adds as the user's food when the meal is logged.
 */
class MealScanController extends Controller
{
    public function scan(Request $request, FoodMatcher $matcher): JsonResponse
    {
        $request->validate([
            'image' => 'required|file|mimetypes:image/jpeg,image/png|max:4096',
        ]);

        if (empty(config('services.anthropic.key'))) {
            return response()->json(['message' => 'Meal scanning is not set up on this server.'], 503);
        }

        $file = $request->file('image');
        try {
            $items = app(FoodVision::class)->detect(
                $file->get(),
                $file->getMimeType(),
                app()->getLocale(),
            );
        } catch (Throwable $e) {
            return $this->serverError($e, 'message', 502);
        }

        $userId = Auth::id();
        $foods = [];
        foreach (array_slice($items, 0, 8) as $item) {
            if (! is_array($item) || empty($item['name'])) {
                continue;
            }
            $food = $matcher->match(
                array_filter([$item['search_term'] ?? null, $item['name']]),
                $userId,
            );
            $box = $item['box'] ?? [];
            $per100 = $item['per_100g'] ?? [];
            $foods[] = [
                'label' => (string) $item['name'],
                'box' => [
                    'x' => $this->fraction($box['x'] ?? 0),
                    'y' => $this->fraction($box['y'] ?? 0),
                    'width' => $this->fraction($box['width'] ?? 0),
                    'height' => $this->fraction($box['height'] ?? 0),
                ],
                'grams' => round(min(max((float) ($item['grams'] ?? 0), 1), 2000)),
                'confidence' => $this->fraction($item['confidence'] ?? 0),
                'food' => $food
                    ? $food->only(['id', 'name', 'name_en', 'name_fr', 'display_name', 'calories', 'protein', 'carbs', 'fats'])
                    : [
                        'id' => 0,
                        'name' => (string) ($item['local_name'] ?? '') ?: (string) $item['name'],
                        'display_name' => (string) ($item['local_name'] ?? '') ?: (string) $item['name'],
                        'calories' => $this->per100($per100['calories'] ?? 0, 900),
                        'protein' => $this->per100($per100['protein'] ?? 0, 100),
                        'carbs' => $this->per100($per100['carbs'] ?? 0, 100),
                        'fats' => $this->per100($per100['fats'] ?? 0, 100),
                    ],
            ];
        }

        return response()->json(['foods' => $foods]);
    }

    private function fraction(mixed $value): float
    {
        return round(min(max((float) $value, 0), 1), 4);
    }

    private function per100(mixed $value, float $max): float
    {
        return round(min(max((float) $value, 0), $max), 1);
    }
}
