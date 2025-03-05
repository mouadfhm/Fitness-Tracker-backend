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

    // A meal includes many foods.
    public function foods()
    {
        return $this->belongsToMany(Food::class)->withPivot('quantity');
    }
}
