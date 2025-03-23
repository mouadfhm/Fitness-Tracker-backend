<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Achievement;

class AchievementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $achievements = [
            // meal achievements
            ['name' => 'First bite', 'description' => 'Log your first meal', 'target' => 1, 'type' => 'meals'],
            ['name' => 'Dietitian', 'description' => 'Log week of meals', 'target' => 7, 'type' => 'streak'],
            ['name' => 'Bulk Monster', 'description' => 'Eat 3000 calories in a day', 'target' => 3000, 'type' => 'calories'],

            // workout achievements
            ['name' => 'First Step', 'description' => 'Log your first workout', 'target' => 1, 'type' => 'workouts'],
            ['name' => 'Never Forget Cardio', 'description' => 'Log running workout', 'target' => 1, 'type' => 'cardio'],
            ['name' => 'Consistency King', 'description' => 'Workout 5 days in a row', 'target' => 5, 'type' => 'streak'],
            ['name' => 'Fat Burner', 'description' => 'Burn 5000 calories', 'target' => 5000, 'type' => 'calories'],

            // progress achievements
            ['name' => 'Making progress', 'description' => 'Log your first weight update', 'target' => 1, 'type' => 'progress'],
            ['name' => 'Weight loss', 'description' => 'Lose 5 kg', 'target' => 5, 'type' => 'weight'],
            ['name' => 'Muscle Gain', 'description' => 'Gain 2 kg of muscle', 'target' => 2, 'type' => 'muscle'],
        ];

        foreach ($achievements as $achievement) {
            Achievement::create($achievement);
        }
    }
}
