<?php

namespace App\Services;

use Carbon\CarbonInterface;

/**
 * Decides whether a wall-clock time falls inside a user's quiet window.
 *
 * Split out of the model, and kept free of both Eloquent and the database, for
 * the same reason as ReminderWindow: the interesting part is half a dozen lines
 * of arithmetic whose edges are worth testing directly.
 *
 * The edge that matters is the wrap. A quiet window is normally something like
 * 22:00-08:00, which is not a range at all in the `$start <= $now <= $end`
 * sense — it is the *complement* of one. Written as a naive between, 22:00-08:00
 * matches nothing whatsoever and every user quietly receives notifications all
 * night, which reads as "the feature does nothing" rather than as a bug.
 */
class QuietHours
{
    /**
     * Is $localNow inside the window, given a time the caller has already
     * converted to the user's own zone?
     *
     * Half-open — [from, to) — so a window ending at 08:00 is over at 08:00
     * rather than swallowing that minute. Consistent with ReminderWindow, and it
     * makes a 22:00-08:00 window exactly ten hours instead of ten hours and a
     * minute.
     *
     * Returns false, meaning "not quiet, go ahead and send", for every input it
     * cannot make sense of:
     *
     * - either end null: quiet hours are switched off. They go null as a pair,
     *   but a half-set pair is read the same way rather than by guessing the
     *   missing end.
     * - both ends equal: a zero-length window. The alternative reading, a full
     *   24 hours, would let someone mute themselves permanently by dragging two
     *   pickers to the same value, and then wonder why the app went silent.
     * - unparseable: a value that predates a format change, or one that arrived
     *   from somewhere that skipped validation. Failing open costs one
     *   notification's worth of peace; failing closed would stop that user's
     *   notifications for good, with nothing on the device to explain it.
     */
    public static function covers(CarbonInterface $localNow, ?string $from, ?string $to): bool
    {
        $start = self::minutesSinceMidnight($from);
        $end   = self::minutesSinceMidnight($to);

        if ($start === null || $end === null || $start === $end) {
            return false;
        }

        $now = $localNow->hour * 60 + $localNow->minute;

        // Same-day window, e.g. 13:00-14:00.
        if ($start < $end) {
            return $now >= $start && $now < $end;
        }

        // Wrapped window, e.g. 22:00-08:00: late enough yesterday, or early
        // enough today. This is the branch a `between` silently loses.
        return $now >= $start || $now < $end;
    }

    /**
     * Normalise a stored time for the API, so clients see one shape.
     *
     * MySQL hands back `time` columns as `HH:MM:SS`, while the defaults and the
     * app both speak `HH:MM`. Rather than let that difference reach the phone,
     * everything leaves here as `HH:MM`.
     */
    public static function format(?string $time): ?string
    {
        $minutes = self::minutesSinceMidnight($time);

        if ($minutes === null) {
            return null;
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * `HH:MM` or `HH:MM:SS` to minutes past midnight, or null if it is neither.
     *
     * Seconds are matched but dropped: the column stores them, no part of this
     * feature lets a user set them, and keeping them would only add a sub-minute
     * edge to every comparison above.
     */
    private static function minutesSinceMidnight(?string $time): ?int
    {
        if ($time === null || !preg_match('/^(\d{1,2}):([0-5]\d)(?::[0-5]\d)?$/', trim($time), $parts)) {
            return null;
        }

        $hour = (int) $parts[1];

        if ($hour > 23) {
            return null;
        }

        return $hour * 60 + (int) $parts[2];
    }
}
