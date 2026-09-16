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
            ['name' => 'First bite', 'name_fr' => 'Première bouchée', 'description' => 'Log your first meal', 'description_fr' => 'Enregistrez votre premier repas', 'target' => 1, 'type' => 'meals'],
            ['name' => 'Dietitian', 'name_fr' => 'Diététicien', 'description' => 'Log week of meals', 'description_fr' => 'Enregistrez une semaine de repas', 'target' => 7, 'type' => 'streak'],
            ['name' => 'Bulk Monster', 'name_fr' => 'Monstre de la prise de masse', 'description' => 'Eat 3000 calories in a day', 'description_fr' => 'Consommez 3000 calories en une journée', 'target' => 3000, 'type' => 'calories'],

            // workout achievements
            ['name' => 'First Step', 'name_fr' => 'Premier pas', 'description' => 'Log your first workout', 'description_fr' => 'Enregistrez votre première séance', 'target' => 1, 'type' => 'workouts'],
            ['name' => 'Never Forget Cardio', 'name_fr' => 'N\'oublie jamais le cardio', 'description' => 'Log running workout', 'description_fr' => 'Enregistrez une séance de course', 'target' => 1, 'type' => 'cardio'],
            ['name' => 'Consistency King', 'name_fr' => 'Roi de la régularité', 'description' => 'Workout 5 days in a row', 'description_fr' => 'Entraînez-vous 5 jours d\'affilée', 'target' => 5, 'type' => 'streak'],
            ['name' => 'Fat Burner', 'name_fr' => 'Brûleur de graisse', 'description' => 'Burn 5000 calories', 'description_fr' => 'Brûlez 5000 calories', 'target' => 5000, 'type' => 'calories'],

            // progress achievements
            ['name' => 'Making progress', 'name_fr' => 'En bonne voie', 'description' => 'Log your first weight update', 'description_fr' => 'Enregistrez votre premier suivi de poids', 'target' => 1, 'type' => 'progress'],
            ['name' => 'Weight loss', 'name_fr' => 'Perte de poids', 'description' => 'Lose 5 kg', 'description_fr' => 'Perdez 5 kg', 'target' => 5, 'type' => 'weight'],
            ['name' => 'Muscle Gain', 'name_fr' => 'Prise de muscle', 'description' => 'Gain 2 kg of muscle', 'description_fr' => 'Gagnez 2 kg de muscle', 'target' => 2, 'type' => 'muscle'],
        ];

        foreach ($achievements as $achievement) {
            Achievement::create($achievement);
        }
    }
}
