<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_streaks', function (Blueprint $table) {
            $table->id();

            // Unique, not merely indexed: one row per user is the invariant the
            // observer's read-modify-write assumes. Without the constraint two
            // concurrent first-ever logs write two rows, and every read
            // afterwards returns whichever the planner reaches first — which is
            // to say the user's streak would flicker between two values.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Unsigned because neither can be negative, and a negative one would
            // mean something upstream is broken; better to fail the write than
            // to store it and have the copy read "-1-day streak".
            $table->unsignedInteger('current_days')->default(0);
            $table->unsignedInteger('longest_days')->default(0);

            // Nullable for a row that exists before its first qualifying day —
            // which the observer never creates, but the constraint should not
            // depend on that staying true.
            $table->date('last_day')->nullable();

            $table->timestamps();

            // For the nightly at-risk sweep, which wants "logged yesterday, on a
            // run of three or more". `last_day` leads because that is the
            // selective half: it narrows to users who logged in the last couple
            // of days, where `current_days >= 3` matches a large fraction of the
            // table. Reversed, the range on `current_days` would stop the index
            // being usable for `last_day` at all.
            $table->index(['last_day', 'current_days']);
        });

        // Existing users start with the streak they have actually earned rather
        // than with zero. Launching a retention mechanic by resetting everybody
        // who was already here makes the feature land as a punishment, and the
        // users it would punish are the loyal ones.
        //
        // Through the command rather than inline so there is one implementation
        // of "replay a history into a streak", and so this is re-runnable
        // afterwards without a migration rollback.
        //
        // Caught, because the table is the part that must land. A failed
        // backfill leaves every user on zero and is fixed by running
        // `php artisan streaks:backfill` again; a migration that fails half way
        // leaves the schema in a state the next deploy has to be talked through.
        try {
            Artisan::call('streaks:backfill');
        } catch (Throwable $e) {
            Log::error('Streak backfill failed during migration; run streaks:backfill by hand', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_streaks');
    }
};
