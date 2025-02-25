<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Progress extends Model
{
    use HasFactory;

    protected $table = 'progresses';

    protected $fillable = [
        'user_id', 
        'date', 
        'weight'
    ];

    // If you don't want to use Laravel's created_at and updated_at columns.
    public $timestamps = false;

    // Each progress entry belongs to a user.
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
