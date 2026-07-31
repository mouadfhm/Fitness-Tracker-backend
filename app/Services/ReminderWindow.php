<?php

namespace App\Services;

use Carbon\CarbonInterface;

/**
 * Decides whether a given wall-clock time falls in a reminder's send window.
 *
 * Reminders used to be scheduled once a day with ->timezone('Africa/Casablanca'),
 * which is only correct for users who happen to live in Morocco. They now run on
 * a fixed cadence and each user is selected when *their* clock reads the target
 * time, so the "when" moved out of the scheduler and into here.
 */
class ReminderWindow
{
    /**
     * How often the reminder commands run, and therefore how wide the window is.
     *
     * These two are the same number on purpose. The window is half-open —
     * [target, target + 30min) — so consecutive runs can neither both match
     * (they are 30 minutes apart) nor both miss it. Every user gets exactly one
     * match per day whatever their offset.
     *
     * That last part is why this is not the "run hourly, compare the hour"
     * check it looks like it should be. tz offsets are not all whole hours:
     * Asia/Kolkata is +05:30, Asia/Kathmandu +05:45, Australia/Adelaide +09:30.
     * Comparing only the hour would fire twice for them, and comparing
     * hour-and-minute exactly against an on-the-hour target would never fire at
     * all. It also keeps the workout reminder at 18:30, where it has always been.
     */
    public const INTERVAL_MINUTES = 30;

    private const MINUTES_PER_DAY = 1440;

    /**
     * Is it this user's turn, given the time their own clock shows?
     *
     * DST is left alone here. A zone that springs forward across the window
     * skips that day's reminder and one that falls back would repeat it, but
     * transitions happen between midnight and 04:00 everywhere the tz database
     * knows about, and both targets are well clear of that. The engagement
     * backoff is the backstop in any case: it requires at least a day between
     * sends of the same type, so a repeat inside one day cannot get out.
     */
    public static function isDue(CarbonInterface $localNow, int $targetHour, int $targetMinute = 0): bool
    {
        $elapsed = ($localNow->hour * 60 + $localNow->minute)
            - ($targetHour * 60 + $targetMinute);

        // Modulo, not abs(): a target of 00:15 still has to match at 00:20 on a
        // clock that wrapped past midnight forty minutes ago.
        $elapsed = ($elapsed % self::MINUTES_PER_DAY + self::MINUTES_PER_DAY) % self::MINUTES_PER_DAY;

        return $elapsed < self::INTERVAL_MINUTES;
    }
}
