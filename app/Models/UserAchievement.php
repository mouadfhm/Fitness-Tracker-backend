<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAchievement extends Model
{
    protected $fillable = ['user_id', 'achievement_id', 'unlocked_at'];

    public function achievement()
    {
        return $this->belongsTo(Achievement::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

