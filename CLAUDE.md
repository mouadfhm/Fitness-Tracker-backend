# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A Laravel 12 REST API backend for a fitness tracking mobile app. Uses Sanctum for token-based authentication and Spatie Permission for role-based access control (roles: `user`, `admin`). Push notifications are delivered via Firebase Cloud Messaging (kreait/firebase-php).

## Commands

```bash
# Start full dev environment (server + queue + logs + vite)
composer run dev

# Serve API only
php artisan serve

# Run all tests
php artisan test

# Run a single test file
php artisan test tests/Feature/ExampleTest.php

# Run tests with a specific filter
php artisan test --filter=ExampleTest

# Run migrations
php artisan migrate

# Lint/format with Laravel Pint
./vendor/bin/pint

# Process queued jobs
php artisan queue:listen --tries=1
```

## Architecture

### API Versioning
There are two generations of workout endpoints:
- **v1** (`/api/workouts/...`) — older, simpler workout CRUD
- **v2** (`/api/v2/workouts/...`) — current generation with gym exercises, custom workouts, workout logs, scheduled workouts, weekly cycle plans, and AI recommendations

All routes require `auth:sanctum` except `POST /api/register` and `POST /api/login`. Admin routes additionally require the `role:admin` middleware from Spatie Permission.

### Key Domain Concepts
- **Food / Meal** — users log meals composed of foods; `MealController` exposes a `totalMacros` aggregate endpoint
- **Progress** — time-series body measurements (weight, etc.) stored per user
- **Goals** — user fitness goals (weight_loss, muscle_gain, maintenance) stored on the User model; updated via `GoalController`
- **GymExercise** — seeded exercise library users pick from when building custom workouts
- **CustomWorkout** — user-defined workouts referencing GymExercises (pivot: `custom_workout_exercise`)
- **WorkoutLog** — records of completed workouts with duration and calories_burned
- **ScheduledWorkout** — future-dated workout plans
- **WeeklyCyclePlan** — recurring weekly workout schedule
- **Achievements** — badge system; `AchievementService::checkAndUnlock()` is called after relevant progress events and triggers a Firebase push notification on unlock

### Services
- `App\Services\AchievementService` — checks achievement thresholds and calls `NotificationService` on unlock
- `App\Services\NotificationService` — wraps Firebase messaging; looks up `UserDevices` token for a user and sends a FCM push

### Auth Flow
- Register creates a user, assigns the `user` role, returns a Sanctum plain-text token
- Login validates credentials, checks `is_valid` flag (deactivated accounts return 403), returns a token
- Tokens are passed as `Authorization: Bearer <token>`

### Firebase Integration
Firebase is bound to the container as `'firebase'` (see `app/Providers`). The `NotificationService` resolves it via `app('firebase')`. Requires Firebase credentials configured in `.env`.

### Testing
Tests use the real database (SQLite in-memory is commented out in `phpunit.xml` — check `.env.testing` or update `phpunit.xml` before running tests). Test suites: `Unit` (`tests/Unit/`) and `Feature` (`tests/Feature/`).
