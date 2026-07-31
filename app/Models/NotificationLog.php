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
