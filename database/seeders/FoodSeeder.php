<?php

namespace Database\Seeders;

use App\Models\Food;
use Illuminate\Database\Seeder;

class FoodSeeder extends Seeder
{
    public function run()
    {
        $filePaths = [
            // database_path('data/csv_result.csv'),
            database_path('data/ABBREV.csv'),
            // Add more file paths as needed
        ];

        foreach ($filePaths as $filePath) {
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($filePath == database_path('data/ABBREV.csv')) {
                // Remove the header row (first line) since your CSV already has column names
                $header = array_shift($lines);

                foreach ($lines as $line) {
                    $line = trim($line);

                    // Parse the CSV line
                    $row = str_getcsv($line, ',');

                    // Clean any surrounding quotes from each field
                    $row = array_map(function ($value) {
                        return trim($value, " '");
                    }, $row);

                    // Convert "t" (trace amounts) to null if needed
                    $fields = array_map(function ($field) {
                        return ($field === 't') ? null : $field;
                    }, $row);

                    // Check for duplicate food entries based on the 'name' field (index 1)
                    if (Food::where('name', $fields[2])->exists()) {
                        $this->command->info("Skipping duplicate food: " . $fields[2]);
                        continue;  // Skip the duplicate food
                    }

                    // Make sure required fields are available:
                    // In your CSV:
                    // index 1: name
                    // index 3: calories
                    // index 4: total_fat
                    // index 5: carbohydrate
                    // index 6: protein
                    if (isset($fields[1], $fields[3], $fields[4], $fields[5], $fields[6])) {
                        Food::create([
                            'name'     => $fields[2],
                            'calories' => (float) $fields[4],
                            // Remove 'g' from nutrient values and convert to float
                            'fats'     => (float) $fields[45] + (float) $fields[46] + (float) $fields[47],
                            'carbs'    => (float) $fields[8],
                            'protein'  => (float) $fields[5]
                        ]);
                    } else {
                        $this->command->warn("Skipping row due to missing data: " . implode(',', $fields[2]));
                    }
                }
            }elseif($filePath == database_path('data/csv_result.csv')) {
                // Remove the header row (first line) since your CSV already has column names
                $header = array_shift($lines);

                foreach ($lines as $line) {
                    $line = trim($line);

                    // Parse the CSV line
                    $row = str_getcsv($line, ',');

                    // Clean any surrounding quotes from each field
                    $row = array_map(function ($value) {
                        return trim($value, " '");
                    }, $row);

                    // Convert "t" (trace amounts) to null if needed
                    $fields = array_map(function ($field) {
                        return ($field === 't') ? null : $field;
                    }, $row);

                    // Check for duplicate food entries based on the 'name' field (index 1)
                    if (Food::where('name', $fields[1])->exists()) {
                        $this->command->info("Skipping duplicate food: " . $fields[1]);
                        continue;  // Skip the duplicate food
                    }

                    // Make sure required fields are available:
                    // In your CSV:
                    // index 1: name
                    // index 3: calories
                    // index 4: total_fat
                    // index 5: carbohydrate
                    // index 6: protein
                    if (isset($fields[1], $fields[3], $fields[4], $fields[5], $fields[6])) {
                        Food::create([
                            'name'     => $fields[1],
                            'calories' => (float) $fields[3],
                            // Remove 'g' from nutrient values and convert to float
                            'fats'     => (float) str_replace('g', '', $fields[4]),
                            'carbs'    => (float) str_replace('g', '', $fields[5]),
                            'protein'  => (float) str_replace('g', '', $fields[6])
                        ]);
                    } else {
                        $this->command->warn("Skipping row due to missing data: " . implode(',', $fields));
                    }
                }
            }
        }

        $this->command->info('Foods table seeded successfully from CSV!');
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Remove the header row (first line) since your CSV already has column names
        $header = array_shift($lines);

        foreach ($lines as $line) {
            $line = trim($line);

            // Parse the CSV line
            $row = str_getcsv($line, ',');

            // Clean any surrounding quotes from each field
            $row = array_map(function ($value) {
                return trim($value, " '");
            }, $row);

            // Convert "t" (trace amounts) to null if needed
            $fields = array_map(function ($field) {
                return ($field === 't') ? null : $field;
            }, $row);

            // Check for duplicate food entries based on the 'name' field (index 1)
            if (Food::where('name', $fields[1])->exists()) {
                $this->command->info("Skipping duplicate food: " . $fields[1]);
                continue;  // Skip the duplicate food
            }

            // Make sure required fields are available:
            // In your CSV:
            // index 1: name
            // index 3: calories
            // index 4: total_fat
            // index 5: carbohydrate
            // index 6: protein
            if (isset($fields[1], $fields[3], $fields[4], $fields[5], $fields[6])) {
                Food::create([
                    'name'     => $fields[1],
                    'calories' => (float) $fields[3],
                    // Remove 'g' from nutrient values and convert to float
                    'fats'     => (float) str_replace('g', '', $fields[4]),
                    'carbs'    => (float) str_replace('g', '', $fields[5]),
                    'protein'  => (float) str_replace('g', '', $fields[6])
                ]);
            } else {
                $this->command->warn("Skipping row due to missing data: " . implode(',', $fields));
            }
        }

        $this->command->info('Foods table seeded successfully from CSV!');
    }
}
