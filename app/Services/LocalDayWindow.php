<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The stretch of UTC that is "today" for a group of users who share a timezone
 * offset.
 *
 * `created_at` is stored in UTC, so "did they log anything today" has to be
 * asked as a range, and the range is different for every offset: a user in
 * Auckland and one in Honolulu are on different calendar days at the same
 * instant. Asked per user that is one query each, which is the cost spec 10
 * removes.
 *
 * What makes the batching possible is that everyone in a chunk got there by
 * passing the same ReminderWindow check, so their clocks all read the same
 * wall-clock time and only the offset separates their day boundaries. Users who
 * share an offset therefore share a window exactly, and one `whereIn` answers
 * for all of them.
 *
 * Kept free of Eloquent so the grouping can be reasoned about on its own — the
 * same reason QuietHours and ReminderWindow are separate from their callers.
 */
class LocalDayWindow
{
    /**
     * @param Collection<int,User> $users Everyone sharing this window.
     */
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly Collection $users,
    ) {
    }

    /**
     * @return int[]
     */
    public function ids(): array
    {
        return $this->users->pluck('id')->all();
    }

    /**
     * Group users by the UTC range covering the day it currently is where they
     * are.
     *
     * Typically returns a single window: users who have never reported a
     * timezone all fall back to the same default. The count is bounded by the
     * number of distinct offsets present regardless — a few dozen at the very
     * worst, and one query each rather than one query per user.
     *
     * @param  Collection<int,User> $users
     * @return Collection<string,self>
     */
    public static function group(Collection $users, Carbon $now): Collection
    {
        return $users
            ->groupBy(fn (User $user) => $user->localNow($now)->startOfDay()->utc()->toDateTimeString())
            ->map(function (Collection $members) use ($now) {
                // Any member will do: sharing the key means sharing the offset,
                // and therefore both ends of the window.
                $localNow = $members->first()->localNow($now);

                return new self(
                    $localNow->copy()->startOfDay()->utc(),
                    $localNow->copy()->endOfDay()->utc(),
                    $members
                );
            });
    }
}
