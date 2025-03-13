<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FavoriteFood extends Model
{
    //
    protected $table = 'favorite_foods';

    protected $fillable = [
        'is_favorite',
        'food_id',
        'user_id',
    ];

    public function food()
    {
        return $this->belongsTo(Food::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
