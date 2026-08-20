<?php

namespace App\Services;

/**
 * Turns a user's logging history into the hour their reminder should go out.
 *
 * Reminders fired at 10:00 and 18:30 for everybody. Someone who always logs
 * breakfast at 07:30 has either already logged by 10:00 — in which case the
 * reminder is suppressed and they hear nothing all day — or forgot three hours
 * ago. This is the arithmetic that moves the send to the hour they actually
 * open the app.
 *
 * Pure and free of both Eloquent and the database, like ReminderWindow and
 * QuietHours next to it. RecomputeHabitualSendHours does the loading and the
 * writing; everything worth arguing about is in here where it can be tested
 * with a list of integers.
 *
 * ## What is measured, and why it is not what the spec says
 *
 * Spec 07 asks for the median hour of the user's `meals`. Taken literally that
 * is the median of *every* meal, which for anyone logging three a day is lunch
 * — around 13:00. Both reminders only reach users who have not logged yet
 * today, so a 13:00 meal reminder would be suppressed on every day the user
 * behaves normally, and on the days they forget it would arrive three hours
 * *later* than the 10:00 it replaced. Exactly backwards.
 *
 * So the sample is one hour per day: the hour of the user's **first** entry on
 * each of their own local dates. That is the hour the spec's own example is
 * about ("always logs breakfast at 07:30"), it is what the meal reminder's copy
 * says ("Time for Your First Meal of The Day"), and it is the only reading
 * under which the reminder lands before the behaviour it is nudging.
 */
final class HabitualHour
{
    /**
     * How far back the estimate looks, and how many days in that window must
     * carry an entry before it is trusted.
     *
     * Spec 07 says five entries. Five *days* is the same number applied to the
     * sample above, and it is the stricter bar: someone who logged five meals
     * in one enthusiastic afternoon and then nothing has told us about that
     * afternoon, not about their routine. Below the bar the column stays null
     * and the user keeps 10:00 / 18:30.
     */
    public const WINDOW_DAYS     = 30;
    public const MIN_SAMPLE_DAYS = 5;

    /**
     * The window a computed hour is allowed to land in.
     *
     * Two things set these bounds. The first is not waking people up: a user
     * who once logged a 3am snack must not be sent a reminder at 3am, which is
     * the failure spec 07 calls out by name.
     *
     * The second is why they are 08–21 rather than the 07–22 the spec suggests.
     * Spec 08 ships a default quiet window of 22:00–08:00, and a user who has
     * never opened the settings screen has that window whether they know it or
     * not. A reminder computed to 07:00 would be suppressed by it — so a user
     * whose habit we successfully learned would go from receiving a 10:00
     * reminder to receiving nothing at all, which is a worse outcome than not
     * having built this. These bounds are the widest that clear that default at
     * both ends: ReminderWindow sends in [hour, hour+30min), so 08:00 starts
     * the moment quiet hours end and 21:00 finishes half an hour before they
     * begin.
     *
     * A user who has set their *own* quiet hours is a different case and is
     * handled where the preferences can actually be read — see
     * RecomputeHabitualSendHours::deliverable().
     */
    public const EARLIEST_HOUR = 8;
    public const LATEST_HOUR   = 21;

    /**
     * The hour to store for a user, or null to leave them on the default.
     *
     * @param int[] $hours One hour per day the user logged something, in any
     *                     order. Duplicates are the point — five days at 07:00
     *                     and one at 03:00 is five sevens and a three.
     */
    public static function fromDailyHours(array $hours): ?int
    {
        if (count($hours) < self::MIN_SAMPLE_DAYS) {
            return null;
        }

        return self::clamp(self::median($hours));
    }

    /**
     * The middle value, taking the earlier of the two when there is an even
     * number of them.
     *
     * Median rather than mean because the mean is what one 3am snack drags the
     * whole estimate towards; the median does not notice it. The tie-break
     * downwards is deliberate too: a reminder half an hour early still finds
     * the user before they log, and one half an hour late finds them after.
     *
     * Known limitation: this is a median on a line, and hours are a circle. A
     * user whose entries straddle midnight — 23:00, 23:30, 00:15, 01:00 — gets
     * a median somewhere in the middle of the day rather than near midnight.
     * Nobody with that pattern survives the clamp above in any case, so the
     * error is not reachable from the stored column; it is written down because
     * it would be if the bounds were ever widened.
     *
     * @param int[] $hours Non-empty.
     */
    private static function median(array $hours): int
    {
        sort($hours);

        return $hours[intdiv(count($hours) - 1, 2)];
    }

    public static function clamp(int $hour): int
    {
        return max(self::EARLIEST_HOUR, min(self::LATEST_HOUR, $hour));
    }

    /**
     * Is a stored value one this class could have produced?
     *
     * Read on every send, so it is the guard against a column edited by hand,
     * written by a future caller that skipped the clamp, or left behind by a
     * narrowing of the bounds above. An hour outside the window is not clamped
     * into it — it is disbelieved, and the user falls back to the default they
     * had before any of this existed.
     */
    public static function isSane(?int $hour): bool
    {
        return $hour !== null
            && $hour >= self::EARLIEST_HOUR
            && $hour <= self::LATEST_HOUR;
    }

    /**
     * When this user's reminder of this kind is due, on their own clock.
     *
     * The default carries a minute and the learned hour does not, which is the
     * whole reason this returns a pair. The workout reminder has always gone
     * out at 18:30 and must keep doing so for everyone we have learned nothing
     * about; a learned 18 means 18:00.
     *
     * @return array{0:int,1:int} hour, minute
     */
    public static function sendTime(?int $preferred, int $defaultHour, int $defaultMinute = 0): array
    {
        if (self::isSane($preferred)) {
            return [$preferred, 0];
        }

        return [$defaultHour, $defaultMinute];
    }
}
