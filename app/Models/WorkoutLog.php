<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkoutLog extends Model
{
    protected $fillable = ['user_id', 'workout_id', 'workout_date', 'duration', 'calories_burned', 'details'];
}