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
        $translationsPath = database_path('data/exercises_translated.csv');

        if (!file_exists($filePath)) {
            $this->command->error("CSV file not found at $filePath");
            return;
        }

        $translations = $this->loadTranslations($translationsPath);

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

            Exercise::create([
                'name' => $name,
                'name_fr' => $translations[strtolower($name)] ?? null,
                'description' => $name,
                'duration' => '1 hour',
                'caloriesPerKg' => $row[5],
            ]);
            $created++;
        }

        fclose($file);
        $this->command->info("Exercise table seeded: {$created} created, {$skipped} skipped (duplicates/empty).");
    }

    /**
     * @return array<string, string> lowercase name => French translation
     */
    protected function loadTranslations(string $path): array
    {
        if (!is_file($path)) {
            $this->command->warn("No translations file at {$path}, seeding without name_fr.");
            return [];
        }

        $translations = [];
        $handle = fopen($path, 'r');
        fgetcsv($handle);
        while (($fields = fgetcsv($handle)) !== false) {
            if (($fields[1] ?? '') !== '') {
                $translations[strtolower($fields[0])] = $fields[1];
            }
        }
        fclose($handle);

        return $translations;
    }
}
