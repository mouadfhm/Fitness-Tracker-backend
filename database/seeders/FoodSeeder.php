<?php

namespace Database\Seeders;

use App\Models\Food;
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

        $handle = fopen($filePath, 'r');
        $header = fgetcsv($handle);
        $index = array_flip($header);

        $created = 0;
        $skipped = 0;

        while (($fields = fgetcsv($handle)) !== false) {
            $nameEn = $fields[$index['name_en']] ?? '';

            if ($nameEn === '' || Food::where('name', $nameEn)->exists()) {
                $skipped++;
                continue;
            }

            Food::create([
                'name' => $nameEn,
                'name_en' => $nameEn,
                'name_fr' => $fields[$index['name_fr']] ?? null,
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
}
