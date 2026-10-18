<?php

namespace App\Services\MealScan;

/**
 * Looks at one photo of a meal and says what is on it.
 */
interface FoodVision
{
    /**
     * Each item: name (generic English food name), search_term, local_name
     * (in $language), box {x, y, width, height} as 0..1 fractions of the
     * photo, grams, confidence (0..1) and per_100g {calories, protein,
     * carbs, fats} as a fallback when the food isn't in the foods table.
     *
     * @return list<array<string, mixed>>
     */
    public function detect(string $image, string $mediaType, string $language): array;
}
