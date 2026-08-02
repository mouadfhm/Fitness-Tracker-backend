<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    // Types. Anything sent has to name itself as one of these so open rates can
    // be compared per kind of notification rather than in aggregate.
    public const TYPE_MEAL_REMINDER    = 'meal_reminder';
    public const TYPE_WORKOUT_REMINDER = 'workout_reminder';
    public const TYPE_ACHIEVEMENT      = 'achievement';
    public const TYPE_WINBACK          = 'winback';

    // Separate from TYPE_WORKOUT_REMINDER, which is the unconditional 18:30
    // nag. This one names a session the user themselves put in the calendar,
    // and the whole reason for splitting the type is that their open rates
    // should differ enormously. If they turn out not to, the premise of the
    // feature is wrong and this table is where that becomes visible.
    public const TYPE_SESSION_REMINDER = 'session_reminder';

    // Outcomes. `skipped` means we never attempted a send (no registered
    // device); `failed` means FCM rejected it.
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'status',
        'error',
        'sent_at',
        'opened_at',
    ];

    protected $casts = [
        'sent_at'   => 'datetime',
        'opened_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
