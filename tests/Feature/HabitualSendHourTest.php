<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\HabitualHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec 07 end to end: a month of logging goes in, an hour comes out of the
 * column, and the reminder command picks the user up at that hour instead of at
 * 10:00.
 *
 * HabitualHourTest covers the arithmetic. What only a database can show is the
 * half in between — the local-date grouping, the two workout tables, the
 * thirty-day boundary — and that is what this is for.
 *
 * No Firebase double anywhere. The users here have no registered device, so
 * NotificationService logs a skipped row and never resolves `firebase` at all;
 * the row is the evidence that the command selected them, which is the only
 * thing these tests are asking about.
 */
class HabitualSendHourTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mid-August, so every zone below is on one side of its DST transition for
     * the whole thirty-day window and the offsets in play are stable.
     */
    private const NOW = '2026-08-20 12:00:00';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * "A user who consistently logs at 07:30 receives the meal reminder at
     * 07:00, not 10:00" — at 08:00 here, for the reason HabitualHourTest
     * records: 07:00 is inside the quiet window every user has by default.
     */
    public function test_an_early_riser_is_learned_from_their_meals(): void
    {
        Carbon::setTestNow(self::NOW);

        $user = $this->makeUser('Europe/Paris');

        foreach ($this->lastTenDays() as $date) {
            $this->logMeal($user, "{$date} 07:30:00");
            $this->logMeal($user, "{$date} 13:00:00");
            $this->logMeal($user, "{$date} 20:00:00");
        }

        $this->recompute();

        $this->assertSame(8, $user->fresh()->preferred_meal_hour);
    }

    /**
     * The reason the sample is one hour per day rather than every meal.
     *
     * The user above logs breakfast, lunch and dinner. The median of all thirty
     * of those meals is lunch — 13:00 — by which time they have already logged
     * breakfast, so the reminder would be suppressed every normal day and would
     * arrive three hours later than 10:00 on the days they forgot.
     */
    public function test_the_estimate_is_the_first_meal_of_the_day_not_the_middle_one(): void
    {
        Carbon::setTestNow(self::NOW);

        $user = $this->makeUser('Europe/Paris');

        foreach ($this->lastTenDays() as $date) {
            $this->logMeal($user, "{$date} 07:30:00");
            $this->logMeal($user, "{$date} 13:00:00");
            $this->logMeal($user, "{$date} 20:00:00");
        }

        $this->recompute();

        $this->assertNotSame(13, $user->fresh()->preferred_meal_hour);
    }

    /**
     * "A user with fewer than 5 entries receives it at the 10:00 default."
     */
    public function test_a_thin_history_leaves_the_column_null(): void
    {
        Carbon::setTestNow(self::NOW);

        $user = $this->makeUser('Europe/Paris');

        foreach (array_slice($this->lastTenDays(), 0, HabitualHour::MIN_SAMPLE_DAYS - 1) as $date) {
            $this->logMeal($user, "{$date} 07:30:00");
        }

        $this->recompute();

        $this->assertNull($user->fresh()->preferred_meal_hour);
    }

    /**
     * Four days of logging is four days however many meals are in them. This is
     * the case the per-day sample is stricter about than the spec's "5 entries",
     * and deliberately so: twelve meals across four days says nothing about a
     * fifth.
     */
    public function test_many_meals_across_too_few_days_still_learns_nothing(): void
    {
        Carbon::setTestNow(self::NOW);

        $user = $this->makeUser('Europe/Paris');

        foreach (array_slice($this->lastTenDays(), 0, 4) as $date) {
            foreach (['07:30:00', '13:00:00', '20:00:00'] as $time) {
                $this->logMeal($user, "{$date} {$time}");
            }
        }

        $this->recompute();

        $this->assertNull($user->fresh()->preferred_meal_hour);
    }

    /**
     * "Nobody receives a reminder outside the clamp window", through the
     * database rather than through the array.
     */
    public function test_a_midnight_snacker_is_clamped_into_the_window(): void
    {
        Carbon::setTestNow(self::NOW);

        $user = $this->makeUser('Europe/Paris');

        foreach ($this->lastTenDays() as $date) {
            $this->logMeal($user, "{$date} 03:00:00");
        }

        $this->recompute();

        $this->assertSame(HabitualHour::EARLIEST_HOUR, $user->fresh()->preferred_meal_hour);
    }

    /**
     * The day a meal belongs to is the user's day, not the server's.
     *
     * A New Yorker's 21:00 dinner is 01:00 the next morning in UTC. Grouped by
     * UTC date it would be the *first* entry of that date, and this user — who
     * has breakfast at 09:00 every day — would have their reminder moved to
     * 21:00 rather than 09:00. The two hours are far enough apart that nothing
     * but correct local-date grouping produces the expected one.
     */
    public function test_the_first_entry_of_the_day_means_the_users_day(): void
    {
        Carbon::setTestNow(self::NOW);

        $user = $this->makeUser('America/New_York');

        foreach ($this->lastTenDays() as $date) {
            $this->logMeal($user, "{$date} 09:00:00");
            $this->logMeal($user, "{$date} 21:00:00");
        }

        $this->recompute();

        $this->assertSame(9, $user->fresh()->preferred_meal_hour);
    }

    /**
     * Workouts are learned from the v1 table the app actually writes today, not
     * only from the `workout_logs` spec 07 names. Reading `workout_logs` alone
     * would leave this user — and every real one — under the sample minimum.
     */
    public function test_the_workout_hour_is_learned_from_the_v1_table(): void
    {
        Carbon::setTestNow(self::NOW);

        $user = $this->makeUser('Europe/Paris');

        foreach ($this->lastTenDays() as $date) {
            $this->logWorkout($user, "{$date} 19:15:00");
        }

        $this->recompute();

        $this->assertSame(19, $user->fresh()->preferred_workout_hour);
    }

    /**
     * And from v2 when that is where the rows are, with the earlier of the two
     * winning on a day that has both.
     */
    public function test_the_workout_hour_takes_the_earlier_of_the_two_tables(): void
    {
        Carbon::setTestNow(self::NOW);

        $user = $this->makeUser('Europe/Paris');

        foreach ($this->lastTenDays() as $date) {
            $this->logWorkout($user, "{$date} 18:00:00");
            $this->logWorkoutLog($user, "{$date} 09:00:00");
        }

        $this->recompute();

        $this->assertSame(9, $user->fresh()->preferred_workout_hour);
    }

    /**
     * A habit is only as current as the window it was measured over. Someone who
     * logged religiously at 08:00 two months ago and has not opened the app
     * since goes back to the default rather than keeping a stale hour forever.
     */
    public function test_logging_older_than_the_window_is_forgotten(): void
    {
        Carbon::setTestNow(self::NOW);

        $user = $this->makeUser('Europe/Paris');

        for ($i = 0; $i < 10; $i++) {
            $date = Carbon::parse(self::NOW)
                ->subDays(HabitualHour::WINDOW_DAYS + 10 + $i)
                ->toDateString();

            $this->logMeal($user, "{$date} 08:30:00");
        }

        $this->recompute();

        $this->assertNull($user->fresh()->preferred_meal_hour);
    }

    /**
     * A stored hour is cleared once the logging behind it ages out, rather than
     * being left to send at an hour nothing supports any more.
     */
    public function test_a_stale_hour_is_cleared_on_the_next_run(): void
    {
        Carbon::setTestNow(self::NOW);

        $user = $this->makeUser('Europe/Paris');
        $user->preferred_meal_hour = 8;
        $user->save();

        $this->recompute();

        $this->assertNull($user->fresh()->preferred_meal_hour);
    }

    /**
     * A user who set their own quiet hours keeps the default send time rather
     * than being moved into the hours they asked not to be disturbed — where
     * NotificationService would suppress every send and they would hear nothing
     * at all.
     */
    public function test_an_hour_inside_the_users_own_quiet_window_is_not_stored(): void
    {
        Carbon::setTestNow(self::NOW);

        $user = $this->makeUser('Europe/Paris');

        NotificationPreference::create(array_merge(
            NotificationPreference::DEFAULTS,
            [
                'user_id'    => $user->id,
                'quiet_from' => '08:00',
                'quiet_to'   => '12:00',
            ]
        ));

        foreach ($this->lastTenDays() as $date) {
            $this->logMeal($user, "{$date} 09:30:00");
        }

        $this->recompute();

        $this->assertNull(
            $user->fresh()->preferred_meal_hour,
            'A 09:00 reminder would land inside this user\'s 08:00-12:00 quiet window.'
        );
    }

    /**
     * The acceptance criterion, on the sending side: the reminder now goes out
     * at the learned hour, and no longer at 10:00.
     *
     * The user has no device registered, so the command's only effect is a
     * skipped log row — which is exactly the signal wanted here, "was this user
     * selected", with no Firebase in the picture.
     */
    public function test_the_reminder_follows_the_learned_hour_and_not_the_default(): void
    {
        $user = $this->makeUser('UTC');
        $user->preferred_meal_hour = 8;
        $user->save();

        $this->mealReminderAt('2026-08-20 10:15:00');

        $this->assertSame(0, $this->mealLogs($user), 'Still being reminded at the old 10:00 default.');

        $this->mealReminderAt('2026-08-20 08:15:00');

        $this->assertSame(1, $this->mealLogs($user), 'Not reminded at the learned hour.');
    }

    /**
     * A user nothing has been learned about is untouched by any of this.
     */
    public function test_a_user_with_no_learned_hour_keeps_ten_oclock(): void
    {
        $user = $this->makeUser('UTC');

        $this->mealReminderAt('2026-08-20 08:15:00');

        $this->assertSame(0, $this->mealLogs($user));

        $this->mealReminderAt('2026-08-20 10:15:00');

        $this->assertSame(1, $this->mealLogs($user));
    }

    /**
     * "Exactly one reminder per type per day", including on the day a user's
     * hour moves and two windows catch them.
     *
     * Spec 07 asks for an explicit "already sent today" check only if spec 02's
     * once-per-day-per-type rule is absent. It is present, and this is the test
     * that says so: with a delivered reminder already on today's date, a second
     * window produces a held-back row rather than a second send.
     */
    public function test_a_second_window_on_the_same_day_cannot_send_twice(): void
    {
        Carbon::setTestNow('2026-08-20 08:15:00');

        $user = $this->makeUser('UTC');
        $user->preferred_meal_hour = 8;
        $user->save();

        NotificationLog::create([
            'user_id' => $user->id,
            'type'    => NotificationLog::TYPE_MEAL_REMINDER,
            'title'   => 'Already delivered',
            'body'    => 'Earlier today, at the hour this user used to be on.',
            'status'  => NotificationLog::STATUS_SENT,
            'sent_at' => Carbon::parse('2026-08-20 06:00:00'),
        ]);

        $this->artisan('send:daily-meal-reminder')->assertSuccessful();

        $sent = NotificationLog::where('user_id', $user->id)
            ->where('type', NotificationLog::TYPE_MEAL_REMINDER)
            ->where('status', NotificationLog::STATUS_SENT)
            ->count();

        $this->assertSame(1, $sent, 'The same reminder went out twice in one day.');

        $this->assertStringContainsString(
            'Engagement backoff',
            (string) NotificationLog::where('user_id', $user->id)
                ->where('status', NotificationLog::STATUS_SKIPPED)
                ->value('error')
        );
    }

    private function recompute(): void
    {
        $this->artisan('notifications:recompute-habitual-hours')->assertSuccessful();
    }

    private function mealReminderAt(string $utc): void
    {
        Carbon::setTestNow($utc);

        $this->artisan('send:daily-meal-reminder')->assertSuccessful();
    }

    private function mealLogs(User $user): int
    {
        return NotificationLog::where('user_id', $user->id)
            ->where('type', NotificationLog::TYPE_MEAL_REMINDER)
            ->count();
    }

    /**
     * The ten days before "now", oldest first, as Y-m-d — the callers pair them
     * with a local time and convert.
     *
     * @return string[]
     */
    private function lastTenDays(): array
    {
        $dates = [];

        for ($i = 10; $i >= 1; $i--) {
            $dates[] = Carbon::parse(self::NOW)->subDays($i)->toDateString();
        }

        return $dates;
    }

    private function makeUser(?string $timezone = null): User
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name'     => "Habitual User {$n}",
            'email'    => "habitual{$n}@example.test",
            'password' => 'x',
        ]);

        $user->timezone = $timezone;
        $user->save();

        return $user;
    }

    /**
     * @param string $localDateTime On the user's own clock — converted here, so
     *                              no test has to know what an offset was on a
     *                              given date.
     */
    private function logMeal(User $user, string $localDateTime): void
    {
        DB::table('meals')->insert([
            'user_id'    => $user->id,
            'date'       => $this->local($user, $localDateTime)->toDateString(),
            'meal_time'  => 'breakfast',
            'created_at' => $this->utc($user, $localDateTime),
            'updated_at' => $this->utc($user, $localDateTime),
        ]);
    }

    private function logWorkout(User $user, string $localDateTime): void
    {
        DB::table('workouts')->insert([
            'user_id'         => $user->id,
            'activity_type'   => 'Running',
            'duration'        => 30,
            'calories_burned' => 300,
            'workout_date'    => $this->local($user, $localDateTime)->toDateString(),
            'created_at'      => $this->utc($user, $localDateTime),
            'updated_at'      => $this->utc($user, $localDateTime),
        ]);
    }

    private function logWorkoutLog(User $user, string $localDateTime): void
    {
        DB::table('workout_logs')->insert([
            'user_id'      => $user->id,
            'workout_date' => $this->utc($user, $localDateTime),
            'duration'     => 30,
            'created_at'   => $this->utc($user, $localDateTime),
            'updated_at'   => $this->utc($user, $localDateTime),
        ]);
    }

    private function local(User $user, string $localDateTime): Carbon
    {
        return Carbon::parse($localDateTime, $user->timezoneOrDefault());
    }

    private function utc(User $user, string $localDateTime): string
    {
        return $this->local($user, $localDateTime)->utc()->toDateTimeString();
    }
}
