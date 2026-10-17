<?php

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ExerciseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $filePath = database_path('data/exercise_dataset.csv');
        $namesPath = database_path('data/activity_names.csv');

        if (!file_exists($filePath)) {
            $this->command->error("CSV file not found at $filePath");
            return;
        }

        $names = $this->loadNames($namesPath);

        $file = fopen($filePath, "r");
        $isFirstRow = true;
        $created = 0;
        $skipped = 0;

        while (($row = fgetcsv($file, 1000, ",")) !== FALSE) {
            if ($isFirstRow) {
                $isFirstRow = false; // Skip CSV header
                continue;
            }

            // Use the full activity description as the name (was previously
            // truncated at the first comma, e.g. "Running" for 16 different
            // running variants — not distinguishable in a list).
            $name = trim($row[0]);

            if ($name === '' || Exercise::where('name', $name)->exists()) {
                $skipped++;
                continue;
            }

            $plain = $names[mb_strtolower($name)] ?? [];

            Exercise::create([
                'name' => $name,
                'name_en' => $plain['name_en'] ?? null,
                'name_fr' => $plain['name_fr'] ?? null,
                'category' => $plain['category_en'] ?? null,
                'category_fr' => $plain['category_fr'] ?? null,
                'description' => $name,
                'duration' => '1 hour',
                // Column 5 ("Calories per kg") divides by pounds twice; work
                // it out from the 130 lb (58.97 kg) column instead.
                'caloriesPerKg' => (float) $row[1] / (130 * 0.45359237),
            ]);
            $created++;
        }

        fclose($file);
        $this->command->info("Exercise table seeded: {$created} created, {$skipped} skipped (duplicates/empty).");
    }

    /**
     * @return array<string, array<string, string>> lowercase source name => row
     */
    protected function loadNames(string $path): array
    {
        if (!is_file($path)) {
            $this->command->warn("No names file at {$path}, seeding with the source names only.");
            return [];
        }

        $names = [];
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        while (($fields = fgetcsv($handle)) !== false) {
            if (count($fields) === count($header)) {
                $row = array_combine($header, $fields);
                $names[mb_strtolower($fields[0])] ??= $row;
            }
        }
        fclose($handle);

        return $names;
    }
}
