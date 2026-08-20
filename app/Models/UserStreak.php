<?php

namespace App\Models;

use App\Services\StreakCalendar;
use Illuminate\Database\Eloquent\Model;

/**
 * One user's run of consecutive days on which they logged a meal or a workout.
 *
 * One row per user, created on their first qualifying log and updated in place
 * thereafter — see App\Observers\StreakObserver. The arithmetic is not here; it
 * is in StreakCalendar, which has no database in it and can therefore be tested
 * against the acceptance criteria directly.
 */
class UserStreak extends Model
{
    protected $fillable = [
        'user_id',
        'current_days',
        'longest_days',
        'last_day',
    ];

    /**
     * `last_day` is deliberately *not* cast to a date.
     *
     * Everything that reads it compares it against a local `Y-m-d` string, and a
     * cast would hand back a Carbon in the app's timezone (UTC) instead — at
     * which point comparing it to a user's local date silently reintroduces the
     * midnight bug that string dates exist to avoid. The driver already returns
     * `Y-m-d` for a `date` column, which is the shape wanted.
     */
    protected $casts = [
        'current_days' => 'integer',
        'longest_days' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The streak as of the user's today, which is not always what is stored.
     *
     * @param string $today The user's local date, `Y-m-d`.
     */
    public function currentDaysOn(string $today): int
    {
        return StreakCalendar::settled($this->current_days, $this->last_day, $today);
    }

    /**
     * @param string $today The user's local date, `Y-m-d`.
     */
    public function isAtRiskOn(string $today): bool
    {
        return StreakCalendar::isAtRisk($this->current_days, $this->last_day, $today);
    }
}
