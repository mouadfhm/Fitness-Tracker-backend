<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GymExercise extends Model
{
    protected $table = 'gym_exercises';
    protected $fillable = ['name', 'description', 'type', 'body_part', 'equipment', 'level'];
}
