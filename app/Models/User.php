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
     * How much above resting each activity level is assumed to burn: the
     * standard Mifflin-St Jeor factors, matching the descriptions the app shows
     * for each level ("Exercise 3–5 days a week" is moderate, and so on).
     * Before, only three of the five levels the app offers were listed, so
     * "light" and "very_active" silently fell back to sedentary.
     */
    public const ACTIVITY_MULTIPLIERS = [
        'sedentary'   => 1.2,
        'light'       => 1.375,
        'moderate'    => 1.55,
        'active'      => 1.725,
        'very_active' => 1.9,
    ];

    private const DEFAULT_ACTIVITY_MULTIPLIER = 1.2;

    /**
     * A weight-loss target never goes below this, whatever the arithmetic says:
     * the usual floor for eating without supervision. Keyed by gender, with the
     * midpoint for anyone who has not said.
     */
    private const MINIMUM_CALORIES = [
        'male'   => 1500,
        'female' => 1200,
    ];

    private const DEFAULT_MINIMUM_CALORIES = 1350;

    protected $fillable = [
        'name', 
        'email', 
        'password', 
        'age', 
        'birth_date',
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
    // EngagementObserver, timezone only by NotificationController, which
    // validates it against the tz database first, and the two preferred_*_hour
    // columns only by RecomputeHabitualSendHours. In $fillable a user could
    // post any of them to the profile endpoint — holding themselves permanently
    // at day 0, storing junk that throws inside Carbon on the next scheduler
    // run and takes the whole reminder batch down with it, or setting their own
    // send hour through a field that was never meant to be one. (Letting a user
    // choose their times is spec 08's job, through the preferences endpoint,
    // and it is out of scope here.)
    //
    // The hours are cast because the driver hands them back as strings and
    // HabitualHour::isSane() compares them as ints. '8' >= 8 happens to be true
    // in PHP, so the bug this prevents is not a wrong answer here but a string
    // hour travelling on into ReminderWindow's arithmetic — the kind of thing
    // that works until it does not. Nullable throughout: null means "nothing
    // learned about this user, use the default".
    protected $casts = [
        'email_verified_at'      => 'datetime',
        'birth_date'             => 'date:Y-m-d',
        'last_engaged_at'        => 'datetime',
        'preferred_meal_hour'    => 'integer',
        'preferred_workout_hour' => 'integer',
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
     * Age in whole years: from birth_date when the user has set one, so it
     * goes up on their birthday, otherwise the age they typed in (accounts
     * from before the app asked for a birthday, or older app versions).
     */
    public function getAgeAttribute($value): ?int
    {
        if ($this->birth_date !== null) {
            return $this->birth_date->age;
        }

        return $value === null ? null : (int) $value;
    }

    /**
     * Makes age and birth_date agree in a register or profile payload. A
     * birthday also refreshes the stored age, the fallback if it is ever
     * cleared. An age on its own comes from an app version that predates the
     * birthday field: when it disagrees with the birthday on file, the user
     * has just corrected it there, so that birthday no longer holds.
     */
    public static function syncAgeWithBirthDate(array $data, ?self $user = null): array
    {
        if (!empty($data['birth_date'])) {
            $data['age'] = Carbon::parse($data['birth_date'])->age;
        } elseif (isset($data['age']) && !array_key_exists('birth_date', $data)
            && $user?->birth_date !== null && $user->age !== (int) $data['age']) {
            $data['birth_date'] = null;
        }

        return $data;
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

        // The formula has a constant for men (+5) and one for women (-161).
        // "other" or unset used to get the women's one; the midpoint is the
        // least wrong guess for someone we know nothing about.
        $sexConstant = match ($this->gender) {
            'male'   => 5,
            'female' => -161,
            default  => -78,
        };

        return 10 * $this->weight
            + 6.25 * $this->height
            - 5 * $this->age
            + $sexConstant;
    }

    /**
     * What the user burns on a typical day: resting burn times their activity
     * level. Null on an incomplete profile.
     */
    public function totalDailyEnergyExpenditure(): ?float
    {
        $bmr = $this->basalMetabolicRate();

        if ($bmr === null) {
            return null;
        }

        // Lower-cased because app versions before this fix sent "Moderate".
        $level = strtolower((string) $this->activity_level);

        return $bmr * (self::ACTIVITY_MULTIPLIERS[$level] ?? self::DEFAULT_ACTIVITY_MULTIPLIER);
    }

    /**
     * Calories the user is aiming to eat each day, or null on an incomplete
     * profile.
     *
     * The same every day. Logged workouts used to be added on top, but the
     * activity level already describes the user's training ("Exercise 3–5 days
     * a week"), so that counted exercise twice and could wipe out a cut's whole
     * deficit.
     */
    public function dailyCalorieTarget(): ?float
    {
        $tdee = $this->totalDailyEnergyExpenditure();

        if ($tdee === null) {
            return null;
        }

        $target = $tdee;

        // 500 kcal (about 0.5 kg a week) and 300 kcal are right for an average
        // adult, but a flat 500 is a quarter of a small person's whole burn. So
        // the change is also held to 20% (loss) and 10% (gain) of their burn,
        // and a weight-loss target never drops below the floor.
        return match ($this->fitness_goal) {
            'weight_loss' => max(
                $target - min(500, 0.20 * $target),
                min($target, self::MINIMUM_CALORIES[$this->gender] ?? self::DEFAULT_MINIMUM_CALORIES)
            ),
            'muscle_gain' => $target + min(300, 0.10 * $target),
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

    /**
     * How many days running this user has logged something.
     *
     * Null until their first meal or workout, which is a real state rather than
     * an oversight: "never logged anything" and "logged, then lapsed" are
     * different, and only the second is a broken streak. Anything reading the
     * count should go through UserStreak::currentDaysOn(), which answers it as
     * of a given day — the stored number is only true as of the day it was
     * written.
     */
    public function streak()
    {
        return $this->hasOne(UserStreak::class);
    }
}
