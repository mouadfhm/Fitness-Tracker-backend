<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Workout;

class ScheduledWorkout extends Model
{
    /**
     * What a session is called when its workout cannot be named.
     *
     * `workout_id` is an unconstrained nullable column, so it can be null or
     * point at a row that has since been deleted. The reminder still goes out in
     * that case — the user did schedule something — it just cannot say what.
     */
    public const UNNAMED = 'Your workout';

    protected $fillable = ['user_id', 'workout_id', 'scheduled_at', 'reminded_at'];

    /**
     * Kept out of the JSON so the three endpoints in ScheduledWorkoutController
     * return exactly what they returned before this column existed. It is
     * bookkeeping for the reminder command and means nothing to the app.
     */
    protected $hidden = ['reminded_at'];

    /**
     * Deliberately no cast on `scheduled_at`.
     *
     * That looks like an oversight and is not. The column holds the user's local
     * wall clock, not UTC — `workout_service.dart` posts a naive
     * `DateTime.toIso8601String()` with the offset stripped, and the calendar
     * screen reads it back the same way. A 'datetime' cast would assert the
     * value is UTC, which is false, and would also change the serialised form in
     * every API response from `2026-08-02 18:00:00` to an ISO-8601 string with a
     * Z on the end, which the Flutter client does not parse. Anything comparing
     * this against a clock has to parse it in the user's own timezone; see
     * SendWorkoutSessionReminder.
     */
    protected $casts = [
        'reminded_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function workout()
    {
        return $this->belongsTo(CustomWorkout::class);
    }

    /**
     * The name to put in a notification.
     */
    public function displayName(): string
    {
        $name = trim((string) ($this->workout->name ?? ''));

        return $name === '' ? self::UNNAMED : $name;
    }
}