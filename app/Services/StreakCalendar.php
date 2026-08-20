<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * The whole streak rule, with no database in it.
 *
 * A **streak day** is any day the user logged at least one meal or one workout.
 * Meal-only and workout-only streaks are more precise and multiply both the copy
 * and the edge cases; one combined streak is the one a user can explain back to
 * you, and a streak nobody understands motivates nobody.
 *
 * Days are `Y-m-d` strings in the user's own timezone, never DateTimes. That is
 * deliberate: the only questions asked here are "is this the same day", "is this
 * the day after" and "is this earlier", and on a `Y-m-d` string all three are
 * exact. Carrying moments instead would drag a timezone into arithmetic that has
 * already had one applied, which is the mistake that breaks streaks at midnight
 * for every user not living on the server's clock.
 *
 * There is one rule — advance() — and both writers use it: the observer applies
 * it once, for today, and the backfill applies it once per historical day. Two
 * implementations of "what counts as a streak" would be two answers to the same
 * question, and the disagreement would surface as a user's streak changing when
 * nothing about their logging had.
 */
final class StreakCalendar
{
    /**
     * Below this, an at-risk nudge is nagging rather than saving.
     *
     * A user who logged once yesterday has no run worth defending and so no
     * loss to be averse to; telling them they have one teaches them the number
     * means nothing. Three days is where the streak itself becomes the thing
     * the user does not want to lose.
     */
    public const AT_RISK_THRESHOLD = 3;

    /**
     * Fold one more logged day into a streak.
     *
     * @param  string $day The local date the user logged on, `Y-m-d`.
     * @param  string|null $lastDay The streak's last qualifying local date.
     * @return array{current_days:int,longest_days:int,last_day:string} Ready to
     *         fill straight onto the model — the keys are the column names.
     */
    public static function advance(?int $current, ?int $longest, ?string $lastDay, string $day): array
    {
        $current = max(0, (int) $current);
        $longest = max(0, (int) $longest);

        // Already counted, or older than what has been counted. The second half
        // is the one worth stating: a write can arrive dated before `last_day`
        // — a device with a skewed clock, a user whose timezone moved west, a
        // backdated row from an import — and reading that as "not consecutive"
        // would reset a live streak to 1 on the strength of a log the user made
        // *earlier*. Ignoring it costs at most one uncounted day; acting on it
        // destroys a run they actually earned.
        if ($lastDay !== null && $day <= $lastDay) {
            return [
                'current_days' => $current,
                'longest_days' => $longest,
                'last_day'     => $lastDay,
            ];
        }

        $current = ($lastDay !== null && $day === self::dayAfter($lastDay)) ? $current + 1 : 1;

        return [
            'current_days' => $current,
            // Personal bests never decrease. It is the one number a user can
            // look at the morning after breaking a streak without feeling they
            // have been reset to nothing.
            'longest_days' => max($longest, $current),
            'last_day'     => $day,
        ];
    }

    /**
     * What `current_days` actually is on a given day, as opposed to what the
     * column happens to hold.
     *
     * The column is only written when the user logs something, so it goes stale
     * the moment they stop: someone who ran to 12 days and then vanished still
     * has 12 sitting in their row. It is not wrong — it was true when written —
     * but anything reading it later has to ask "as of when", and this is that
     * question. A streak whose last day is neither today nor yesterday is over.
     *
     * Nothing recomputes the column in the background, on purpose. A nightly job
     * zeroing lapsed streaks would be a second writer racing the observer for
     * the same rows, to produce a value this derives for free.
     */
    public static function settled(?int $current, ?string $lastDay, string $today): int
    {
        if ($lastDay === null) {
            return 0;
        }

        return in_array($lastDay, [$today, self::dayBefore($today)], true)
            ? max(0, (int) $current)
            : 0;
    }

    /**
     * Is this streak long enough to be worth saving, and about to be lost?
     *
     * "Nothing logged today" is not a separate query. `last_day` is stamped by
     * the observer on every qualifying write, so `last_day === yesterday` says
     * both that yesterday counted and that today has not — yet. A streak whose
     * last day is older than yesterday is already broken, and a notification
     * telling someone they have already failed demotivates where one offering a
     * save converts, so those users hear nothing.
     */
    public static function isAtRisk(?int $current, ?string $lastDay, string $today): bool
    {
        return $lastDay === self::dayBefore($today)
            && self::settled($current, $lastDay, $today) >= self::AT_RISK_THRESHOLD;
    }

    public static function dayAfter(string $day): string
    {
        return self::date($day)->addDay()->format('Y-m-d');
    }

    public static function dayBefore(string $day): string
    {
        return self::date($day)->subDay()->format('Y-m-d');
    }

    /**
     * Parsed in UTC, and only ever formatted back to `Y-m-d`.
     *
     * The date handed in is already local — the caller converted it — so this
     * must not apply a second offset to it. UTC has no DST, which is what makes
     * addDay() here mean "the next date on a calendar" rather than "24 hours
     * later"; in a zone with a transition those two are not the same day, and
     * a user in Santiago would lose a streak to their own clocks going back.
     *
     * The leading `!` resets the unparsed fields to zero rather than to now, so
     * a call made at 23:59 cannot roll the date over inside createFromFormat.
     */
    private static function date(string $day): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $day, 'UTC');
    }
}
