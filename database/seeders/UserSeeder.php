<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('password'),
            'age' => 30,
            'weight' => 75,
            'height' => 175,
            'gender' => 'male',
            'activity_level' => 'moderate',
            'fitness_goal' => 'muscle_gain'
        ]);
    }
}
