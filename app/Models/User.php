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
}
