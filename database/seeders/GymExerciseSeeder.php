<?php

namespace Database\Seeders;

use App\Models\GymExercise;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class GymExerciseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $filePath = database_path('data/megaGymDataset.csv');
        $translationsPath = database_path('data/gym_exercises_translated.csv');

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

            $name = trim($row[1]);

            if (GymExercise::where('name', $name)->where('description', $row[2])->exists()) {
                $skipped++;
                continue;
            }

            GymExercise::create([
                'name' => $name,
                'name_fr' => $translations[strtolower($name)] ?? null,
                'description' => $row[2],
                'type' => $row[3],
                'body_part' => $row[4],
                'equipment' => $row[5],
                'level' => $row[6],
            ]);
            $created++;
        }

        fclose($file);
        $this->command->info("Gym Exercise table seeded: {$created} created, {$skipped} skipped (duplicates).");
    }

    /**
     * @return array<string, string> lowercase title => French translation
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
