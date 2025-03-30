<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeeklyCyclePlan extends Model
{
    protected $fillable = [
        'user_id',
        'start_date',
        'weeks',
        'days_pattern',
    ];

    // Cast days_pattern to array automatically
    protected $casts = [
        'days_pattern' => 'array',
        'start_date'   => 'date',
    ];
}