<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackfillFrenchNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_foods_seeded_before_translations_get_french_names(): void
    {
        $user = User::create(['name' => 'Eater', 'email' => 'eater@example.test', 'password' => 'x']);

        // As the 2025 seeder stored them: raw USDA description, no translations.
        $butter = $this->food('BUTTER,WITH SALT');
        // A translated row whose French name was edited by hand.
        $cheese = $this->food('Blue Cheese', ['name_en' => 'Blue Cheese', 'name_fr' => 'Bleu d\'Auvergne']);
        // Added from a barcode scan: not in the dataset, left alone.
        $scanned = $this->food('BUTTER,WHIPPED,W/ SALT', ['added_by' => $user->id]);

        $this->runMigration();

        $row = DB::table('foods')->find($butter);
        $this->assertSame('Beurre (salé)', $row->name_fr);
        $this->assertSame('Butter (With Salt)', $row->name_en);
        $this->assertSame('BUTTER,WITH SALT', $row->source_name);
        $this->assertSame('Bleu d\'Auvergne', DB::table('foods')->find($cheese)->name_fr);
        $this->assertNull(DB::table('foods')->find($scanned)->name_fr);

        Sanctum::actingAs($user);
        $names = collect($this->getJson('/api/foods/search', ['Accept-Language' => 'fr'])->json())
            ->pluck('display_name', 'id');
        $this->assertSame('Beurre (salé)', $names[$butter]);

        $names = collect($this->getJson('/api/foods/search', ['Accept-Language' => 'en'])->json())
            ->pluck('display_name', 'id');
        $this->assertSame('Butter (With Salt)', $names[$butter]);
    }

    public function test_activities_and_gym_exercises_get_french_names(): void
    {
        $activity = DB::table('exercises')->insertGetId([
            'name' => 'Cycling, mountain bike, bmx',
            'description' => 'Cycling, mountain bike, bmx',
            'duration' => '1 hour',
            'caloriesPerKg' => 8.5,
        ]);
        $gym = DB::table('gym_exercises')->insertGetId([
            'name' => 'Partner plank band row',
            'description' => '',
            'type' => 'Strength',
            'body_part' => 'Abdominals',
            'equipment' => 'Bands',
            'level' => 'Intermediate',
        ]);

        $this->runMigration();

        $this->assertSame('Cyclisme, VTT, BMX', DB::table('exercises')->where('id', $activity)->value('name_fr'));
        $this->assertSame(
            'Rameur avec bande élastique en position de planche à deux',
            DB::table('gym_exercises')->where('id', $gym)->value('name_fr'),
        );
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_10_17_120000_backfill_french_names.php');
        $migration->up();
    }

    private function food(string $name, array $extra = []): int
    {
        return DB::table('foods')->insertGetId($extra + [
            'name' => $name,
            'calories' => 717,
            'protein' => 0.85,
            'carbs' => 0.06,
            'fats' => 81.11,
        ]);
    }
}
