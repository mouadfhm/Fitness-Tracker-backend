<?php

namespace App\Services;

use Carbon\CarbonInterface;

/**
 * Decides when the reminder for one scheduled session goes out.
 *
 * Sibling of ReminderWindow, and deliberately not an extension of it. That one
 * answers "is this user's clock at 18:30?" — a target fixed at compile time,
 * shared by every user, checked every 30 minutes. This one has a different
 * target for every row, derived from when the user said they would train, and
 * runs hourly. The only thing the two share is the half-open-window trick, and
 * copying six lines of arithmetic is cheaper than a base class that has to be
 * read twice to see which half applies.
 *
 * ## Why there are two cases
 *
 * The spec this implements says: remind about sessions starting in 30-90
 * minutes. That is the right rule and it currently applies to nothing. Every
 * scheduled_workouts row in production sits at 00:00 or 01:00, because no screen
 * in the app has ever shown a time picker — `workout_details_screen.dart` and
 * `new_workout_cycle_screen.dart` both call showDatePicker, and
 * WeeklyCyclePlanController::generateSchedule materialises cycle plans at
 * midnight. `scheduled_at` is, in practice, a date with a junk time on the end.
 *
 * Applied literally to that data the rule fires at about 22:30 the night before
 * ("Leg Day in an hour" at half past ten), and spec 08's default quiet window of
 * 22:00-08:00 then swallows most of what is left. So a session with no real time
 * is reminded on the morning of the day itself instead, and a session with a
 * real time gets the spec's rule exactly. Add a time picker later and the second
 * case starts applying on its own, with nothing here to change.
 */
class SessionReminderWindow
{
    /**
     * How often the command runs, and therefore how wide every window below is.
     *
     * Equal on purpose, exactly as in ReminderWindow: consecutive runs are an
     * hour apart and each window is a half-open hour, so a session can neither
     * be matched by two runs nor slip between them. This is what makes "exactly
     * one reminder" a property of the arithmetic rather than a hope, and it
     * holds for the offsets that are not whole hours (Asia/Kathmandu is +05:45)
     * because the runs stay 60 minutes apart wherever the user is.
     *
     * Change this and you must change the ->hourly() in routes/console.php with
     * it, in the same commit.
     */
    public const INTERVAL_MINUTES = 60;

    /**
     * How far ahead of a properly timed session the reminder goes out.
     *
     * The window is [start - 90min, start - 30min), i.e. "starting in the next
     * 30 to 90 minutes" — enough warning to get to a gym, not so much that the
     * user has forgotten by the time it matters.
     */
    public const LEAD_MINUTES = 90;

    /**
     * Local hour for a session that has a date but no time.
     *
     * 08:00 rather than something later for two reasons: it is the moment the
     * default quiet window ends (QuietHours is half-open, so 08:00 is already
     * audible), and it cannot collide with the 18:30 generic workout reminder,
     * which would otherwise put two workout notifications on the same device
     * inside one window.
     */
    public const FALLBACK_HOUR = 8;

    /**
     * Below this hour, a time is treated as an artefact rather than an intention.
     *
     * A threshold and not an `=== 00:00` check because the existing rows are
     * split between midnight and 01:00 — the cycle-plan generator and the older
     * data disagree — and a future writer could add a third. 04:00 is chosen
     * because it sits comfortably above every artefact time in the table and
     * below any hour a person would actually plan to train: the earliest gyms
     * open at five. The cost of the heuristic being wrong is bounded and small
     * in one direction only — someone who genuinely schedules a 03:00 session is
     * reminded that morning at 08:00 rather than at 01:30, which is late but not
     * absurd. The reverse mistake, reading midnight as intent, is the 22:30
     * reminder this exists to avoid.
     */
    public const MEANINGFUL_TIME_FROM_HOUR = 4;

    /**
     * Did the user pick this time, or did a date picker leave it there?
     */
    public static function hasMeaningfulTime(CarbonInterface $scheduledAt): bool
    {
        return $scheduledAt->hour >= self::MEANINGFUL_TIME_FROM_HOUR;
    }

    /**
     * The instant the window opens, on the user's own clock.
     *
     * @param CarbonInterface $scheduledAt Already in the user's timezone.
     */
    public static function remindAt(CarbonInterface $scheduledAt): CarbonInterface
    {
        if (self::hasMeaningfulTime($scheduledAt)) {
            return $scheduledAt->copy()->subMinutes(self::LEAD_MINUTES);
        }

        return $scheduledAt->copy()->setTime(self::FALLBACK_HOUR, 0);
    }

    /**
     * Is this session's reminder due on this run?
     *
     * Both arguments are the user's wall clock — `scheduled_at` is stored as the
     * local time the app posted, not as UTC, so neither value may be compared
     * against the server's clock.
     *
     * Differenced as timestamps rather than with diffInMinutes(). The two agree
     * on ordinary days; on the day a zone shifts they do not, because Carbon's
     * diff counts calendar minutes between two wall clocks and a run an hour
     * later can then read as 0 or 120 minutes on. Timestamps are elapsed real
     * time, which is what "an hour after the last run" means.
     */
    public static function isDue(CarbonInterface $localNow, CarbonInterface $scheduledAt): bool
    {
        $elapsed = ($localNow->getTimestamp() - self::remindAt($scheduledAt)->getTimestamp()) / 60;

        return $elapsed >= 0 && $elapsed < self::INTERVAL_MINUTES;
    }
}
