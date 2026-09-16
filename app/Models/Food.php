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
        'name_en',
        'name_fr',
        'source_name',
        'calories',
        'protein',
        'carbs',
        'fats',
        'is_favorite',
        'added_by',
    ];

    protected $appends = ['display_name'];

    // Locale-aware display name; falls back to the base 'name' for
    // user-added foods that don't have translations.
    public function getDisplayNameAttribute()
    {
        if (app()->getLocale() === 'fr' && !empty($this->name_fr)) {
            return $this->name_fr;
        }

        return $this->name_en ?? $this->name;
    }

    // A food can belong to many meals.
    public function meals()
    {
        return $this->belongsToMany(Meal::class)->withPivot('quantity');
    }
    public function favoriteFoods()
    {
        return $this->hasMany(FavoriteFood::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
