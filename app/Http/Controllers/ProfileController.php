<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfileController extends Controller
{
    // Display the authenticated user's profile
    public function show(Request $request)
    {
        return response()->json($request->user()->load('roles'));
    }

    // Update profile details
    public function update(Request $request)
    {
        $user = $request->user();
        $validatedData = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'age'           => 'sometimes|integer',
            'weight'        => 'sometimes|numeric',
            'height'        => 'sometimes|numeric',
            'gender'        => 'sometimes|in:male,female,other',
            'activity_level'=> 'sometimes|string',
            'fitness_goal'  => 'sometimes|in:weight_loss,muscle_gain,maintenance',
        ]);

        $user->update($validatedData);
        $user->syncRoles([]); // Remove existing roles first (optional)
        $user->assignRole('admin');
        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $user,
        ]);
    }
}
