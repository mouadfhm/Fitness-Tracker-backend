<?php

namespace App\Models;

use App\Services\QuietHours;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One user's notification settings: which kinds they want, and when they would
 * rather not be disturbed.
 *
 * Rows are created lazily — on the first save from the settings screen — so the
 * table starts empty and the users who already exist need no backfill. That
 * makes "no row" a state the sending path has to handle, and it handles it by
 * reading it as DEFAULTS below.
 */
class NotificationPreference extends Model
{
    /**
     * What a user gets before they have ever opened the settings screen.
     *
     * Also the column defaults in the create migration, and the two have to
     * agree. If "no row" meant something different from "a row that has just
     * been created", then opening the settings screen and saving nothing would
     * change what a user receives — a behaviour change with no user decision
     * behind it, and one nobody would think to look for. Both paths read this
     * constant so they cannot drift apart.
     */
    public const DEFAULTS = [
        'meal_reminders'    => true,
        'workout_reminders' => true,
        'achievements'      => true,
        'winback'           => true,
        'streaks'           => true,
        'quiet_from'        => '22:00',
        'quiet_to'          => '08:00',
    ];

    /**
     * NotificationLog type => the column that governs it.
     *
     * The toggles are named for what a user recognises, so they are plural where
     * the log types are singular. Everything sent names one of these types,
     * which is what lets a single check in NotificationService cover every
     * sender rather than each one remembering to ask.
     */
    public const TYPE_COLUMNS = [
        NotificationLog::TYPE_MEAL_REMINDER    => 'meal_reminders',
        NotificationLog::TYPE_WORKOUT_REMINDER => 'workout_reminders',
        NotificationLog::TYPE_ACHIEVEMENT      => 'achievements',
        NotificationLog::TYPE_WINBACK          => 'winback',

        // Scheduled-session reminders share the workout toggle rather than
        // getting one of their own. Two arguments settled it. The first is what
        // the switch means to the person reading it: someone who turned off
        // "Workout reminders" has said they do not want the phone raising
        // workouts with them, and a second workout notification that keeps
        // arriving because it is technically a different type is how an app
        // gets muted at the OS level — the failure 00-index.md warns about, and
        // not one that is undone by a later apology. The second is that
        // allowsType() lets an unmapped type through unconditionally, so
        // *omitting* this line would not defer the decision, it would make the
        // notification impossible to switch off.
        //
        // The cost is that the two cannot be tuned apart. If their open rates
        // turn out as different as this feature assumes, that is worth
        // revisiting with a real column, a migration, and a row on the settings
        // screen.
        NotificationLog::TYPE_SESSION_REMINDER => 'workout_reminders',

        // A toggle of its own, unlike the session reminder above, and for the
        // reason that decided that one the other way: what the switch means to
        // the person reading it. Someone who turned off "Meal reminders" has
        // said they do not want to be told to eat; they have not said they want
        // to lose a streak silently. The two notifications make different
        // offers, so they get different switches.
        NotificationLog::TYPE_STREAK_AT_RISK   => 'streaks',
    ];

    protected $fillable = [
        'user_id',
        'meal_reminders',
        'workout_reminders',
        'achievements',
        'winback',
        'streaks',
        'quiet_from',
        'quiet_to',
    ];

    protected $casts = [
        'meal_reminders'    => 'boolean',
        'workout_reminders' => 'boolean',
        'achievements'      => 'boolean',
        'winback'           => 'boolean',
        'streaks'           => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * This user's preferences, or an unsaved instance carrying the defaults.
     *
     * Deliberately a model rather than null-or-model: every caller wants to ask
     * the same questions of it either way, and a nullable return would put a
     * "?? default" at each of those call sites, one of which would eventually
     * get it wrong.
     */
    public static function forUser(int $userId): self
    {
        return static::firstOrNew(['user_id' => $userId], self::DEFAULTS);
    }

    /**
     * Has the user left this kind of notification switched on?
     *
     * An unrecognised type is allowed through. There is no toggle for it, so the
     * user cannot have opted out of it, and dropping it silently would make a
     * newly added notification type look broken rather than unconfigured. The
     * quiet window still applies to it, because that one is about *when* rather
     * than about what.
     */
    public function allowsType(string $type): bool
    {
        $column = self::TYPE_COLUMNS[$type] ?? null;

        if ($column === null) {
            return true;
        }

        return (bool) $this->{$column};
    }

    /**
     * @param CarbonInterface $localNow Already in the user's own timezone.
     */
    public function isQuietAt(CarbonInterface $localNow): bool
    {
        return QuietHours::covers($localNow, $this->quiet_from, $this->quiet_to);
    }

    /**
     * The shape the app reads and writes. Kept here rather than in the
     * controller so that the GET response and the body echoed back from the PUT
     * cannot end up describing the same row differently.
     */
    public function toApiArray(): array
    {
        return [
            'meal_reminders'    => (bool) $this->meal_reminders,
            'workout_reminders' => (bool) $this->workout_reminders,
            'achievements'      => (bool) $this->achievements,
            'winback'           => (bool) $this->winback,
            'streaks'           => (bool) $this->streaks,
            'quiet_from'        => QuietHours::format($this->quiet_from),
            'quiet_to'          => QuietHours::format($this->quiet_to),
        ];
    }
}
