<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\User;
use App\Services\NotificationContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The acceptance criterion the pure rule tests cannot reach: copy generation
 * issues a constant number of queries however many users are in the batch.
 *
 * This is the property that decides whether the daily reminders keep working as
 * the app grows. A per-user query here is invisible at today's size — a few
 * hundred extra round trips inside a command nobody watches — and becomes a
 * timed-out cron job at ten times it, reported as "notifications stopped" with
 * nothing pointing at the cause. Asserting the count, rather than the runtime,
 * is what makes the regression fail in CI instead of in production.
 */
class NotificationContentQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_priming_costs_the_same_number_of_queries_for_one_user_as_for_fifty(): void
    {
        $now = Carbon::parse('2026-08-02 18:00:00');

        $one  = $this->makeUsers(1);
        $many = $this->makeUsers(50);

        $forOne  = $this->queriesToComposeFor($one, $now);
        $forMany = $this->queriesToComposeFor($many, $now);

        $this->assertSame(
            $forOne,
            $forMany,
            "Copy generation must not query per user: {$forOne} queries for 1 user, {$forMany} for 50."
        );
    }

    /**
     * A batch that has been primed must not go back to the database at all when
     * the copy is actually composed — that is the whole point of the split.
     */
    public function test_composing_after_priming_issues_no_further_queries(): void
    {
        $now   = Carbon::parse('2026-08-02 18:00:00');
        $users = $this->makeUsers(20);

        $content = new NotificationContentService();
        $content->prime($users, $now);

        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ($users as $userId) {
            $content->forUser($userId, NotificationLog::TYPE_WORKOUT_REMINDER);
        }

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queries, "forUser() queried {$queries} times after priming.");
    }

    /**
     * Two users in genuinely different states, end to end through the loading
     * half rather than through hand-built facts.
     */
    public function test_users_in_different_states_receive_different_copy(): void
    {
        $now = Carbon::parse('2026-08-02 18:00:00');

        $onARun = $this->makeUser();
        $quiet  = $this->makeUser();

        // Four training days in the week just gone, and a five-day week a
        // fortnight back to be the record that today would match.
        foreach (['2026-07-28', '2026-07-29', '2026-07-31', '2026-08-01'] as $date) {
            $this->logWorkout($onARun, $date);
        }

        foreach (['2026-07-13', '2026-07-14', '2026-07-15', '2026-07-16', '2026-07-17'] as $date) {
            $this->logWorkout($onARun, $date);
        }

        $content = new NotificationContentService();
        $content->prime([$onARun, $quiet], $now);

        $forRunner = $content->forUser($onARun, NotificationLog::TYPE_WORKOUT_REMINDER);
        $forQuiet  = $content->forUser($quiet, NotificationLog::TYPE_WORKOUT_REMINDER);

        $this->assertStringContainsString('4 days this week', $forRunner['body']);
        $this->assertStringContainsString('record of 5', $forRunner['body']);

        // The spec's other acceptance criterion, from the same run: nothing
        // notable still produces the generic reminder rather than nothing.
        $this->assertSame("🏋️ Time for Your Workout!", $forQuiet['title']);
        $this->assertNotSame($forRunner['body'], $forQuiet['body']);
    }

    /**
     * Count the queries a whole compose pass costs, priming included.
     *
     * @param int[] $userIds
     */
    private function queriesToComposeFor(array $userIds, Carbon $now): int
    {
        $content = new NotificationContentService();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $content->prime($userIds, $now);

        foreach ($userIds as $userId) {
            $content->forUser($userId, NotificationLog::TYPE_WORKOUT_REMINDER);
        }

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return int[]
     */
    private function makeUsers(int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->makeUser();
        }

        return $ids;
    }

    private function makeUser(): int
    {
        static $n = 0;
        $n++;

        return User::create([
            'name'           => "Test User {$n}",
            'email'          => "user{$n}@example.test",
            'password'       => 'x',
            'age'            => 29,
            'weight'         => 72.5,
            'height'         => 178,
            'gender'         => 'male',
            'activity_level' => 'moderate',
            'fitness_goal'   => 'weight_loss',
        ])->id;
    }

    private function logWorkout(int $userId, string $date): void
    {
        DB::table('workouts')->insert([
            'user_id'         => $userId,
            'activity_type'   => 'Running',
            'duration'        => 30,
            'calories_burned' => 300,
            'workout_date'    => $date,
            'created_at'      => $date . ' 12:00:00',
            'updated_at'      => $date . ' 12:00:00',
        ]);
    }
}
