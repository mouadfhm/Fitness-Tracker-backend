<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
     * Takes the model rather than an id because every caller already holds one,
     * from the chunk it is iterating. Looking it up again here was a second
     * SELECT of a row the command had in hand, per user, per run.
     *
     * @param string $type One of the NotificationLog::TYPE_* constants.
     */
    public function dueForReminder(User $user, string $type): bool
    {
        return self::shouldSend(
            self::daysInactive($user->last_engaged_at),
            self::daysSince($this->lastSentAt($user->id, $type))
        );
    }

    /**
     * The same decision for a whole chunk, in one query instead of one each.
     *
     * This is the batching that matters. The per-user form issues a `max`
     * against notification_logs for every candidate; at ten thousand users on a
     * half-hourly scheduler that is the dominant cost of the run, and the
     * answer for all of them fits in a single grouped read.
     *
     * @param  Collection<int,User> $users
     * @param  string $type One of the NotificationLog::TYPE_* constants.
     * @return array<int,bool> user id => may we send
     */
    public function dueForReminderMany(Collection $users, string $type): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $lastSent = $this->lastSentAtMany($users->pluck('id')->all(), $type);

        $decisions = [];

        foreach ($users as $user) {
            // Carbon::now() is still read per user, inside daysSince, as it was
            // when this ran one user at a time. Hoisting it out of the loop
            // would be a behaviour change hiding inside a performance one.
            $decisions[$user->id] = self::shouldSend(
                self::daysInactive($user->last_engaged_at),
                self::daysSince($lastSent[$user->id] ?? null)
            );
        }

        return $decisions;
    }

    /**
     * When this type last actually reached the user, or null if it never has.
     *
     * Only rows we actually delivered count.
     *
     * Counting `skipped` rows here would be a ratchet with no way out: today's
     * skip becomes tomorrow's "last sent", which pushes the next window out
     * again, and the user never hears from us regardless of the table above.
     * `failed` is excluded for the plainer reason that nothing arrived on the
     * device, so it cannot have annoyed anyone.
     */
    private function lastSentAt(int $userId, string $type): ?string
    {
        return NotificationLog::where('user_id', $userId)
            ->where('type', $type)
            ->where('status', NotificationLog::STATUS_SENT)
            ->max('sent_at');
    }

    /**
     * lastSentAt for many users in one grouped read.
     *
     * Users with no delivered row of this type are simply absent from the
     * result, and the caller reads that absence as null — the same "never sent"
     * the single-user form returns for them.
     *
     * Served by the existing (user_id, type, sent_at) index as a prefix.
     *
     * @param  int[] $userIds
     * @return array<int,string> user id => sent_at
     */
    private function lastSentAtMany(array $userIds, string $type): array
    {
        return NotificationLog::query()
            ->whereIn('user_id', $userIds)
            ->where('type', $type)
            ->where('status', NotificationLog::STATUS_SENT)
            ->groupBy('user_id')
            ->selectRaw('user_id, max(sent_at) as last_sent_at')
            ->get()
            ->pluck('last_sent_at', 'user_id')
            ->all();
    }

    /**
     * Whole days between a `sent_at` and now, or null for "never sent".
     *
     * Shared by both paths above rather than spelled out in each. Compared on
     * day boundaries rather than as an elapsed interval: the scheduler fires
     * within a minute of the target time, so a run a few seconds earlier than
     * yesterday's would fail an exact ">= 24h" test and silently drop the
     * reminder to every other day. That is a subtle enough trap to be worth
     * having exactly one copy of.
     */
    private static function daysSince(?string $lastSentAt): ?int
    {
        if (!$lastSentAt) {
            return null;
        }

        return (int) Carbon::parse($lastSentAt)->startOfDay()->diffInDays(Carbon::now()->startOfDay());
    }
}
