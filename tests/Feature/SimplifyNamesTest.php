<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SimplifyNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_kind_of_seeded_food_gets_plain_names(): void
    {
        $user = User::create(['name' => 'Eater', 'email' => 'eater@example.test', 'password' => 'x']);

        // 2025 USDA seed, later given a source_name by the backfill.
        $butter = $this->food('BUTTER,WITH SALT', ['source_name' => 'BUTTER,WITH SALT', 'name_fr' => 'Beurre (salé)']);
        // The second 2025 food list: readable names, never translated.
        $pecans = $this->food('Nuts, pecans');
        // Scanned by a user: left as they named it.
        $scanned = $this->food('NUTELLA, nutella, pate', ['added_by' => $user->id]);

        $this->runMigration();

        $this->assertSame(['Butter, salted', 'Beurre salé'], $this->names('foods', $butter));
        $this->assertSame(['Pecans', 'Noix de pécan'], $this->names('foods', $pecans));
        $this->assertSame([null, null], $this->names('foods', $scanned));
        $this->assertSame('BUTTER,WITH SALT', DB::table('foods')->where('id', $butter)->value('name'));

        Sanctum::actingAs($user);
        $fr = collect($this->getJson('/api/foods/search', ['Accept-Language' => 'fr'])->json())->pluck('display_name', 'id');
        $this->assertSame('Noix de pécan', $fr[$pecans]);
        $en = collect($this->getJson('/api/foods/search', ['Accept-Language' => 'en'])->json())->pluck('display_name', 'id');
        $this->assertSame('Pecans', $en[$pecans]);
    }

    public function test_activities_get_plain_names_and_a_category(): void
    {
        // Seeded in 2025: the name was the text before the first comma.
        $old = $this->activity('Running', 'Running, general');
        // Seeded since September: the whole text is the name.
        $new = $this->activity('Running, general', 'Running, general');

        $this->runMigration();

        foreach ([$old, $new] as $id) {
            $row = DB::table('exercises')->find($id);
            $this->assertSame('Running', $row->name_en);
            $this->assertSame('Course à pied', $row->name_fr);
            $this->assertSame('Running and athletics', $row->category);
            $this->assertSame('Course et athlétisme', $row->category_fr);
        }

        Sanctum::actingAs(User::create(['name' => 'Runner', 'email' => 'runner@example.test', 'password' => 'x']));
        $activity = collect($this->getJson('/api/workouts/exercises', ['Accept-Language' => 'fr'])->json())->firstWhere('id', $old);
        $this->assertSame('Course à pied', $activity['display_name']);
        $this->assertSame('Course et athlétisme', $activity['display_category']);
        $this->assertSame('Running, general', $activity['description']);
    }

    public function test_gym_exercises_lose_program_codes(): void
    {
        $id = DB::table('gym_exercises')->insertGetId([
            'name' => 'HM Left Side Bird-Dog',
            'description' => '',
            'type' => 'Strength',
            'body_part' => 'Abdominals',
            'equipment' => 'Body Only',
            'level' => 'Beginner',
        ]);

        $this->runMigration();

        $this->assertSame(['Bird dog, left side', 'Bird dog, côté gauche'], $this->names('gym_exercises', $id));
    }

    public function test_every_plain_name_is_free_of_codes_and_symbols(): void
    {
        foreach (['food_names.csv' => 1, 'gym_exercise_names.csv' => 1, 'activity_names.csv' => 1] as $file => $first) {
            $handle = fopen(database_path("data/{$file}"), 'r');
            fgetcsv($handle);
            while (($fields = fgetcsv($handle)) !== false) {
                foreach (array_slice($fields, $first) as $name) {
                    $this->assertMatchesRegularExpression("/^[\\p{L}0-9][\\p{L}0-9 ,.'\\-]*$/u", $name, "{$file}: {$fields[0]}");
                }
            }
            fclose($handle);
        }
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_10_17_180000_simplify_food_and_exercise_names.php');
        $migration->down();
        $migration->up();
    }

    private function names(string $table, int $id): array
    {
        $row = DB::table($table)->find($id);

        return [$row->name_en, $row->name_fr];
    }

    private function food(string $name, array $extra = []): int
    {
        return DB::table('foods')->insertGetId($extra + [
            'name' => $name,
            'calories' => 100,
            'protein' => 1,
            'carbs' => 1,
            'fats' => 1,
        ]);
    }

    private function activity(string $name, string $description): int
    {
        return DB::table('exercises')->insertGetId([
            'name' => $name,
            'description' => $description,
            'duration' => '1 hour',
            'caloriesPerKg' => 8,
        ]);
    }
}
