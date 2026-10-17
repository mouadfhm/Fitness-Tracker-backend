<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exercise extends Model
{
    // 
    protected $table = 'exercises';
    protected $fillable = [
        'name',
        'name_en',
        'name_fr',
        'category_fr',
        'description',
        'category',
        'duration',
        'defaultReps',
        'caloriesPerKg',
    ];
    protected $appends = ['display_name', 'display_category'];

    // The activity in plain words, in the request's language.
    public function getDisplayNameAttribute()
    {
        if (app()->getLocale() === 'fr' && !empty($this->name_fr)) {
            return $this->name_fr;
        }

        return $this->name_en ?? $this->name;
    }

    // The group the app filters activities by, in the request's language.
    public function getDisplayCategoryAttribute()
    {
        if (app()->getLocale() === 'fr' && !empty($this->category_fr)) {
            return $this->category_fr;
        }

        return $this->category;
    }
}
