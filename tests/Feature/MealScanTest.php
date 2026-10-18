<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\User;
use App\Services\MealScan\FoodMatcher;
use App\Services\MealScan\FoodVision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * POST /api/meals/scan with the vision model replaced by a fake that answers
 * with fixed foods, so these cover matching, clamping and the error paths.
 */
class MealScanTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** What the fake model answers, and what it was asked. */
    private array $answer = [];

    private ?array $asked = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'test-key']);
        $this->user = User::create(['name' => 'Scanner', 'email' => 'scan@example.test', 'password' => 'x']);

        $this->app->instance(FoodVision::class, new class($this) implements FoodVision
        {
            public function __construct(private MealScanTest $test) {}

            public function detect(string $image, string $mediaType, string $language): array
            {
                return $this->test->answer($mediaType, $language);
            }
        });
    }

    public function answer(string $mediaType, string $language): array
    {
        $this->asked = compact('mediaType', 'language');

        return $this->answer;
    }

    private function food(string $nameEn, array $extra = []): Food
    {
        return Food::create(array_merge([
            'name' => $nameEn, 'name_en' => $nameEn, 'calories' => 50,
            'protein' => 1, 'carbs' => 10, 'fats' => 0.5,
        ], $extra));
    }

    private function item(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Banana, raw', 'search_term' => 'banana', 'local_name' => 'Banane',
            'box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4],
            'grams' => 118, 'confidence' => 0.92,
            'per_100g' => ['calories' => 89, 'protein' => 1.1, 'carbs' => 22.8, 'fats' => 0.3],
        ], $overrides);
    }

    private function scan(array $headers = [])
    {
        return $this->actingAs($this->user, 'sanctum')
            ->withHeaders($headers)
            ->post('/api/meals/scan', ['image' => UploadedFile::fake()->image('frame.jpg', 64, 48)], ['Accept' => 'application/json']);
    }

    public function test_a_seen_food_comes_back_as_the_matching_food_with_its_box_and_grams(): void
    {
        $this->food('Banana pepper, raw');
        $banana = $this->food('Banana, raw', ['calories' => 89, 'name_fr' => 'Banane, crue']);
        $this->answer = [$this->item()];

        $this->scan(['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertJsonPath('foods.0.food.id', $banana->id)
            ->assertJsonPath('foods.0.food.display_name', 'Banane, crue')
            ->assertJsonPath('foods.0.food.calories', 89)
            ->assertJsonPath('foods.0.grams', 118)
            ->assertJsonPath('foods.0.confidence', 0.92)
            ->assertJsonPath('foods.0.box', ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4]);

        $this->assertSame(['mediaType' => 'image/jpeg', 'language' => 'fr'], $this->asked);
    }

    public function test_a_food_missing_from_the_table_comes_back_with_id_0_and_the_estimate(): void
    {
        $this->answer = [$this->item(['name' => 'Chicken tagine', 'search_term' => 'tagine', 'local_name' => 'Tajine de poulet'])];

        $this->scan()
            ->assertOk()
            ->assertJsonPath('foods.0.food.id', 0)
            ->assertJsonPath('foods.0.food.name', 'Tajine de poulet')
            ->assertJsonPath('foods.0.food.calories', 89)
            ->assertJsonPath('foods.0.food.carbs', 22.8);
    }

    public function test_out_of_range_numbers_are_clamped(): void
    {
        $this->answer = [$this->item([
            'box' => ['x' => -0.2, 'y' => 1.4, 'width' => 2, 'height' => 0.5],
            'grams' => 99999, 'confidence' => 7,
            'search_term' => 'nothing', 'name' => 'Nothing here',
            'per_100g' => ['calories' => 5000, 'protein' => -3, 'carbs' => 10, 'fats' => 1],
        ])];

        $this->scan()
            ->assertOk()
            ->assertJsonPath('foods.0.box', ['x' => 0, 'y' => 1, 'width' => 1, 'height' => 0.5])
            ->assertJsonPath('foods.0.grams', 2000)
            ->assertJsonPath('foods.0.confidence', 1)
            ->assertJsonPath('foods.0.food.calories', 900)
            ->assertJsonPath('foods.0.food.protein', 0);
    }

    public function test_it_is_unavailable_without_an_api_key(): void
    {
        config(['services.anthropic.key' => null]);

        $this->scan()->assertStatus(503);
        $this->assertNull($this->asked);
    }

    public function test_it_needs_an_image(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/meals/scan', ['image' => UploadedFile::fake()->create('notes.txt', 3, 'text/plain')])
            ->assertStatus(422);
        $this->assertNull($this->asked);
    }

    public function test_it_needs_a_signed_in_user(): void
    {
        $this->postJson('/api/meals/scan')->assertUnauthorized();
    }

    public function test_the_matcher_prefers_the_plain_food_and_whole_words(): void
    {
        $this->food('Eggnog');
        $eggs = $this->food('Eggs, hard-boiled');
        $this->food('Salmon oil');
        $salmon = $this->food('Atlantic salmon, raw');
        $this->food('Lemon peel, raw');
        $lemon = $this->food('Lemon, raw, peeled');

        $matcher = new FoodMatcher;
        $this->assertSame($eggs->id, $matcher->match(['Egg, boiled', 'egg'], null)?->id);
        $this->assertSame($salmon->id, $matcher->match(['Salmon, baked', 'salmon'], null)?->id);
        $this->assertSame($lemon->id, $matcher->match(['Lemon', 'lemon'], null)?->id);
        $this->assertNull($matcher->match(['Chicken tagine', 'tagine'], null));
    }

    public function test_the_matcher_sees_the_users_own_foods_but_not_other_peoples(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'x']);
        $this->food('Harira soup', ['added_by' => $other->id]);
        $mine = $this->food('Harira', ['added_by' => $this->user->id]);

        $matcher = new FoodMatcher;
        $this->assertSame($mine->id, $matcher->match(['harira'], $this->user->id)?->id);
        $this->assertSame('Harira soup', $matcher->match(['harira'], $other->id)?->name_en);
        $this->assertNull($matcher->match(['harira'], null));
    }
}
