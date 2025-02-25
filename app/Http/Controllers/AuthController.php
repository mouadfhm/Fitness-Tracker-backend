<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // Register a new user and generate an auth token
    public function register(Request $request)
    {
        $validatedData = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'required|email|unique:users',
            'password'      => 'required|string|min:6|confirmed',
            'age'           => 'nullable|integer',
            'weight'        => 'nullable|numeric',
            'height'        => 'nullable|numeric',
            'gender'        => 'nullable|in:male,female,other',
            'activity_level'=> 'nullable|string',
            'fitness_goal'  => 'nullable|in:weight_loss,muscle_gain,maintenance',
        ]);

        $user = User::create([
            'name'          => $validatedData['name'],
            'email'         => $validatedData['email'],
            'password'      => Hash::make($validatedData['password']),
            'age'           => $validatedData['age'] ?? null,
            'weight'        => $validatedData['weight'] ?? null,
            'height'        => $validatedData['height'] ?? null,
            'gender'        => $validatedData['gender'] ?? null,
            'activity_level'=> $validatedData['activity_level'] ?? null,
            'fitness_goal'  => $validatedData['fitness_goal'] ?? null,
        ]);

        // Optionally assign a default role (e.g., Regular User)
        $user->assignRole('admin');

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
        ], 201);
    }

    // Login an existing user and generate an auth token
    public function login(Request $request)
    {
        $request->validate([
           'email'    => 'required|email',
           'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
        ]);
    }
}
