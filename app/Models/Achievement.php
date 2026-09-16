<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Achievement extends Model
{
    protected $fillable = ['name', 'name_fr', 'description', 'description_fr', 'target', 'type', 'icon'];
    protected $appends = ['display_name', 'display_description'];

    public function getDisplayNameAttribute()
    {
        if (app()->getLocale() === 'fr' && !empty($this->name_fr)) {
            return $this->name_fr;
        }

        return $this->name;
    }

    public function getDisplayDescriptionAttribute()
    {
        if (app()->getLocale() === 'fr' && !empty($this->description_fr)) {
            return $this->description_fr;
        }

        return $this->description;
    }
}
