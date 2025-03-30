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
        $filePath = [database_path('data/megaGymDataset.csv')]; // Path to CSV
        foreach ($filePath as $filePath) {
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
                if (GymExercise::where('name', $row[1])->where('description',$row[2])->exists()) {
                    $this->command->info("Skipping duplicate Exercise: " . $row[1]);
                    continue;  // Skip the duplicate food
                }

                // Insert the food if not a duplicate
                GymExercise::create([
                    'name' => $row[1],
                    'description' => $row[2],
                    'type' => $row[3],
                    'body_part' => $row[4],
                    'equipment' => $row[5],
                    'level' => $row[6],
                ]);    
            }

            fclose($file);
            $this->command->info('Gym Exercise table seeded successfully from CSV!');
        }

    }
}
