<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exercise extends Model
{
    // 
    protected $table = 'exercises';
    protected $fillable = [
        'name',
        'name_fr',
        'description',
        'category',
        'duration',
        'defaultReps',
        'caloriesPerKg',
    ];
    protected $appends = ['display_name'];

    public function getDisplayNameAttribute()
    {
        if (app()->getLocale() === 'fr' && !empty($this->name_fr)) {
            return $this->name_fr;
        }

        return $this->name;
    }
}
