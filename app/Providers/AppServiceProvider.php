<?php

namespace App\Providers;

use Anthropic\Client;
use App\Models\Meal;
use App\Models\Progress;
use App\Models\Workout;
use App\Models\WorkoutLog;
use App\Observers\EngagementObserver;
use App\Observers\StreakObserver;
use App\Services\MealScan\ClaudeFoodVision;
use App\Services\MealScan\FoodVision;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->bind(FoodVision::class, fn () => new ClaudeFoodVision(
            new Client(
                apiKey: (string) config('services.anthropic.key'),
                requestOptions: ['timeout' => 30, 'maxRetries' => 1],
            ),
            config('services.anthropic.meal_scan_model'),
        ));
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

        $this->configureRateLimiting();
    }

    /**
     * Limits for the unauthenticated auth endpoints. Per email as well as per
     * IP for the ones that take an email, so one account can't be hammered
     * from many addresses and one address can't sweep many accounts. Plus
     * the meal scanner, which costs money per call.
     */
    private function configureRateLimiting(): void
    {
        $email = fn (Request $request) => strtolower((string) $request->input('email'));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.$email($request).'|'.$request->ip()),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute(5)->by('register-ip:'.$request->ip()),
        ]);

        RateLimiter::for('forgot-password', fn (Request $request) => [
            Limit::perMinute(1)->by('forgot:'.$email($request)),
            Limit::perHour(10)->by('forgot-ip:'.$request->ip()),
        ]);

        RateLimiter::for('reset-password', fn (Request $request) => [
            Limit::perMinute(10)->by('reset-ip:'.$request->ip()),
        ]);

        // Every meal-scan frame is a paid model call. The app sends at most
        // one every 1.5 s while scanning and pauses itself once the plate is
        // read, so these only stop runaway clients.
        RateLimiter::for('meal-scan', fn (Request $request) => [
            Limit::perMinute(40)->by('meal-scan:'.$request->user()?->id),
            Limit::perDay(600)->by('meal-scan-day:'.$request->user()?->id),
        ]);
    }
}
