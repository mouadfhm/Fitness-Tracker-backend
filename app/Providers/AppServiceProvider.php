<?php

namespace App\Providers;

use App\Models\Meal;
use App\Models\Progress;
use App\Models\Workout;
use App\Models\WorkoutLog;
use App\Observers\EngagementObserver;
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
    }
}
