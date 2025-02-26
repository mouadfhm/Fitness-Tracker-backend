<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\FoodController;
use App\Http\Controllers\MealController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\WorkoutController;
use App\Http\Controllers\AdminController;

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile', [ProfileController::class, 'update']);

    // Food search
    Route::group(['prefix' => 'foods'], function () {
        Route::apiResource('/', FoodController::class);
        Route::post('/add', [FoodController::class, 'store']);
        Route::put('/{id}', [FoodController::class, 'update']);
        Route::delete('/{id}', [FoodController::class, 'destroy']);
        Route::get('/search', [FoodController::class, 'index']);
        Route::get('/{id}', [FoodController::class, 'show']);
    });

    // Meal logging & food search
    Route::group(['prefix' => 'meals'], function () {
        Route::apiResource('/', MealController::class);
        Route::post('/add', [MealController::class, 'store']);
        Route::put('/{id}', [MealController::class, 'update']);
        Route::delete('/{id}', [MealController::class, 'destroy']);
        Route::get('/search', [MealController::class, 'index']);
        Route::get('/{id}', [MealController::class, 'show']);
    });

    // Goal & progress tracking
    Route::group(['prefix' => 'goals'], function () {
        Route::post('/add', [GoalController::class, 'store']);
    });

    // Progress tracking
    Route::group(['prefix' => 'progress'], function () {
        Route::post('/add', [ProgressController::class, 'store']);
        Route::get('/', [ProgressController::class, 'index']);
    });

    // Optional: Workout tracking
    Route::group(['prefix' => 'workouts'], function () {
        Route::apiResource('workouts', WorkoutController::class);
        Route::post('/add', [WorkoutController::class, 'store']);
        Route::put('/{id}', [WorkoutController::class, 'update']);
        Route::delete('/{id}', [WorkoutController::class, 'destroy']);
        Route::get('/search', [WorkoutController::class, 'index']);
        Route::get('/{id}', [WorkoutController::class, 'show']);
    });

    // Admin routes
    Route::middleware('role:admin')->group(function () {
        Route::get('admin/dashboard', [AdminController::class, 'dashboard']);
    });
});
