<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Workout;

class ScheduledWorkout extends Model
{
    protected $fillable = ['user_id', 'workout_id', 'scheduled_at'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function workout()
    {
        return $this->belongsTo(CustomWorkout::class);
    }
}