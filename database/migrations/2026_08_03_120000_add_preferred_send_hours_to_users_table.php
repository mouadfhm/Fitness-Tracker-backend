<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable, unsigned, no default, no backfill — for the same reason
            // `timezone` is. NULL is not "we have not got round to it", it is a
            // decision the sending path reads: "this user has not used the app
            // often enough for us to have an opinion, send at the hour everyone
            // has always been sent at". Defaulting these to 10 and 18 would make
            // a computed hour indistinguishable from a guessed one, and would
            // silently move the workout reminder from 18:30 to 18:00 for every
            // user in the table.
            //
            // Hours only, no minutes. The estimate is a median over a month of
            // logging and is not precise to the minute in any sense worth
            // storing; App\Services\ReminderWindow already sends anywhere in the
            // half hour that follows.
            $table->unsignedTinyInteger('preferred_meal_hour')->nullable();
            $table->unsignedTinyInteger('preferred_workout_hour')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['preferred_meal_hour', 'preferred_workout_hour']);
        });
    }
};
