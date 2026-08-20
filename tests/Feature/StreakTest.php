<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\Progress;
use App\Models\User;
use App\Models\UserStreak;
use App\Models\Workout;
use App\Models\WorkoutLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec 10 end to end.
 *
 * StreakCalendarTest covers the arithmetic. What only a database can show is
 * everything around it — that the observer is actually registered on all three
 * logging models, that the day credited is the user's day and not the server's,
 * that the backfill's SQL groups the way it claims to, and that the evening
 * command picks up exactly the right people.
 *
 * No Firebase double anywhere. The users here have no registered device, so
 * NotificationService logs a skipped row and never resolves `firebase` at all;
 * that row is the evidence the command selected them, which is the only thing
 * these tests ask about.
 */
class StreakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mid-August, so every zone below sits on one side of its DST transition
     * and the offsets in play are stable. Europe/Paris is UTC+2 here, which
     * makes 18:00 UTC the users' 20:00 — their send window.
     */
    private const EVENING = '2026-08-20 18:10:00';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- upkeep

    /**
     * "Logging on consecutive days increments."
     */
    public function test_consecutive_days_increment_as_they_are_logged(): void
    {
        $user = $this->makeUser();

        $this->logMeal($user, '2026-08-18 09:00:00');
        $this->logMeal($user, '2026-08-19 09:00:00');
        $this->logMeal($user, '2026-08-20 09:00:00');

        $streak = $user->streak()->first();

        $this->assertSame(3, $streak->current_days);
        $this->assertSame(3, $streak->longest_days);
        $this->assertSame('2026-08-20', $streak->last_day);
    }

    /**
     * "Skipping a day resets to 1 on the next log", and "longest_days never
     * decreases" alongside it.
     */
    public function test_a_skipped_day_resets_the_streak_but_not_the_record(): void
    {
        $user = $this->makeUser();

        foreach (['2026-08-14', '2026-08-15', '2026-08-16', '2026-08-17'] as $day) {
            $this->logMeal($user, "{$day} 09:00:00");
        }

        $this->logMeal($user, '2026-08-20 09:00:00');

        $streak = $user->streak()->first();

        $this->assertSame(1, $streak->current_days);
        $this->assertSame(4, $streak->longest_days);
    }

    /**
     * The definition, checked against all three tables the app writes to.
     *
     * A meal or a workout, either one, and both on the same day still make one
     * day. v1 `workouts` is in here deliberately: it is the table the live app
     * writes, and an implementation that only observed `workout_logs` would
     * pass every other test in this file.
     */
    public function test_a_meal_or_a_workout_makes_the_day_count_once(): void
    {
        $user = $this->makeUser();

        $this->logMeal($user, '2026-08-18 08:00:00');
        $this->logWorkout($user, '2026-08-18 19:00:00');

        $this->assertSame(1, $user->streak()->first()->current_days);

        $this->logWorkout($user, '2026-08-19 19:00:00');

        $this->assertSame(2, $user->streak()->first()->current_days);

        $this->logWorkoutLog($user, '2026-08-20 19:00:00');

        $this->assertSame(3, $user->streak()->first()->current_days);
    }

    /**
     * A weigh-in is engagement, not a streak day.
     *
     * It resets the reminder backoff — Progress is in AppServiceProvider's
     * engagement list — and it must not keep a streak alive on its own, or the
     * mechanic stops meaning "I logged what I ate or what I did".
     */
    public function test_a_weigh_in_does_not_count(): void
    {
        $user = $this->makeUser();

        Carbon::setTestNow($this->utc($user, '2026-08-18 09:00:00'));

        // Through the model, so that if Progress were ever added to the streak
        // observer's list this would fail rather than quietly pass.
        Progress::create([
            'user_id' => $user->id,
            'weight'  => 80,
            'date'    => '2026-08-18',
        ]);

        $this->assertNull($user->streak()->first());
    }

    /**
     * The bug this whole design exists to avoid: days evaluated where the user
     * is, not where the server is.
     *
     * Both logs below fall on one day in Pacific/Auckland and on two different
     * days in UTC. Counted on the server's clock they would be a streak of two;
     * counted on the user's, which is the only clock they experience, they are
     * one day logged twice.
     */
    public function test_the_day_is_the_users_day_and_not_the_servers(): void
    {
        $user = $this->makeUser('Pacific/Auckland');

        // 21 Aug, 09:00 and 23:00 in Auckland — 20 Aug 21:00 and 21 Aug 11:00 UTC.
        $this->logMeal($user, '2026-08-21 09:00:00');
        $this->logMeal($user, '2026-08-21 23:00:00');

        $streak = $user->streak()->first();

        $this->assertSame(1, $streak->current_days);
        $this->assertSame('2026-08-21', $streak->last_day);
    }

    // -------------------------------------------------------------- backfill

    /**
     * "Backfill produces plausible streaks for existing users."
     */
    public function test_the_backfill_rebuilds_a_live_streak_from_history(): void
    {
        Carbon::setTestNow(self::EVENING);

        $user = $this->makeUser();

        // A five-day run that ended yesterday, and an older three-day one.
        foreach (['2026-08-04', '2026-08-05', '2026-08-06'] as $day) {
            $this->insertMeal($user, "{$day} 09:00:00");
        }

        foreach (['2026-08-15', '2026-08-16', '2026-08-17', '2026-08-18'] as $day) {
            $this->insertMeal($user, "{$day} 09:00:00");
        }

        // Yesterday came from a workout rather than a meal, which is the half
        // of the definition a meals-only backfill would silently drop.
        $this->insertWorkout($user, '2026-08-19 18:00:00');

        $this->artisan('streaks:backfill')->assertSuccessful();

        $streak = $user->streak()->first();

        $this->assertSame(5, $streak->current_days);
        $this->assertSame(5, $streak->longest_days);
        $this->assertSame('2026-08-19', $streak->last_day);
    }

    /**
     * A user who stopped logging in March must not be greeted with the streak
     * they lost in March. The personal best survives; the live count does not.
     */
    public function test_the_backfill_settles_a_lapsed_streak_but_keeps_the_best(): void
    {
        Carbon::setTestNow(self::EVENING);

        $user = $this->makeUser();

        foreach (['2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04'] as $day) {
            $this->insertMeal($user, "{$day} 09:00:00");
        }

        $this->artisan('streaks:backfill')->assertSuccessful();

        $streak = $user->streak()->first();

        $this->assertSame(0, $streak->current_days);
        $this->assertSame(4, $streak->longest_days);
        $this->assertSame('2026-03-04', $streak->last_day);
    }

    /**
     * "Never logged anything" and "logged, then lapsed" are different states,
     * and only the second is a broken streak.
     */
    public function test_the_backfill_leaves_a_user_with_no_history_alone(): void
    {
        Carbon::setTestNow(self::EVENING);

        $user = $this->makeUser();

        $this->artisan('streaks:backfill')->assertSuccessful();

        $this->assertNull($user->streak()->first());
    }

    /**
     * It is the repair tool as well as the migration's, so running it twice has
     * to leave the same answer rather than compounding one.
     */
    public function test_the_backfill_is_idempotent(): void
    {
        Carbon::setTestNow(self::EVENING);

        $user = $this->makeUser();

        foreach (['2026-08-17', '2026-08-18', '2026-08-19'] as $day) {
            $this->insertMeal($user, "{$day} 09:00:00");
        }

        $this->artisan('streaks:backfill')->assertSuccessful();
        $this->artisan('streaks:backfill')->assertSuccessful();

        $this->assertSame(1, UserStreak::where('user_id', $user->id)->count());
        $this->assertSame(3, $user->streak()->first()->current_days);
    }

    // ------------------------------------------------------------- the send

    /**
     * "The at-risk notification fires only for streaks of 3+ with nothing
     * logged today."
     */
    public function test_a_three_day_streak_with_nothing_logged_today_is_warned(): void
    {
        Carbon::setTestNow(self::EVENING);

        $user = $this->makeUser();
        $this->giveStreak($user, 6, '2026-08-19');

        $this->artisan('send:streak-at-risk')->assertSuccessful();

        $log = $this->streakLog($user);

        $this->assertNotNull($log, 'A six-day streak about to break heard nothing.');

        // The number is the argument. Copy that dropped it would be asking for
        // the same action with none of the reason.
        $this->assertStringContainsString('6-day streak', $log->title);
    }

    /**
     * Below the threshold. Someone who logged once yesterday has no run to be
     * afraid of losing, and telling them they do teaches them the number means
     * nothing.
     */
    public function test_a_two_day_streak_hears_nothing(): void
    {
        Carbon::setTestNow(self::EVENING);

        $user = $this->makeUser();
        $this->giveStreak($user, 2, '2026-08-19');

        $this->artisan('send:streak-at-risk')->assertSuccessful();

        $this->assertNull($this->streakLog($user));
    }

    public function test_a_user_who_already_logged_today_hears_nothing(): void
    {
        Carbon::setTestNow(self::EVENING);

        $user = $this->makeUser();
        $this->giveStreak($user, 6, '2026-08-20');

        $this->artisan('send:streak-at-risk')->assertSuccessful();

        $this->assertNull($this->streakLog($user));
    }

    /**
     * "Send before the streak breaks, not after."
     *
     * This user's streak ended the day before yesterday: there is nothing left
     * to save, and a notification here would only be telling them they failed.
     */
    public function test_an_already_broken_streak_hears_nothing(): void
    {
        Carbon::setTestNow(self::EVENING);

        $user = $this->makeUser();
        $this->giveStreak($user, 6, '2026-08-18');

        $this->artisan('send:streak-at-risk')->assertSuccessful();

        $this->assertNull($this->streakLog($user));
    }

    /**
     * The evening hour is the user's evening.
     *
     * Both users are at risk. It is 20:10 for the one in Paris and 14:10 for
     * the one in New York, so only the first is due — the second is picked up
     * by the run six hours from now, which is the entire point of running this
     * every thirty minutes instead of once a night.
     */
    public function test_it_fires_on_the_users_evening_and_not_the_servers(): void
    {
        Carbon::setTestNow(self::EVENING);

        $paris = $this->makeUser('Europe/Paris');
        $this->giveStreak($paris, 4, '2026-08-19');

        $newYork = $this->makeUser('America/New_York');
        $this->giveStreak($newYork, 4, '2026-08-19');

        $this->artisan('send:streak-at-risk')->assertSuccessful();

        $this->assertNotNull($this->streakLog($paris));
        $this->assertNull($this->streakLog($newYork));
    }

    /**
     * "At most once per day."
     *
     * The guard is the notification log spec 01 built, read through
     * EngagementService — which is why spec 10 depends on 01. The row below is
     * what a successful send earlier this evening would have left behind.
     */
    public function test_it_does_not_send_twice_in_one_day(): void
    {
        Carbon::setTestNow(self::EVENING);

        $user = $this->makeUser();
        $this->giveStreak($user, 6, '2026-08-19');

        NotificationLog::create([
            'user_id' => $user->id,
            'type'    => NotificationLog::TYPE_STREAK_AT_RISK,
            'title'   => '🔥 6-day streak',
            'body'    => "Don't break it — log anything today.",
            'status'  => NotificationLog::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->artisan('send:streak-at-risk')->assertSuccessful();

        $second = NotificationLog::where('user_id', $user->id)
            ->where('type', NotificationLog::TYPE_STREAK_AT_RISK)
            ->where('status', NotificationLog::STATUS_SKIPPED)
            ->first();

        $this->assertNotNull($second, 'The repeat was not recorded as held back.');
        $this->assertSame('Already notified today', $second->error);

        $this->assertSame(
            1,
            NotificationLog::where('user_id', $user->id)
                ->where('type', NotificationLog::TYPE_STREAK_AT_RISK)
                ->where('status', NotificationLog::STATUS_SENT)
                ->count()
        );
    }

    /**
     * The off switch. Not in spec 10, and shipping without it would make this
     * the one notification in the app a user cannot stop — which is how an app
     * earns a permanent OS-level mute.
     */
    public function test_a_user_who_switched_streak_nudges_off_hears_nothing(): void
    {
        Carbon::setTestNow(self::EVENING);

        $user = $this->makeUser();
        $this->giveStreak($user, 6, '2026-08-19');

        NotificationPreference::create([
            'user_id' => $user->id,
            'streaks' => false,
        ]);

        $this->artisan('send:streak-at-risk')->assertSuccessful();

        $log = $this->streakLog($user);

        $this->assertNotNull($log, 'The suppression itself should still be recorded.');
        $this->assertStringContainsString('Disabled by user preference', $log->error);
    }

    // --------------------------------------------------------------- helpers

    private function makeUser(string $timezone = 'Europe/Paris'): User
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name'     => "Streak User {$n}",
            'email'    => "streak{$n}@example.test",
            'password' => 'x',
        ]);

        $user->timezone = $timezone;
        $user->save();

        return $user;
    }

    private function giveStreak(User $user, int $current, string $lastDay): UserStreak
    {
        return UserStreak::create([
            'user_id'      => $user->id,
            'current_days' => $current,
            'longest_days' => $current,
            'last_day'     => $lastDay,
        ]);
    }

    private function streakLog(User $user): ?NotificationLog
    {
        return NotificationLog::where('user_id', $user->id)
            ->where('type', NotificationLog::TYPE_STREAK_AT_RISK)
            ->where('status', NotificationLog::STATUS_SKIPPED)
            ->latest('id')
            ->first();
    }

    /**
     * Logged through the model, so the observer fires — which is half of what
     * these tests are checking.
     *
     * @param string $localDateTime On the user's own clock. The wall clock is
     *        moved to match, because the observer credits the day it is *now*
     *        where the user is, not a date carried in the row.
     */
    private function logMeal(User $user, string $localDateTime): void
    {
        Carbon::setTestNow($this->utc($user, $localDateTime));

        Meal::create([
            'user_id'   => $user->id,
            'date'      => substr($localDateTime, 0, 10),
            'meal_time' => 'breakfast',
        ]);
    }

    private function logWorkout(User $user, string $localDateTime): void
    {
        Carbon::setTestNow($this->utc($user, $localDateTime));

        Workout::create([
            'user_id'         => $user->id,
            'activity_type'   => 'running',
            'duration'        => 30,
            'calories_burned' => 300,
            'workout_date'    => substr($localDateTime, 0, 10),
        ]);
    }

    private function logWorkoutLog(User $user, string $localDateTime): void
    {
        Carbon::setTestNow($this->utc($user, $localDateTime));

        WorkoutLog::create([
            'user_id'      => $user->id,
            'workout_date' => $this->utc($user, $localDateTime),
            'duration'     => 45,
        ]);
    }

    /**
     * History as it would already be in the table, written past the observer so
     * the backfill has something to rebuild from rather than something the
     * observer has already counted.
     */
    private function insertMeal(User $user, string $localDateTime): void
    {
        DB::table('meals')->insert([
            'user_id'    => $user->id,
            'date'       => substr($localDateTime, 0, 10),
            'meal_time'  => 'breakfast',
            'created_at' => $this->utc($user, $localDateTime),
            'updated_at' => $this->utc($user, $localDateTime),
        ]);
    }

    private function insertWorkout(User $user, string $localDateTime): void
    {
        DB::table('workouts')->insert([
            'user_id'         => $user->id,
            'activity_type'   => 'running',
            'duration'        => 30,
            'calories_burned' => 300,
            'workout_date'    => substr($localDateTime, 0, 10),
            'created_at'      => $this->utc($user, $localDateTime),
            'updated_at'      => $this->utc($user, $localDateTime),
        ]);
    }

    private function utc(User $user, string $localDateTime): Carbon
    {
        return Carbon::parse($localDateTime, $user->timezoneOrDefault())->utc();
    }
}
