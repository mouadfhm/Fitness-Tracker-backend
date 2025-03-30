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
use App\Http\Controllers\AchievementController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\CustomWorkoutController;
use App\Http\Controllers\GymExerciseController;
use App\Http\Controllers\ScheduledWorkoutController;
use App\Http\Controllers\WorkoutLogController;
use App\Http\Controllers\WeeklyCyclePlanController;




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
        Route::post('/favorite/{id}', [FoodController::class, 'addFavorite']);
        Route::post('/remove-favorite/{id}', [FoodController::class, 'removeFavorite']);
        Route::delete('/{id}', [FoodController::class, 'destroy']);
        Route::get('/search', [FoodController::class, 'index']);
        Route::get('/{id}', [FoodController::class, 'show']);
    });

    // Meal logging & food search
    Route::group(['prefix' => 'meals'], function () {
        Route::apiResource('/', MealController::class);
        Route::get('/macros', [MealController::class, 'totalMacros']);
        Route::post('/add', [MealController::class, 'store']);
        Route::put('/{id}', [MealController::class, 'update']);
        Route::get('/search', [MealController::class, 'index']);
        Route::get('/{id}', [MealController::class, 'show']);
        Route::delete('/{id}', [MealController::class, 'destroy']);
    });

    // Goal & progress tracking
    Route::group(['prefix' => 'goals'], function () {
        Route::put('/', [GoalController::class, 'update']);
        Route::get('/search', [GoalController::class, 'index']);
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
        Route::get('/exercises', [WorkoutController::class, 'exercises']);
        Route::post('/calories-burned', [WorkoutController::class, 'caloriesBurned']);
        Route::get('/{id}', [WorkoutController::class, 'show']);
    });
    // Optional: Workout tracking
    Route::group(['prefix' => 'v2/workouts'], function () {
        //gym exercises
        Route::get('/exercises', [GymExerciseController::class, 'index']);
        Route::get('/exercises/search', [GymExerciseController::class, 'search']);
        // Workouts
        Route::get('/workouts', [WorkoutController::class, 'index']);
        Route::get('/workouts/{id}', [WorkoutController::class, 'show']);

        // Custom workouts
        Route::get('/custom-workouts', [CustomWorkoutController::class, 'index']);
        Route::get('/custom-workouts/{id}', [CustomWorkoutController::class, 'show']);
        Route::post('/custom-workouts', [CustomWorkoutController::class, 'store']);
        Route::put('/custom-workouts/{id}', [CustomWorkoutController::class, 'update']);

        // Logging workouts
        Route::get('/workout-logs', [WorkoutLogController::class, 'index']);
        Route::post('/workout-logs', [WorkoutLogController::class, 'store']);

        // Scheduling workouts
        Route::get('/scheduled-workouts', [ScheduledWorkoutController::class, 'index']);
        Route::post('/scheduled-workouts', [ScheduledWorkoutController::class, 'store']);

        // AI-based recommendations
        Route::get('/recommendations', [RecommendationController::class, 'index']);
        // weekly workouts
        Route::get('/weekly-cycle-plans', [WeeklyCyclePlanController::class, 'index']);
        Route::post('/weekly-cycle-plans', [WeeklyCyclePlanController::class, 'store']);
        Route::put('/weekly-cycle-plans/{id}', [WeeklyCyclePlanController::class, 'update']);
    });

    Route::get('/user/achievements', [AchievementController::class, 'getUserAchievements'])->middleware('auth:sanctum');
    Route::get('/achievements', [AchievementController::class, 'getAchievements']);

    Route::post('/save-device-token', [NotificationController::class, 'saveDeviceToken'])->middleware('auth:sanctum');

    // Admin routes
    Route::middleware('role:admin')->group(function () {
        Route::get('admin/dashboard', [AdminController::class, 'dashboard']);
    });
});
