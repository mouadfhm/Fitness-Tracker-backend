<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each source of engagement, and the column that dates it.
     *
     * `progresses` is the odd one out: the table has timestamps but the model
     * sets $timestamps = false, so created_at is NULL on every row ever written
     * through Eloquent. Its `date` column is the only usable signal.
     */
    private const SOURCES = [
        'meals'        => 'created_at',
        'workouts'     => 'created_at',
        'workout_logs' => 'created_at',
        'progresses'   => 'date',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_engaged_at')->nullable()->index();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The index is named after the column, so dropping the column alone
            // leaves an orphaned index on MySQL. Drop it explicitly first.
            $table->dropIndex(['last_engaged_at']);
            $table->dropColumn('last_engaged_at');
        });
    }

    /**
     * Without this every existing user looks dormant the moment the column
     * lands, and reminders stop for all of them on the next run.
     *
     * Applied as one statement per source, each keeping the later of what is
     * already there and what the source reports. That is GREATEST() without
     * depending on GREATEST(), which returns NULL for the whole expression as
     * soon as one argument is NULL — which is the normal case here, since most
     * users have rows in some of these tables and not others.
     */
    private function backfill(): void
    {
        foreach (self::SOURCES as $table => $column) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $latest = "(SELECT MAX({$column}) FROM {$table} WHERE {$table}.user_id = users.id)";

            DB::statement("
                UPDATE users
                SET last_engaged_at = {$latest}
                WHERE {$latest} IS NOT NULL
                  AND (last_engaged_at IS NULL OR last_engaged_at < {$latest})
            ");
        }
    }
};
