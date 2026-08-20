<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserStreak;
use App\Services\StreakCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds every user's streak from the meals and workouts they have already
 * logged.
 *
 * Run once by the create migration, and available by hand afterwards. Without
 * it every existing user launches this feature on zero, and a retention
 * mechanic that opens by telling a two-year user their streak is nothing reads
 * as a punishment for having been here first.
 *
 * Idempotent, so it doubles as the repair tool: if a run of the observer is
 * ever lost — a deploy mid-request, a database failover — `streaks:backfill
 * --user=` puts one row back without touching anybody else's.
 *
 * ## Why the offsets, and not just `DATE(created_at)`
 *
 * The same reason RecomputeHabitualSendHours next door does it: the day that
 * counts is the day on the user's clock and the column is UTC. Converting per
 * row in PHP means pulling every meal ever logged into memory; converting per
 * user in SQL means a query per user. Users are grouped by their current UTC
 * offset instead, and each group gets one query per table with the shift baked
 * in — a handful of queries in practice, because a user base clusters into very
 * few offsets.
 *
 * ## DST
 *
 * The offset applied is the one in force now, across the user's whole history.
 * A day logged within an hour of local midnight, on the far side of a
 * transition, can therefore land on the adjacent date and either join or break
 * a run it should not have. That is a handful of rows per user at worst, it
 * self-corrects the next time they log, and the alternative is resolving the
 * offset per row — which costs exactly the row-by-row conversion this is built
 * to avoid.
 */
class BackfillStreaks extends Command
{
    protected $signature = 'streaks:backfill {--user= : Rebuild one user instead of all of them}';
    protected $description = 'Rebuild user streaks from existing meal and workout history';

    /**
     * Matches the chunk the reminder commands walk users in. Nothing here is
     * sensitive to it beyond memory: a chunk holds at most this many users'
     * worth of distinct dates.
     */
    private const CHUNK = 500;

    /**
     * Everything that makes a day count.
     *
     * All three, and the third is the one that would be easy to leave out. The
     * spec names `meals` and `workout_logs`; `workouts` is v1, it is still the
     * table the app writes when a user logs a workout today, and omitting it
     * would backfill most users a streak built from meals alone — plausible
     * enough to look right, and wrong for everyone who trains without logging
     * what they ate.
     */
    private const SOURCE_TABLES = ['meals', 'workouts', 'workout_logs'];

    public function handle(): int
    {
        $now  = Carbon::now();
        $only = $this->option('user');

        $rebuilt = 0;
        $onARun  = 0;

        User::query()
            ->when($only, fn ($query) => $query->whereKey($only))
            ->chunkById(self::CHUNK, function ($users) use ($now, &$rebuilt, &$onARun) {
                $daysByUser = $this->loggedDays($users, $now);
                $rows = [];

                foreach ($users as $user) {
                    $days = $daysByUser[$user->id] ?? [];

                    if ($days === []) {
                        // No row rather than a row of zeroes. "Never logged
                        // anything" and "logged, then lapsed" are different
                        // states and only the second is a broken streak.
                        continue;
                    }

                    $row = $this->replay($days, $user->localNow($now)->format('Y-m-d'), (int) $user->id);

                    $rows[] = $row;
                    $rebuilt++;
                    $onARun += $row['current_days'] > 0 ? 1 : 0;
                }

                if ($rows !== []) {
                    UserStreak::upsert($rows, ['user_id'], ['current_days', 'longest_days', 'last_day']);
                }
            });

        $this->info("Streak backfill: {$rebuilt} users rebuilt, {$onARun} of them on a live streak.");

        return self::SUCCESS;
    }

    /**
     * Replay a user's history through the same rule the observer applies live.
     *
     * Deliberately a loop over StreakCalendar::advance() rather than a smarter
     * single-pass scan. The clever version would be a second implementation of
     * "what counts as a streak", and the day it disagreed with the observer a
     * user's number would change on a deploy rather than on anything they did.
     *
     * @param  string[] $days The user's local dates, unsorted, deduplicated.
     * @return array{user_id:int,current_days:int,longest_days:int,last_day:string}
     */
    private function replay(array $days, string $today, int $userId): array
    {
        sort($days);

        $state = ['current_days' => 0, 'longest_days' => 0, 'last_day' => null];

        foreach ($days as $day) {
            $state = StreakCalendar::advance(
                $state['current_days'],
                $state['longest_days'],
                $state['last_day'],
                $day
            );
        }

        return [
            'user_id' => $userId,
            // Settled rather than stored raw. A user whose last log was in
            // March has a `current_days` of whatever their run reached back
            // then, and writing that straight into a fresh table would greet
            // them with a streak they lost months ago. `longest_days` keeps it.
            'current_days' => StreakCalendar::settled($state['current_days'], $state['last_day'], $today),
            'longest_days' => $state['longest_days'],
            'last_day'     => $state['last_day'],
        ];
    }

    /**
     * Every local date each user logged anything on.
     *
     * @param  \Illuminate\Support\Collection<int,User> $users
     * @return array<int,string[]> userId => local dates, deduplicated
     */
    private function loggedDays($users, Carbon $now): array
    {
        $idsByOffset = [];

        foreach ($users as $user) {
            $idsByOffset[intdiv($user->localNow($now)->getOffset(), 60)][] = $user->id;
        }

        // userId => date => true, because the three tables overlap heavily: a
        // day with both a meal and a workout on it is one streak day, not two.
        $seen = [];

        foreach ($idsByOffset as $offset => $ids) {
            foreach (self::SOURCE_TABLES as $table) {
                foreach ($this->localDates($table, $ids, (int) $offset) as $row) {
                    $seen[(int) $row->user_id][(string) $row->local_date] = true;
                }
            }
        }

        return array_map(
            static fn (array $dates): array => array_keys($dates),
            $seen
        );
    }

    /**
     * One row per user per local date they logged in this table.
     *
     * $offsetMinutes is an int the caller derived from the tz database, and it
     * is interpolated rather than bound because the same expression has to
     * appear in the SELECT and the GROUP BY and be identical in both.
     *
     * MySQL-only (DATE_ADD). So is the rest of this schema — see the note in
     * phpunit.xml about why SQLite is not an option here.
     *
     * @param int[] $userIds
     */
    private function localDates(string $table, array $userIds, int $offsetMinutes)
    {
        $local = "DATE(DATE_ADD({$table}.created_at, INTERVAL {$offsetMinutes} MINUTE))";

        return DB::table($table)
            ->whereIn('user_id', $userIds)
            ->whereNotNull('created_at')
            ->groupBy('user_id', DB::raw($local))
            ->selectRaw("user_id, {$local} as local_date")
            ->get();
    }
}
