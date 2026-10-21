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
        // App versions before the calorie fix sent "Moderate".
        if (is_string($request->input('activity_level'))) {
            $request->merge(['activity_level' => strtolower($request->input('activity_level'))]);
        }
        try {
            $validatedData = $request->validate([
                'name'          => 'required|string|max:255',
                'email'         => 'required|email|unique:users',
                'password'      => 'required|string|min:8|confirmed',
                'age'           => 'nullable|integer|between:5,120',
                'birth_date'    => 'nullable|date|before:today|after:1900-01-01',
                'weight'        => 'nullable|numeric|between:20,400',
                'height'        => 'nullable|numeric|between:50,272',
                'gender'        => 'nullable|in:male,female,other',
                'activity_level' => 'nullable|in:' . implode(',', array_keys(User::ACTIVITY_MULTIPLIERS)),
                'fitness_goal'  => 'nullable|in:weight_loss,muscle_gain,maintenance',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
        $validatedData = User::syncAgeWithBirthDate($validatedData);
        try {
            $user = User::create([
                'name'          => $validatedData['name'],
                'email'         => $validatedData['email'],
                'password'      => Hash::make($validatedData['password']),
                'age'           => $validatedData['age'] ?? null,
                'birth_date'    => $validatedData['birth_date'] ?? null,
                'weight'        => $validatedData['weight'] ?? null,
                'height'        => $validatedData['height'] ?? null,
                'gender'        => $validatedData['gender'] ?? null,
                'activity_level' => $validatedData['activity_level'] ?? null,
                'fitness_goal'  => $validatedData['fitness_goal'] ?? null,
            ]);
            // Optionally assign a default role (e.g., Regular User)
            $user->assignRole('user');

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'user'         => $user,
            ], 201);
        } catch (\Exception $e) {
            return $this->serverError($e, 'message', 500);
        }
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
        if (!$user->is_valid) {
            return response()->json(['message' => 'This account has been deactivated. Please contact support.'], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
        ]);
    }
}
