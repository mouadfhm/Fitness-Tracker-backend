<?php

use App\Support\FoodNameCleaner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-runs FoodNameCleaner over the seeded USDA foods now that it knows many
 * more abbreviations, so "Fruit Babyfood (Bananas&pnappl, Str)" reads
 * "Fruit Baby Food (Bananas & Pineapple, Strained)". User-added foods and
 * foods whose name was edited by hand are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('foods')
            ->whereNull('added_by')
            ->whereNotNull('source_name')
            ->select('id', 'name', 'name_en', 'source_name')
            ->orderBy('id')
            ->chunkById(500, function ($foods) {
                foreach ($foods as $food) {
                    $clean = FoodNameCleaner::clean($food->source_name);
                    if ($clean === '' || $clean === $food->name_en) {
                        continue;
                    }

                    $changes = ['name_en' => $clean];
                    // `name` is unique; only follow name_en when it wasn't
                    // customised and the new name is free.
                    if ($food->name === $food->name_en
                        && ! DB::table('foods')->where('name', $clean)->exists()) {
                        $changes['name'] = $clean;
                    }

                    DB::table('foods')->where('id', $food->id)->update($changes);
                }
            });
    }

    public function down(): void
    {
        // The old abbreviated names aren't worth restoring.
    }
};
