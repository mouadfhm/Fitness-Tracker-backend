<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkoutPlan extends Model
{
    protected $fillable = ['name', 'description', 'category'];

    public function exercises()
    {
        return $this->belongsToMany(Exercise::class, 'exercise_workout_plan')
            ->withPivot('sets', 'reps', 'duration', 'rest')
            ->withTimestamps();
    }
}
