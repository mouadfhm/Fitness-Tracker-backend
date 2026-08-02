<?php

namespace App\Console\Commands;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\HabitualHour;
use App\Services\QuietHours;
use App\Services\ReminderWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Learns, once a week, what hour each user actually opens the app at.
 *
 * Writes `users.preferred_meal_hour` and `users.preferred_workout_hour`, which
 * SendDailyMealReminder and SendDailyReminder read to decide whose turn it is.
 * The two decisions worth understanding — what a "habitual hour" is a median of,
 * and what range it is allowed to land in — are in App\Services\HabitualHour.
 * This class is the loading, the timezone arithmetic and the write.
 *
 * ## Why the offsets, and not just `HOUR(created_at)`
 *
 * The hour that matters is the one on the user's clock, and the column is in
 * UTC. Converting per row in PHP would mean pulling a month of every user's
 * meals into memory. Converting per user in SQL would mean a query per user.
 * So users are grouped by their current UTC offset and each *group* gets one
 * query with the shift baked in — one or two queries in practice, because a
 * user base clusters into very few offsets.
 *
 * That grouping is also what makes the per-day part correct. "The first entry
 * of the day" has to mean the user's day: for anyone west of Greenwich the
 * evening meal falls on the next UTC date, so grouping by `DATE(created_at)`
 * would score dinner as the following morning's first entry and move every
 * American user's reminder into their evening.
 *
 * ## DST
 *
 * The offset used is the one in force now, applied to the whole 30-day window.
 * For the fortnight either side of a transition that is an hour out for the
 * part of the window on the far side of it, which can move a median by an hour
 * and is corrected by the next weekly run. The alternative — resolving the
 * offset per row — costs the row-by-row conversion this is built to avoid, to
 * fix an hour of drift in an estimate that is already rounded to the hour.
 */
class RecomputeHabitualSendHours extends Command
{
    protected $signature = 'notifications:recompute-habitual-hours';
    protected $description = 'Recompute each user\'s habitual meal and workout logging hour';

    /**
     * Matches the chunk size the reminder commands walk users in. Nothing here
     * is sensitive to it beyond memory: a chunk holds at most this many users'
     * worth of one row per day per table.
     */
    private const CHUNK = 500;

    /**
     * Where completed workouts land.
     *
     * Both, for the reason NotificationContentService gives at trainingByUser():
     * the app writes v1 `workouts` today and `workout_logs` once v2 logging is
     * switched on. Reading only `workout_logs` — which is what spec 07 names —
     * would leave every user below the minimum sample and quietly make the
     * workout half of this feature do nothing at all.
     */
    private const WORKOUT_TABLES = ['workouts', 'workout_logs'];

    public function handle(): int
    {
        $now   = Carbon::now();
        $since = $now->copy()->subDays(HabitualHour::WINDOW_DAYS);

        $scanned = 0;
        $changed = 0;
        $onMeal  = 0;
        $onGym   = 0;

        User::query()
            ->with('notificationPreference')
            ->chunkById(self::CHUNK, function ($users) use ($now, $since, &$scanned, &$changed, &$onMeal, &$onGym) {
                [$mealDays, $workoutDays] = $this->firstEntryDays($users, $now, $since);

                foreach ($users as $user) {
                    $scanned++;

                    $meal = $this->deliverable(
                        $user,
                        HabitualHour::fromDailyHours(self::hours($mealDays[$user->id] ?? []))
                    );

                    $workout = $this->deliverable(
                        $user,
                        HabitualHour::fromDailyHours(self::hours($workoutDays[$user->id] ?? []))
                    );

                    $user->preferred_meal_hour    = $meal;
                    $user->preferred_workout_hour = $workout;

                    // Assigned unconditionally, saved only if that changed
                    // something. A user who stopped logging a month ago has
                    // their learned hour cleared here and goes back to the
                    // default, which is the right answer: the estimate is only
                    // as good as the window it was measured over.
                    if ($user->isDirty()) {
                        $user->save();
                        $changed++;
                    }

                    $onMeal += $meal === null ? 0 : 1;
                    $onGym  += $workout === null ? 0 : 1;
                }
            });

        $this->info(
            "Habitual send hours: {$scanned} users scanned, {$changed} updated. " .
            "{$onMeal} on a learned meal hour, {$onGym} on a learned workout hour."
        );

        return self::SUCCESS;
    }

