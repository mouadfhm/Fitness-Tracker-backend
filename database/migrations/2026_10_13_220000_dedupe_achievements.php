<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AchievementSeeder used Achievement::create(), so running it again (the way
 * to fill name_fr/description_fr after the translations migration) inserted
 * a second copy of every achievement and the app listed each one twice.
 *
 * This keeps the oldest row of each name, copies any translation a newer
 * copy carries onto it, moves unlocks from the copies to it, drops unlocks a
 * user now holds twice (keeping the earliest), deletes the copies, and adds
 * unique indexes so neither table can take a duplicate again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $groups = DB::table('achievements')
                ->orderBy('id')
                ->get()
                ->groupBy('name')
                ->filter(fn ($rows) => $rows->count() > 1);

            foreach ($groups as $rows) {
                $keeper = $rows->first();
                $copies = $rows->slice(1);
                $copyIds = $copies->pluck('id')->all();

                $translations = [];
                foreach (['name_fr', 'description_fr'] as $column) {
                    if (empty($keeper->{$column})) {
                        $value = $copies->reverse()->pluck($column)->first(fn ($v) => ! empty($v));
                        if ($value !== null) {
                            $translations[$column] = $value;
                        }
                    }
                }
                if ($translations) {
                    DB::table('achievements')->where('id', $keeper->id)->update($translations);
                }

                DB::table('user_achievements')
                    ->whereIn('achievement_id', $copyIds)
                    ->update(['achievement_id' => $keeper->id]);

                DB::table('achievements')->whereIn('id', $copyIds)->delete();
            }

            $this->dropDuplicateUnlocks();
        });

        Schema::table('achievements', function (Blueprint $table) {
            $table->unique('name');
        });

        Schema::table('user_achievements', function (Blueprint $table) {
            $table->unique(['user_id', 'achievement_id']);
        });
    }

    public function down(): void
    {
        Schema::table('user_achievements', function (Blueprint $table) {
            // MySQL backs the user_id foreign key with this index once it
            // exists, so give the key its own index before dropping it.
            $table->index('user_id');
            $table->dropUnique(['user_id', 'achievement_id']);
        });

        Schema::table('achievements', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }

    private function dropDuplicateUnlocks(): void
    {
        $duplicates = DB::table('user_achievements')
            ->select('user_id', 'achievement_id')
            ->groupBy('user_id', 'achievement_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $pair) {
            $ids = DB::table('user_achievements')
                ->where('user_id', $pair->user_id)
                ->where('achievement_id', $pair->achievement_id)
                ->orderByRaw('unlocked_at IS NULL')
                ->orderBy('unlocked_at')
                ->orderBy('id')
                ->pluck('id');

            DB::table('user_achievements')->whereIn('id', $ids->slice(1)->all())->delete();
        }
    }
};
