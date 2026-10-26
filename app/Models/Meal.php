<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Meal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 
        'date',
        'meal_time'
    ];

    // Each meal is logged by a user.
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // A meal includes many foods. The pivot id names one entry, so the app can
    // remove a single food from a meal that holds several (the same food may
    // be in it twice).
    public function foods()
    {
        return $this->belongsToMany(Food::class)->withPivot('id', 'quantity');
    }
}
