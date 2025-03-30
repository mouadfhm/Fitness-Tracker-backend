<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomWorkout extends Model
{
    protected $fillable = ['user_id', 'name', 'description'];

    public function gym_exercises()
    {
        return $this->belongsToMany(GymExercise::class, 'custom_workout_exercise')
            ->withPivot('sets', 'reps', 'duration', 'rest')
            ->withTimestamps();
    }
}
