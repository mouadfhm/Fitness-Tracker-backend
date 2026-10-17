<?php

namespace Database\Seeders;

use App\Models\Food;
use App\Support\FoodNameCleaner;
use Illuminate\Database\Seeder;

class FoodSeeder extends Seeder
{
    public function run()
    {
        $filePath = database_path('data/foods_translated.csv');

        if (!is_file($filePath)) {
            $this->command->error(
                "Missing {$filePath}. Run `php artisan foods:translate` first to generate it."
            );

            return;
        }

        // Plain English and French names shown in the app, by USDA name.
        $plainNames = $this->plainNames();

        $handle = fopen($filePath, 'r');
        $header = fgetcsv($handle);
        $index = array_flip($header);

        $created = 0;
        $skipped = 0;

        while (($fields = fgetcsv($handle)) !== false) {
            // Re-clean from the USDA name so newer abbreviation expansions
            // apply without regenerating the CSV.
            $sourceName = $fields[$index['source_name']] ?? '';
            $nameEn = ($sourceName !== '' ? FoodNameCleaner::clean($sourceName) : '')
                ?: ($fields[$index['name_en']] ?? '');

            if ($nameEn === '' || Food::where('name', $nameEn)->exists()) {
                $skipped++;
                continue;
            }

            $plain = $plainNames[mb_strtolower($sourceName)] ?? null;

            Food::create([
                'name' => $nameEn,
                'name_en' => $plain['name_en'] ?? $nameEn,
                'name_fr' => $plain['name_fr'] ?? ($fields[$index['name_fr']] ?? null),
                'source_name' => $fields[$index['source_name']] ?? null,
                'calories' => (float) ($fields[$index['calories']] ?? 0),
                'protein' => (float) ($fields[$index['protein']] ?? 0),
                'carbs' => (float) ($fields[$index['carbs']] ?? 0),
                'fats' => (float) ($fields[$index['fats']] ?? 0),
            ]);
            $created++;
        }
        fclose($handle);

        $this->command->info("Foods table seeded: {$created} created, {$skipped} skipped (duplicates/empty).");
    }

    /**
     * @return array<string, array{name_en: string, name_fr: string}> lowercase key => names
     */
    protected function plainNames(): array
    {
        $path = database_path('data/food_names.csv');
        if (!is_file($path)) {
            return [];
        }

        $names = [];
        $handle = fopen($path, 'r');
        fgetcsv($handle);
        while (($fields = fgetcsv($handle)) !== false) {
            if (count($fields) === 3) {
                $names[mb_strtolower($fields[0])] ??= ['name_en' => $fields[1], 'name_fr' => $fields[2]];
            }
        }
        fclose($handle);

        return $names;
    }
}
