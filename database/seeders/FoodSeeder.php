<?php

namespace Database\Seeders;

use App\Models\Food;
use Illuminate\Database\Seeder;

class FoodSeeder extends Seeder
{
    public function run()
    {
        $filePath = storage_path('/assets/ABBREV.csv'); // Path to CSV

        if (!file_exists($filePath)) {
            $this->command->error("CSV file not found at $filePath");
            return;
        }

        $file = fopen($filePath, "r");
        $isFirstRow = true;

        while (($row = fgetcsv($file, 1000, ",")) !== FALSE) {
            if ($isFirstRow) {
                $isFirstRow = false; // Skip CSV header
                continue;
            }

            // Check if the food already exists by its name (or other unique field)
            if (Food::where('name', $row[2])->exists()) {
                $this->command->info("Skipping duplicate food: " . $row[2]);
                continue;  // Skip the duplicate food
            }

            // Insert the food if not a duplicate
            Food::create([
                'name' => $row[2],
                'calories' => $row[4],
                'protein' => $row[5],
                'carbs' => $row[8],
                'fats' => (float) $row[44] + (float) $row[45] + (float) $row[46]
            ]);
        }

        fclose($file);
        $this->command->info('Foods table seeded successfully from CSV!');
    }
}
