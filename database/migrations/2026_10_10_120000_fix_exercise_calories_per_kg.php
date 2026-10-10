<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "Calories per kg" column of exercise_dataset.csv is the 130 lb figure
 * divided by 130 and by 2.20462 a second time, so every activity burned
 * about 4.86x too little (mountain biking came out at ~137 kcal/hour for a
 * 78 kg person instead of ~665). This scales the stored values back to kcal
 * per kg per hour (roughly the MET value) and fixes workouts already logged
 * with them, which also feed the daily calorie goal.
 */
return new class extends Migration
{
    private const FACTOR = 2.20462 * 2.20462;

    public function up(): void
    {
        // The broken values top out below 4; corrected ones go above 15.
        // Skip if the table was already seeded with corrected data.
        if (DB::table('exercises')->max('caloriesPerKg') >= 5) {
            return;
        }

        $this->scale(self::FACTOR);
    }

    public function down(): void
    {
        if (DB::table('exercises')->max('caloriesPerKg') < 5) {
            return;
        }

        $this->scale(1 / self::FACTOR);
    }

    private function scale(float $factor): void
    {
        DB::transaction(function () use ($factor) {
            DB::table('exercises')->update([
                'caloriesPerKg' => DB::raw('caloriesPerKg * '.$factor),
            ]);

            DB::table('workouts')
                ->whereIn('activity_type', DB::table('exercises')->select('description'))
                ->update(['calories_burned' => DB::raw('calories_burned * '.$factor)]);
        });
    }
};