    /**
     * The first entry on each local date, per user, for both kinds of logging.
     *
     * @param  \Illuminate\Support\Collection<int,User> $users
     * @return array{0:array<int,array<string,int>>,1:array<int,array<string,int>>}
     *         meals and workouts, each userId => localDate => seconds past local midnight
     */
    private function firstEntryDays($users, Carbon $now, Carbon $since): array
    {
        $idsByOffset = [];

        foreach ($users as $user) {
            $idsByOffset[intdiv($user->localNow($now)->getOffset(), 60)][] = $user->id;
        }

        $meals    = [];
        $workouts = [];

        foreach ($idsByOffset as $offset => $ids) {
            self::keepEarliest($meals, $this->firstEntryTimes('meals', $ids, $since, (int) $offset));

            foreach (self::WORKOUT_TABLES as $table) {
                self::keepEarliest($workouts, $this->firstEntryTimes($table, $ids, $since, (int) $offset));
            }
        }

        return [$meals, $workouts];
    }

    /**
     * One row per user per local date: when, in seconds past their midnight,
     * they first logged something in this table.
     *
     * $offsetMinutes is an int the caller derived from the tz database and is
     * interpolated rather than bound, because the same expression has to appear
     * in the GROUP BY and the SELECT and be identical in both.
     *
     * MySQL-only (DATE_ADD, TIME_TO_SEC). So is the rest of this schema — see
     * the note in phpunit.xml about why SQLite is not an option here.
     *
     * @param  int[] $userIds
     * @return array<int,array<string,int>> userId => localDate => seconds
     */
    private function firstEntryTimes(string $table, array $userIds, Carbon $since, int $offsetMinutes): array
    {
        $local = "DATE_ADD({$table}.created_at, INTERVAL {$offsetMinutes} MINUTE)";

        $rows = DB::table($table)
            ->whereIn('user_id', $userIds)
            ->whereNotNull('created_at')
            ->where('created_at', '>=', $since)
            ->groupBy('user_id', DB::raw("DATE({$local})"))
            ->selectRaw("user_id, DATE({$local}) as local_date, MIN(TIME_TO_SEC(TIME({$local}))) as first_sec")
            ->get();

        $days = [];

        foreach ($rows as $row) {
            $days[(int) $row->user_id][(string) $row->local_date] = (int) $row->first_sec;
        }

        return $days;
    }

    /**
     * Fold one table's first-entry times into the running set, keeping whichever
     * is earlier on each day.
     *
     * A user who logged a v1 workout at 07:00 and a v2 one at 19:00 on the same
     * day started training that day at 07:00, whatever table each row is in.
     *
     * @param array<int,array<string,int>> $into
     * @param array<int,array<string,int>> $from
     */
    private static function keepEarliest(array &$into, array $from): void
    {
        foreach ($from as $userId => $days) {
            foreach ($days as $date => $seconds) {
                if (!isset($into[$userId][$date]) || $seconds < $into[$userId][$date]) {
                    $into[$userId][$date] = $seconds;
                }
            }
        }
    }

    /**
     * @param  array<string,int> $days localDate => seconds past local midnight
     * @return int[]
     */
    private static function hours(array $days): array
    {
        return array_values(array_map(
            static fn (int $seconds): int => intdiv($seconds, 3600),
            $days
        ));
    }

    /**
     * The hour, or null if a reminder sent at it would never arrive.
     *
     * HabitualHour's bounds already clear the *default* quiet window, so this
     * only ever fires for someone who set their own. For them, silently moving
     * the reminder into the hours they asked not to be disturbed would turn a
     * user who gets reminders today into one who gets nothing — the same
     * regression those bounds exist to prevent, arrived at from the other side.
     * Returning null hands them back the 10:00 / 18:30 they have now, which
     * their quiet hours may or may not allow, exactly as before this feature.
     *
     * Both ends of the send window are checked: ReminderWindow fires anywhere
     * in [hour, hour + INTERVAL_MINUTES), and a quiet window need not start on
     * the hour.
     */
    private function deliverable(User $user, ?int $hour): ?int
    {
        if ($hour === null) {
            return null;
        }

        $preferences = $user->notificationPreference
            ?? new NotificationPreference(NotificationPreference::DEFAULTS);

        $opens  = Carbon::createFromTime($hour, 0, 0);
        $closes = $opens->copy()->addMinutes(ReminderWindow::INTERVAL_MINUTES - 1);

        foreach ([$opens, $closes] as $moment) {
            if (QuietHours::covers($moment, $preferences->quiet_from, $preferences->quiet_to)) {
                return null;
            }
        }

        return $hour;
    }
}
