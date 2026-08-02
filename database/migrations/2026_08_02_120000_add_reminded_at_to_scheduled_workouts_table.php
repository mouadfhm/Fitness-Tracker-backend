<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When this session's reminder was decided.
     *
     * The alternative was to key off notification_logs, but that table has no
     * column for the thing a notification is *about* — only a user and a type —
     * so "have we already reminded this user about workout #412?" would have had
     * to be inferred from timestamps. A column on the row itself answers it
     * exactly, and answers it in the same query that finds the candidates
     * (`whereNull`) rather than in a second one per user.
     *
     * It matters because the command runs hourly against a window that is one
     * hour wide. Those are equal on purpose and each session should therefore
     * match once, but "should" is doing a lot of work there: a clock adjustment,
     * a slow run, or a scheduler that fires twice in a minute all turn one
     * reminder into three. This makes that structurally impossible instead.
     */
    public function up(): void
    {
        Schema::table('scheduled_workouts', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('scheduled_at');

            // Leading column is the one that eliminates almost everything: after
            // a few months the overwhelming majority of rows are reminded and
            // stay that way, so `reminded_at IS NULL` is the selective half of
            // the predicate and `scheduled_at` narrows what is left to a day or
            // so either side of now.
            $table->index(['reminded_at', 'scheduled_at'], 'scheduled_workouts_reminder_idx');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_workouts', function (Blueprint $table) {
            // Dropped before the column: MySQL will not drop a column an index
            // still references, and leaving this out is how a rollback fails
            // halfway on production and not on anyone's laptop.
            $table->dropIndex('scheduled_workouts_reminder_idx');
            $table->dropColumn('reminded_at');
        });
    }
};
