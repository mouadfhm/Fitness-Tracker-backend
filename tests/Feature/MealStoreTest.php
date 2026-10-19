<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\Meal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/meals/add. Foods stored with a USDA-style name were sent by their
 * plain name, matched nothing, and were logged as empty meals.
 */
class MealStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => 'Eater', 'email' => 'eat@example.test', 'password' => 'x']);
    }

    private function food(): Food
    {
        return Food::create([
            'name' => 'BANANAS,RAW', 'name_en' => 'Banana', 'name_fr' => 'Banane',
            'calories' => 89, 'protein' => 1.1, 'carbs' => 22.8, 'fats' => 0.3,
        ]);
    }

    private function add(array $food)
    {
        return $this->actingAs($this->user)->postJson('/api/meals/add', [
            'date' => now()->toDateString(),
            'meal_time' => 'lunch',
            'foods' => [array_merge(['quantity' => 120], $food)],
        ]);
    }

    public function test_food_id_is_logged(): void
    {
        $food = $this->food();

        $this->add(['name' => 'Banana', 'food_id' => $food->id])->assertCreated();

        $this->assertSame([$food->id], Meal::first()->foods->pluck('id')->all());
    }

    public function test_plain_english_and_french_names_are_found(): void
    {
        $food = $this->food();

        $this->add(['name' => 'Banana'])->assertCreated();
        $this->add(['name' => 'Banane'])->assertCreated();

        Meal::all()->each(fn ($meal) => $this->assertSame([$food->id], $meal->foods->pluck('id')->all()));
    }

    public function test_unknown_name_is_refused_without_an_empty_meal(): void
    {
        $this->add(['name' => 'Nothing like this'])->assertStatus(422);

        $this->assertSame(0, Meal::count());
    }
}
