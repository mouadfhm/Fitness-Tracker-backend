<?php

namespace App\Providers;

use App\Models\Meal;
use App\Models\Progress;
use App\Models\Workout;
use App\Models\WorkoutLog;
use App\Observers\EngagementObserver;
use App\Observers\StreakObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Every model whose creation means "this user is still using the app".
     *
     * Workout (v1) is in here alongside WorkoutLog (v2) because both endpoints
     * are live, and the daily workout reminder decides who to nag by looking at
     * the v1 table. Omitting it would nag users who logged a workout yesterday.
     */
    private const ENGAGEMENT_MODELS = [
        Meal::class,
        Workout::class,
        WorkoutLog::class,
        Progress::class,
    ];

    /**
     * Every model whose creation makes a day count towards a streak.
     *
     * The same list as above minus Progress, and that omission is the whole
     * definition. A streak day is one the user logged a meal or a workout on;
     * stepping on the scales is engagement — it resets the backoff above — but
     * it is not the habit this mechanic exists to build, and counting it would
     * let a streak run indefinitely on weigh-ins alone.
     *
     * Workout (v1) is in here alongside WorkoutLog (v2) for the reason the list
     * above gives: both endpoints are live, and leaving v1 out would break the
     * streak of every user who logs workouts through the screen that writes it.
     */
    private const STREAK_MODELS = [
        Meal::class,
        Workout::class,
        WorkoutLog::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (self::ENGAGEMENT_MODELS as $model) {
            $model::observe(EngagementObserver::class);
        }

        foreach (self::STREAK_MODELS as $model) {
            $model::observe(StreakObserver::class);
        }
    }
}
