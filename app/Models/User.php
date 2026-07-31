<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

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
    // EngagementObserver. In $fillable a user could post it to the profile
    // endpoint and hold themselves permanently at day 0 — or at day 999.
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_engaged_at'   => 'datetime',
    ];

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
