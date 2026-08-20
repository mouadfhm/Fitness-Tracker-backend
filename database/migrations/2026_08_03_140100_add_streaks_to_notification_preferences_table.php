<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An off switch for the streak nudge.
 *
 * Not in spec 10, and not optional either. NotificationPreference::allowsType()
 * lets an unmapped type through unconditionally — see the note on TYPE_COLUMNS
 * — so shipping the streak notification without a column here would not defer
 * the decision, it would make the notification impossible to switch off. An
 * evening notification a user cannot stop is how an app gets muted at the OS
 * level, which 00-index.md names as the failure that is not undone later.
 *
 * Its own toggle rather than sharing one of the four. Reusing `meal_reminders`
 * would mean someone who turned off meal reminders silently lost their streak
 * saves too, and the two are not the same offer: one says "you have not eaten",
 * the other says "you are about to lose something you built".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            // Default true, matching every other toggle and the DEFAULTS
            // constant the model reads for users who have no row yet. The two
            // have to agree, or opening the settings screen and saving nothing
            // would change what a user receives.
            $table->boolean('streaks')->default(true)->after('winback');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn('streaks');
        });
    }
};
