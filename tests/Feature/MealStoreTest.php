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

    /** A repeated meal is logged in one request with all its foods. */
    private function addMeal(array $foods)
    {
        return $this->actingAs($this->user)->postJson('/api/meals/add', [
            'date' => now()->toDateString(),
            'meal_time' => 'breakfast',
            'foods' => $foods,
        ]);
    }

    private function oats(): Food
    {
        return Food::create([
            'name' => 'OATS', 'name_en' => 'Oats', 'name_fr' => "Flocons d'avoine",
            'calories' => 389, 'protein' => 16.9, 'carbs' => 66.3, 'fats' => 6.9,
        ]);
    }

    public function test_several_foods_are_logged_as_one_meal(): void
    {
        $banana = $this->food();
        $oats = $this->oats();

        $this->addMeal([
            ['food_id' => $banana->id, 'quantity' => 120],
            ['food_id' => $oats->id, 'quantity' => 60],
        ])->assertCreated();

        $this->assertSame(1, Meal::count());
        $this->assertEqualsCanonicalizing([$banana->id, $oats->id], Meal::first()->foods->pluck('id')->all());
    }

    public function test_one_food_is_removed_and_the_others_stay(): void
    {
        $banana = $this->food();
        $oats = $this->oats();
        $meal = $this->addMeal([
            ['food_id' => $banana->id, 'quantity' => 120],
            ['food_id' => $oats->id, 'quantity' => 60],
        ])->json('meal');
        $bananaEntry = collect($meal['foods'])->firstWhere('id', $banana->id)['pivot']['id'];

        $this->actingAs($this->user)
            ->deleteJson("/api/meals/{$meal['id']}/food-entries/{$bananaEntry}")
            ->assertOk();

        $this->assertSame([$oats->id], Meal::find($meal['id'])->foods->pluck('id')->all());
    }

    public function test_removing_the_last_food_deletes_the_meal(): void
    {
        $meal = $this->add(['food_id' => $this->food()->id])->json('meal');

        $this->actingAs($this->user)
            ->deleteJson("/api/meals/{$meal['id']}/food-entries/{$meal['foods'][0]['pivot']['id']}")
            ->assertOk();

        $this->assertSame(0, Meal::count());
    }

    public function test_another_users_meal_or_entry_is_not_touched(): void
    {
        $meal = $this->add(['food_id' => $this->food()->id])->json('meal');
        $entry = $meal['foods'][0]['pivot']['id'];
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'x']);

        $this->actingAs($other)->deleteJson("/api/meals/{$meal['id']}/food-entries/{$entry}")->assertNotFound();
        $this->actingAs($this->user)->deleteJson("/api/meals/{$meal['id']}/food-entries/999999")->assertNotFound();

        $this->assertSame(1, Meal::first()->foods()->count());
    }
}
