<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GymExercise extends Model
{
    protected $table = 'gym_exercises';
    protected $fillable = ['name', 'name_fr', 'description', 'type', 'body_part', 'equipment', 'level'];
    protected $appends = ['display_name'];

    public function getDisplayNameAttribute()
    {
        if (app()->getLocale() === 'fr' && !empty($this->name_fr)) {
            return $this->name_fr;
        }

        return $this->name;
    }
}
