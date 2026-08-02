<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * Where users are assumed to be until their device says otherwise.
     *
     * Every reminder used to run ->timezone('Africa/Casablanca'), so this is not
     * a new opinion — it is the old one, now confined to users whose device has
     * not reported anything yet.
     */
    public const DEFAULT_TIMEZONE = 'Africa/Casablanca';

    /**
     * How much above resting each activity level is assumed to burn.
     *
     * Lifted verbatim out of GoalController::calculateMacros, including the 1.2
     * fallback for a null or unrecognised level.
     */
    public const ACTIVITY_MULTIPLIERS = [
        'sedentary' => 1.2,
        'moderate'  => 1.4,
        'active'    => 1.6,
    ];

    private const DEFAULT_ACTIVITY_MULTIPLIER = 1.2;

    protected $fillable = [
        'name', 
        'email', 
        'password', 
        'age', 
        'weight', 
        'height', 
        'gender', 
        'activity_level', 
        'fitness_goal'
    ];

    protected $hidden = [
        'password', 
        'remember_token',
    ];

    // Deliberately absent from $fillable: last_engaged_at is written only by
    // EngagementObserver, and timezone only by NotificationController, which
    // validates it against the tz database first. In $fillable a user could
    // post either to the profile endpoint — holding themselves permanently at
    // day 0, or storing junk that throws inside Carbon on the next scheduler
    // run and takes the whole reminder batch down with it.
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_engaged_at'   => 'datetime',
    ];

    /**
     * The user's IANA timezone, or the fallback when they have never reported
     * one. Also guards against a value that was valid when it was stored but
     * has since been dropped from the tz database, which is rare but does
     * happen and would otherwise throw.
     */
    public function timezoneOrDefault(): string
    {
        $timezone = $this->timezone;

        if ($timezone === null || !in_array($timezone, timezone_identifiers_list(), true)) {
            return self::DEFAULT_TIMEZONE;
        }

        return $timezone;
    }

    /**
     * Now, as this user's wall clock reads it.
     *
     * The app runs on UTC (config/app.php), so anything deciding "is it 10am
     * for them" or "what day is it where they are" has to come through here.
     */
    public function localNow(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())->copy()->setTimezone($this->timezoneOrDefault());
    }

    /**
     * Mifflin-St Jeor resting burn, or null when the profile is too thin to
     * compute one.
     *
     * Moved here from GoalController so that the notification copy and the goals
     * screen cannot come to disagree. A reminder saying "you are 480 kcal under
     * your goal" against a screen showing a different goal is worse than no
     * personalization at all — it makes the number look invented, which is the
     * one thing this feature cannot afford.
     */
    public function basalMetabolicRate(): ?float
    {
        if ($this->weight === null || $this->height === null || $this->age === null) {
            return null;
        }

        return 10 * $this->weight
            + 6.25 * $this->height
            - 5 * $this->age
            + ($this->gender === 'male' ? 5 : -161);
    }

    /**
     * Calories the user is aiming to eat today, or null on an incomplete profile.
     *
     * @param float $caloriesBurnedToday Today's logged workout burn, which the
     *        target moves with — train harder, eat more. The caller supplies it
     *        because the two callers source it differently: the goals endpoint
     *        queries one user, the reminder commands have already fetched the
     *        burn for everybody in one grouped query.
     */
    public function dailyCalorieTarget(float $caloriesBurnedToday = 0.0): ?float
    {
        $bmr = $this->basalMetabolicRate();

        if ($bmr === null) {
            return null;
        }

        $multiplier = self::ACTIVITY_MULTIPLIERS[$this->activity_level] ?? self::DEFAULT_ACTIVITY_MULTIPLIER;

        $target = ($bmr * $multiplier) + $caloriesBurnedToday;

        return match ($this->fitness_goal) {
            'weight_loss' => $target - 500,
            'muscle_gain' => $target + 300,
            default       => $target,
        };
    }

    // A user can have many meals.
    public function meals()
    {
        return $this->hasMany(Meal::class);
    }

    // A user can have many progress logs.
    public function progresses()
    {
        return $this->hasMany(Progress::class);
    }

    // A user can have many workouts.
    public function workouts()
    {
        return $this->hasMany(Workout::class);
    }
    public function foods()
    {
        return $this->hasMany(Food::class, 'added_by');
    }

    /**
     * Notification settings, if this user has ever saved any.
     *
     * Null until then, which is a real state rather than an oversight — see
     * NotificationPreference. Anything that needs the settings regardless should
     * call NotificationPreference::forUser(), which fills in the defaults.
     */
    public function notificationPreference()
    {
        return $this->hasOne(NotificationPreference::class);
    }
}
