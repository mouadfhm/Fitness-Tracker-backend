<?php

namespace Tests\Feature;

use App\Models\FavoriteFood;
use App\Models\Food;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/foods/search, the full list the app downloads on start. It was a
 * few MB of uncompressed JSON, which took a long time on a slow network.
 */
class FoodListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => 'Eater', 'email' => 'eat@example.test', 'password' => 'x']);
    }

    private function seedFoods(int $count = 40): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Food::create([
                'name' => "FOOD {$i},RAW", 'name_en' => "Food {$i}", 'name_fr' => "Aliment {$i}",
                'calories' => 89.1, 'protein' => 1.1, 'carbs' => 22.8, 'fats' => 0.3,
            ]);
        }
    }

    private function list(array $headers = [])
    {
        return $this->actingAs($this->user)->get('/api/foods/search', $headers + ['Accept' => 'application/json']);
    }

    public function test_each_food_has_one_name_in_the_request_language_and_rounded_macros(): void
    {
        $this->seedFoods(1);

        $this->list(['Accept-Language' => 'fr'])->assertOk()->assertExactJson([[
            'id' => Food::first()->id, 'display_name' => 'Aliment 1',
            'calories' => 89.1, 'protein' => 1.1, 'carbs' => 22.8, 'fats' => 0.3, 'is_favorite' => 0,
        ]]);
        $this->assertSame('Food 1', $this->list(['Accept-Language' => 'en'])->json('0.display_name'));
    }

    public function test_names_fall_back_like_display_name(): void
    {
        Food::create(['name' => 'My shake', 'calories' => 1, 'protein' => 1, 'carbs' => 1, 'fats' => 1, 'added_by' => $this->user->id]);
        Food::create(['name' => 'APPLES,RAW', 'name_en' => 'Apple', 'name_fr' => '', 'calories' => 1, 'protein' => 1, 'carbs' => 1, 'fats' => 1]);

        $names = $this->list(['Accept-Language' => 'fr'])->json('*.display_name');

        $this->assertEqualsCanonicalizing(['My shake', 'Apple'], $names);
    }

    public function test_favourites_come_first(): void
    {
        $this->seedFoods(3);
        $favourite = Food::orderByDesc('id')->first();
        FavoriteFood::create(['food_id' => $favourite->id, 'user_id' => $this->user->id, 'is_favorite' => true]);

        $first = $this->list()->json('0');

        $this->assertSame([$favourite->id, 1], [$first['id'], $first['is_favorite']]);
    }

    public function test_the_list_is_gzipped_for_clients_that_accept_it(): void
    {
        $this->seedFoods();

        $plain = $this->list()->assertOk();
        $zipped = $this->list(['Accept-Encoding' => 'gzip, deflate'])->assertOk();

        $this->assertNull($plain->headers->get('Content-Encoding'));
        $this->assertSame('gzip', $zipped->headers->get('Content-Encoding'));
        $this->assertSame($plain->getContent(), gzdecode($zipped->getContent()));
        $this->assertLessThan(strlen($plain->getContent()), strlen($zipped->getContent()));
    }

    public function test_an_unchanged_list_is_a_304(): void
    {
        $this->seedFoods(2);

        $etag = $this->list()->assertOk()->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->list(['If-None-Match' => $etag])->assertStatus(304)->assertContent('');

        // Favouriting changes the list, so the old ETag no longer matches.
        FavoriteFood::create(['food_id' => Food::first()->id, 'user_id' => $this->user->id, 'is_favorite' => true]);
        $this->list(['If-None-Match' => $etag])->assertOk();
    }

    public function test_each_language_has_its_own_etag(): void
    {
        $this->seedFoods(2);

        $this->assertNotSame(
            $this->list(['Accept-Language' => 'en'])->headers->get('ETag'),
            $this->list(['Accept-Language' => 'fr'])->headers->get('ETag'),
        );
    }
}
