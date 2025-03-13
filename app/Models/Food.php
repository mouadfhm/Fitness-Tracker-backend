<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Food extends Model
{
    use HasFactory;
    protected $table = 'foods';
    protected $fillable = [
        'name', 
        'calories', 
        'protein', 
        'carbs', 
        'fats',
        'is_favorite',
    ];

    // A food can belong to many meals.
    public function meals()
    {
        return $this->belongsToMany(Meal::class)->withPivot('quantity');
    }
    public function favoriteFoods()
    {
        return $this->hasMany(FavoriteFood::class);
    }
}
