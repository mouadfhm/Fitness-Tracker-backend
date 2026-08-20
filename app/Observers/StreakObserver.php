<?php

namespace App\Observers;

use App\Models\User;
use App\Models\UserStreak;
use App\Services\StreakCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps `user_streaks` current as users log meals and workouts.
 *
 * Registered per logging model in AppServiceProvider, for the reason
 * EngagementObserver gives next door: a controller-side update only covers the
 * endpoints that exist today, and the endpoint someone adds next year without
 * knowing about this file would silently break the streaks of everyone who used
 * it. Observing the model covers every write path to that model, including the
 * ones not written yet.
 *
 * Unlike EngagementObserver this cannot be a blind one-statement update. The day
 * being credited is the day it is *where the user is*, so their timezone has to
 * be read, and the new value depends on the old one.
 */
class StreakObserver
{
    public function created(Model $model): void
    {
        $this->credit($model->user_id);
    }

    private function credit($userId): void
    {
        if (!$userId) {
            return;
        }

        try {
            // Only the two columns needed to place this write on a calendar.
            // The whole row would be a wasted read on every meal anyone logs.
            $user = User::select('id', 'timezone')->find($userId);

            if (!$user) {
                return;
            }

            $this->record((int) $user->id, $user->localNow()->format('Y-m-d'));
        } catch (Throwable $e) {
            // Bookkeeping must never fail the write it is riding along with. A
            // lost day costs the user a streak they can rebuild; an exception
            // escaping here costs them the meal they just logged.
            Log::error('Could not update streak', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply one qualifying day to the user's streak row.
     *
     * @param string $localDay `Y-m-d` on the user's own clock.
     */
    private function record(int $userId, string $localDay): void
    {
        DB::transaction(function () use ($userId, $localDay) {
            // Locked because the update is read-then-write, and a user logging
            // a meal and a workout in the same breath — two requests, same
            // second — would otherwise have both read `last_day = yesterday`
            // and both increment, paying out two days for one. The lock makes
            // the second see the first's write and no-op, which is what the
            // rule already says should happen for a day that is already counted.
            $streak = UserStreak::where('user_id', $userId)->lockForUpdate()->first()
                ?? new UserStreak(['user_id' => $userId]);

            $streak->fill(StreakCalendar::advance(
                $streak->current_days,
                $streak->longest_days,
                $streak->last_day,
                $localDay
            ));

            // No isDirty() guard. A same-day log leaves every column identical,
            // so this is a no-op statement rather than a wasted write, and the
            // branch it would skip is also the one holding the lock for the
            // shortest time.
            $streak->save();
        });
    }
}
