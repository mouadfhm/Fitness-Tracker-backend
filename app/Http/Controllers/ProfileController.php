<?php

namespace App\Http\Controllers;

use App\Models\Progress;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;


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
        try {
            $validatedData = $request->validate([
                'name'          => 'sometimes|string|max:255',
                'age'           => 'sometimes|integer',
                'weight'        => 'sometimes|numeric',
                'height'        => 'sometimes|numeric',
                'gender'        => 'sometimes|in:male,female,other',
                'activity_level' => 'sometimes|string',
                'fitness_goal'  => 'sometimes|in:weight_loss,muscle_gain,maintenance',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
        try {
            $progress = Progress::create([
                'user_id' => $user->id,
                'date'    => date('Y-m-d'),
                'weight'  => $validatedData['weight'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        try {
            $user->update($validatedData);
            return response()->json([
                'message' => 'Profile updated successfully.',
                'user'    => $user,
                'progress' => $progress
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
