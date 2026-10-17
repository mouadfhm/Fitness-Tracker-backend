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
        $namesPath = database_path('data/gym_exercise_names.csv');

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

            $name = trim($row[1]);

            if (GymExercise::where('name', $name)->where('description', $row[2])->exists()) {
                $skipped++;
                continue;
            }

            $plain = $names[mb_strtolower($name)] ?? [];

            GymExercise::create([
                'name' => $name,
                'name_en' => $plain['name_en'] ?? null,
                'name_fr' => $plain['name_fr'] ?? null,
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
