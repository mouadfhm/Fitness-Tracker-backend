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
        //
        $filePath = [storage_path('app/public/exercise_dataset.csv')]; // Path to CSV
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
                if (Exercise::where('name', explode(',', $row[0])[0])->where('description',$row[0])->exists()) {
                    $this->command->info("Skipping duplicate Exercise: " . explode(',', $row[0])[0]);
                    continue;  // Skip the duplicate food
                }

                // Insert the food if not a duplicate
                Exercise::create([
                    'name' => explode(',', $row[0])[0],
                    'description' => $row[0],
                    'duration' => '1 hour',
                    'caloriesPerKg' => $row[5],
                ]);    
            }

            fclose($file);
            $this->command->info('Exercise table seeded successfully from CSV!');
        }

    }
}
