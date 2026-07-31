<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Decides whether a user should be reminded, based on how long it has been
 * since they last used the app.
 *
 * Before this, both daily commands pinged every user who had not logged, every
 * day, forever. That is the pattern that produces uninstalls and one-star
 * reviews: the less someone uses the app, the more it shouts at them.
 */
class EngagementService
{
    /**
     * Days inactive => minimum days between reminders of a single type.
     *
     * Read as "up to and including N days inactive, wait this long". Past the
     * last band the answer is stop: a user gone for two months is not going to
     * be won back by a fourteenth identical reminder.
     */
    private const BACKOFF = [
        7  => 1,
        21 => 3,
        60 => 7,
    ];

    public const DORMANT_AFTER_DAYS = 60;

    /**
     * Minimum gap in days, or null when the user is dormant and gets nothing.
     */
    public static function minimumGapDays(int $daysInactive): ?int
    {
        foreach (self::BACKOFF as $upTo => $gap) {
            if ($daysInactive <= $upTo) {
                return $gap;
            }
        }

        return null;
    }

    /**
     * A user who has never logged anything is day 0, not dormant. That is a new
     * account being onboarded, and cutting their reminders on the first morning
     * is exactly backwards.
     */
    public static function daysInactive(?CarbonInterface $lastEngagedAt, ?CarbonInterface $now = null): int
    {
        if ($lastEngagedAt === null) {
            return 0;
        }

        $now ??= Carbon::now();

        // Whole calendar days. A stamp from 23:00 yesterday is one day old at
        // 10:00 this morning, which is how a person would count it.
        $days = $lastEngagedAt->copy()->startOfDay()->diffInDays($now->copy()->startOfDay());

        // Guards against clock skew or a future-dated backfill row producing a
        // negative count, which would fall through every band.
        return max(0, (int) $days);
    }

    /**
     * The whole decision, with no database in it, so the boundaries can be
     * tested directly.
     *
     * $daysSinceLastSent is null when this type has never actually reached the
     * user — the first reminder always goes out.
     */
    public static function shouldSend(int $daysInactive, ?int $daysSinceLastSent): bool
    {
        $gap = self::minimumGapDays($daysInactive);

        if ($gap === null) {
            return false;
        }

        if ($daysSinceLastSent === null) {
            return true;
        }

        return $daysSinceLastSent >= $gap;
    }

    /**
     * @param string $type One of the NotificationLog::TYPE_* constants.
     */
    public function dueForReminder(int $userId, string $type): bool
    {
        $user = User::find($userId);

        if (!$user) {
            return false;
        }

        return self::shouldSend(
            self::daysInactive($user->last_engaged_at),
            $this->daysSinceLastSent($userId, $type)
        );
    }

    /**
     * Only rows we actually delivered count.
     *
     * Counting `skipped` rows here would be a ratchet with no way out: today's
     * skip becomes tomorrow's "last sent", which pushes the next window out
     * again, and the user never hears from us regardless of the table above.
     * `failed` is excluded for the plainer reason that nothing arrived on the
     * device, so it cannot have annoyed anyone.
     */
    private function daysSinceLastSent(int $userId, string $type): ?int
    {
        $lastSentAt = NotificationLog::where('user_id', $userId)
            ->where('type', $type)
            ->where('status', NotificationLog::STATUS_SENT)
            ->max('sent_at');

        if (!$lastSentAt) {
            return null;
        }

        // Compared on day boundaries rather than as an elapsed interval. The
        // scheduler fires within a minute of the target time, so a run a few
        // seconds earlier than yesterday's would fail an exact ">= 24h" test
        // and silently drop the reminder to every other day.
        return (int) Carbon::parse($lastSentAt)->startOfDay()->diffInDays(Carbon::now()->startOfDay());
    }
}
