<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every seeded food, activity and gym exercise a plain English and a
 * plain French name: no USDA abbreviations, codes, brackets, quotes or
 * slashes ("BEEF,TOP LOIN,BNLS,LN&FAT,1/8\" FAT,ALL GRDS,CKD,RSTD" becomes
 * "Beef top loin, lean and fat, roasted" / "Contre-filet de bœuf, maigre et
 * gras, rôti"). The names come from database/data/food_names.csv,
 * activity_names.csv and gym_exercise_names.csv, which the seeders use too.
 *
 * `name` and `description` are left as they are: they are unique keys,
 * and workouts are logged against the activity description.
 *
 * Activities also get a category ("Running and athletics" / "Course et
 * athlétisme"). Old rows were seeded with the text before the first comma
 * as their name, which the app used as the category filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
            // `category` exists since 2025 but was never filled.
            $table->string('category_fr')->nullable()->after('category');
        });
        Schema::table('gym_exercises', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
        });

        $this->renameFoods();
        $this->renameActivities();
        $this->renameGymExercises();
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropColumn(['name_en', 'category_fr']);
        });
        Schema::table('gym_exercises', function (Blueprint $table) {
            $table->dropColumn('name_en');
        });
    }

    private function renameFoods(): void
    {
        $names = $this->namesByKey('food_names.csv', 'key');

        DB::table('foods')
            ->whereNull('added_by')
            ->select('id', 'name', 'source_name')
            ->orderBy('id')
            ->chunkById(500, function ($foods) use ($names) {
                foreach ($foods as $food) {
                    $row = ($food->source_name !== null ? $names[mb_strtolower($food->source_name)] ?? null : null)
                        ?? $names[mb_strtolower($food->name)] ?? null;
                    if ($row !== null) {
                        DB::table('foods')->where('id', $food->id)->update([
                            'name_en' => $row['name_en'],
                            'name_fr' => $row['name_fr'],
                        ]);
                    }
                }
            });
    }

    private function renameActivities(): void
    {
        $names = $this->namesByKey('activity_names.csv', 'source');

        DB::table('exercises')
            ->select('id', 'name', 'description')
            ->orderBy('id')
            ->chunkById(500, function ($activities) use ($names) {
                foreach ($activities as $activity) {
                    $row = $names[mb_strtolower(trim((string) $activity->description))]
                        ?? $names[mb_strtolower(trim((string) $activity->name))] ?? null;
                    if ($row !== null) {
                        DB::table('exercises')->where('id', $activity->id)->update([
                            'name_en' => $row['name_en'],
                            'name_fr' => $row['name_fr'],
                            'category' => $row['category_en'],
                            'category_fr' => $row['category_fr'],
                        ]);
                    }
                }
            });
    }

    private function renameGymExercises(): void
    {
        $names = $this->namesByKey('gym_exercise_names.csv', 'key');

        DB::table('gym_exercises')
            ->select('id', 'name')
            ->orderBy('id')
            ->chunkById(500, function ($exercises) use ($names) {
                foreach ($exercises as $exercise) {
                    $row = $names[mb_strtolower(trim((string) $exercise->name))] ?? null;
                    if ($row !== null) {
                        DB::table('gym_exercises')->where('id', $exercise->id)->update([
                            'name_en' => $row['name_en'],
                            'name_fr' => $row['name_fr'],
                        ]);
                    }
                }
            });
    }

    /**
     * Rows of database/data/$file keyed by the lowercase $keyColumn.
     *
     * @return array<string, array<string, string>>
     */
    private function namesByKey(string $file, string $keyColumn): array
    {
        $handle = fopen(database_path("data/{$file}"), 'r');
        $header = fgetcsv($handle);
        $rows = [];
        while (($fields = fgetcsv($handle)) !== false) {
            if (count($fields) === count($header)) {
                $row = array_combine($header, $fields);
                $rows[mb_strtolower($row[$keyColumn])] ??= $row;
            }
        }
        fclose($handle);

        return $rows;
    }
};
