<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();

            // Unique, not merely indexed: one row per user is the invariant that
            // the lazy firstOrNew in the model and the updateOrCreate in the
            // controller both assume. Without the constraint, two devices saving
            // settings at the same moment write two rows, and every read after
            // that returns whichever one the planner happens to reach first.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->boolean('meal_reminders')->default(true);
            $table->boolean('workout_reminders')->default(true);
            $table->boolean('achievements')->default(true);
            $table->boolean('winback')->default(true);

            // Nullable so quiet hours can be switched off entirely, the pair
            // going NULL together — see NotificationPreference::quietWindow,
            // which reads a half-set pair as "off" rather than inventing the
            // missing end.
            //
            // These defaults are duplicated in NotificationPreference::DEFAULTS
            // deliberately. A migration has to keep meaning what it meant on the
            // day it ran, so it must not reference a constant a later release
            // could change; the constant is what the running code reads.
            $table->time('quiet_from')->nullable()->default('22:00:00');
            $table->time('quiet_to')->nullable()->default('08:00:00');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
