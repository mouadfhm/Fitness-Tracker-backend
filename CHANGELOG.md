# Changelog

All notable changes to the Fitness Tracker API are documented here.

---

## [Unreleased] — 2026-07-31

### Added
- **Notification send & open logging** (spec `01-send-open-logging`). New `notification_logs` table records every notification with its type, title, body, outcome and `sent_at`, plus `opened_at` once the user taps it. Composite index on `(user_id, type, sent_at)`.
- `NotificationService::sendNotification()` takes a required `$type` (`NotificationLog::TYPE_*`) and writes exactly one row per call: `sent`, `failed` (with the FCM error, truncated to the column width) or `skipped` (no registered device). Log writes are themselves guarded, so a missing table cannot break achievement unlocks.
- FCM payloads now carry a `data` block with `log_id` and `type` so the app can report opens.
- `POST /api/notifications/{log}/opened` (`auth:sanctum`) → 204. Returns 404 for logs belonging to another user, so the id is not an enumeration oracle. First tap wins; repeat taps do not move `opened_at`.

- **Per-user timezone** (spec `09-per-user-timezone`). New nullable `users.timezone` column holding an IANA identifier. `POST /api/save-device-token` accepts an optional `timezone` alongside `device_token`; the app already calls it on login and on every token refresh. Values are checked against `timezone_identifiers_list()` and silently dropped if unrecognised — a bad string must not 422 the request, because that would also discard the device token and stop notifications entirely. Not in `$fillable`, so it cannot be mass-assigned via the profile endpoint.
- `User::timezoneOrDefault()` and `User::localNow()`; `App\Services\ReminderWindow` decides whether a user's own clock is inside a reminder's send window.

### Changed
- **Reminder commands batch their per-user queries** (spec `10-reminder-query-batching`). Each candidate used to cost roughly five SELECTs per run — one `exists` for "logged today", a `User::find` and a `max(sent_at)` for the engagement backoff, and a preferences read plus a *second* `User::find` inside `sendBulk`. All five are now per-chunk: one `select distinct user_id` per distinct local day, one grouped `max(sent_at) … group by user_id`, and one `whereIn` each for users and preferences. Measured on a 9-candidate fixture: 46 queries → 15, with the per-user slope falling from 6.0 to 1.0 — the remainder being the `notification_logs` insert, a write left unbatched on purpose because `log_id` has to travel in the payload. Behaviour is unchanged, verified by diffing the full outcome table (who was sent to, who was skipped, and the exact reason text) before and after.
- `EngagementService::dueForReminder()` takes a `User` rather than an id, since every caller already holds the model; `dueForReminderMany()` answers for a whole chunk. `NotificationPreference::forUsers()` and `defaultFor()` added, with `forUser()` routed through the latter so "no row is read as DEFAULTS" lives in one place. A chunk whose preference lookup *throws* still suppresses nothing at all, exactly as the per-user path always did — deliberately distinct from a user who merely has no row and therefore keeps the default quiet hours.
- New `App\Services\LocalDayWindow` groups users by the UTC range covering the day it currently is where they are, so "has this person logged today" costs one query per timezone offset instead of one per user.
- `AchievementService`, `SendDailyReminder` and `SendDailyMealReminder` pass their notification type.
- **Reminders now fire on the user's local clock, not the server's.** Both commands moved from `dailyAt(...)->timezone('Africa/Casablanca')` to `everyThirtyMinutes()->withoutOverlapping()`, selecting the users whose own time reads the target — 10:00 for meals, 18:30 for workouts. Users with no reported timezone fall back to `Africa/Casablanca`, i.e. exactly the old behaviour. The 30-minute cadence and `ReminderWindow::INTERVAL_MINUTES` must stay equal, or users get two reminders a day or none; half- and quarter-hour zones (`Asia/Kolkata`, `Asia/Kathmandu`, `Pacific/Chatham`) are why this is not a plain hour comparison.
- The "already logged today" and weekday-only checks are evaluated against the user's local day rather than the server's UTC day. Both commands now iterate users in chunks instead of pre-filtering with `whereDoesntHave`, since neither question has a single server-wide answer any more.

---

## 2026-05-20

### Fixed
- **NotificationService**: Missing `config/firebase.php` caused `config('firebase.credentials')` to return `null`, breaking Firebase factory initialization on boot. Created config file mapping `FIREBASE_CREDENTIALS` and `FIREBASE_PROJECT_ID` env vars.
- **NotificationService**: Uncaught FCM exceptions (bad credentials, invalid token, network failure) propagated and crashed achievement unlock. Wrapped in try/catch with `Log::error` so notification failures are logged without breaking the caller.
- **FirebaseServiceProvider**: Factory no longer passes `null` to `withServiceAccount` when credentials are missing; it now fails loudly at resolution time with the expected path, instead of silently building an unauthenticated client.
- **AchievementService**: Replaced `new NotificationService()` manual instantiation with constructor injection so the service is resolved from the container and testable.

### Infrastructure
- CORS middleware registered via `bootstrap/app.php` with `prepend`.

---

## 2026-04-19

### Fixed
- **FoodSeeder**: Removed dead-code block that re-processed `ABBREV.csv` using `NDB_No` codes as food names, inserting ~8,790 corrupt numeric-name records into the foods table.
- **FoodController** (`/foods/search`): Added `whereRaw('name REGEXP ?', ['^[^0-9]'])` guard to filter any remaining numeric-only food names from search results.
- Added migration `2026_04_19_204052_delete_numeric_name_foods` to clean up corrupt records from production.

### Added
- Postman collection (`Fitness-Tracker.postman_collection.json`) covering all API endpoints.

---

## 2026-04-16

### Security
- **FoodController**: Fixed SQL injection — `$userId` was concatenated directly into a raw SQL fragment. Replaced with parameterized `selectRaw(..., [$userId])`.
- **FoodController**: Added ownership check on `update` and `destroy` — any authenticated user could previously edit or delete another user's food entries. Now scoped to `added_by = Auth::id()`.
- **WeeklyCyclePlanController**: Fixed IDOR on `update` — `findOrFail($id)` had no ownership check. Changed to `where('user_id', Auth::id())->firstOrFail()`.
- **AuthController**: Revoke all existing tokens on login to prevent unbounded token accumulation and invalidate stolen prior-session tokens.
- **Sanctum**: Set token expiration to 30 days. Tokens were previously valid indefinitely.

### Fixed
- **WorkoutController**: Fixed fatal `TypeError` when logging a workout with an unrecognised `activity_type`. `Exercise::first()` could return `null`; accessing `->caloriesPerKg` on null now returns 422 instead of 500.
- **ProfileController**: Fixed crash on profile update when `weight` is absent from the request. `Progress::create` now only runs when `weight` is present.
- **GoalController**: Fixed crash when `weight`, `height`, or `age` are not set on the user profile. Both `index` and `update` return 422 with a descriptive message instead of corrupt macro values.
- **GoalController**: Fixed negative `carbs` value when protein and fat requirements exceeded total daily calories. Carbs are now clamped to `max(0, ...)`.
- **NotificationService / UserDevice model**: Fixed `ClassNotFoundException` breaking all push notifications. Model was named `UserDevices` but imported everywhere as `UserDevice`. Renamed class, file, and all references.
- **WorkoutLogController**: Fixed `details` field validation — changed from `nullable|json` (requiring pre-encoded string) to `nullable|array` so mobile clients can send a native object.

### Performance
- **MealController**: Eliminated N+1 query in the meal-logging streak check. Replaced up to 31 separate `EXISTS` queries with a single `pluck('date')` + in-memory check.

### Routing
- Removed duplicate `apiResource('/')` registrations in `foods`, `meals`, and `workouts` groups that conflicted with explicit route definitions.
- Reordered routes so static paths (`/search`, `/exercises`, `/calories-burned`) are declared before `/{id}` wildcards. `GET /foods/search` was previously unreachable.
- Removed redundant `->middleware('auth:sanctum')` on routes already inside the `auth:sanctum` group.

### Code Quality
- Standardised `auth::id()` to `Auth::id()` across `CustomWorkoutController`, `WorkoutLogController`, `ScheduledWorkoutController`, `WeeklyCyclePlanController`, and `NotificationController`.
- **CustomWorkoutController**: Added per-element validation for `gym_exercises` array (`integer|exists:gym_exercises,id`).
- **ProgressController**: Replaced `limit(10)` hard cap with `paginate(30)`.
- Removed dead methods `indexx()` and `showw()` from `WorkoutController` and their unused `WorkoutPlan` import.

---

## 2026-04-13

### Infrastructure
- Added GitHub Actions workflow (`.github/workflows/deploy.yml`) for automated deployment on push.

---

## 2026-02-05

### Fixed
- **WeeklyCyclePlanController**: Bug fix affecting weekly cycle plan creation and updates.
- **DatabaseSeeder**: Corrected seeder execution order.

---

## 2025-10-06

### Added
- **Account deletion**: `DELETE /api/profile` allows users to permanently delete their account and all associated data.
- **Account deactivation**: Added `is_valid` flag to the users table (`add_valid_user` migration). Login returns 403 for deactivated accounts.

---

## 2025-10-01

### Fixed
- **FoodController**: Fixed favorite food toggle returning incorrect state.

---

## 2025-05-04

### Added
- **Food model**: Added `is_custom` flag and `added_by` foreign key to the foods table to distinguish user-created foods from seeded data.

### Fixed
- **AchievementController**: Fixed achievement lookup returning incorrect results.
- **FoodController**: Fixed food search and creation for user-specific foods.

---

## 2025-04-06

### Fixed
- **GoalController**: Corrected macro calculation logic.
- **MealController**: Fixed meal logging edge cases.
- **ProgressController**: Fixed progress entry ordering.
- Added `edit_meal_table` migration for meal schema corrections.

---

## 2025-03-31

### Added
- Route for listing scheduled workouts added to `api.php`.

---

## 2025-03-30 — v2 Workout System

### Added
Full v2 workout system under `/api/v2/workouts/`:

- **GymExercise**: Seeded exercise library (`megaGymDataset.csv`, ~2,900 exercises) with `GymExerciseController` and `GymExerciseSeeder`.
- **CustomWorkout**: User-defined workouts referencing gym exercises via `custom_workout_exercise` pivot. Full CRUD via `CustomWorkoutController`.
- **WorkoutLog**: Records of completed workouts with duration and calories burned. CRUD via `WorkoutLogController`.
- **ScheduledWorkout**: Future-dated workout plans. CRUD via `ScheduledWorkoutController`.
- **WeeklyCyclePlan**: Recurring weekly workout schedule. CRUD via `WeeklyCyclePlanController`.
- **AI Recommendations**: `RecommendationController` endpoint for workout suggestions.
- Migrations for all new tables: `gym_exercises`, `workout_plans`, `exercise_workout_plan`, `custom_workouts`, `custom_workout_exercise`, `workout_logs`, `scheduled_workouts`, `weekly_cycle_plans`.

---

## 2025-03-28 — Push Notifications

### Added
- **Firebase Cloud Messaging**: Integrated `kreait/firebase-php`. `FirebaseServiceProvider` binds messaging to the container as `'firebase'`.
- **NotificationService**: Sends FCM push notifications to a user's registered device token.
- **NotificationController** + `POST /api/save-device-token`: Saves or updates a user's FCM device token.
- **UserDevices model** and `user_devices` migration.
- **Scheduled commands**: `SendDailyReminder` and `SendDailyMealReminder` artisan commands scheduled via `Console/Kernel`.

---

## 2025-03-23 — Achievements

### Added
- **Achievement system**: `Achievement` and `UserAchievement` models with unlock tracking.
- **AchievementService**: `checkAndUnlock($user, $type, $progress)` checks thresholds and unlocks badges, triggering a push notification on unlock.
- **AchievementController**: `GET /api/user/achievements` returns all achievements with unlock status.

---

## 2025-03-22

### Fixed
- **FoodController**: Improvements to food search and filtering logic for production.

---

## 2025-03-13

### Changed
- **Favorites**: Refactored from an `is_favorite` column on the `foods` table to a dedicated `favorite_foods` pivot table (`FavoriteFood` model). Added toggle, list, and search-by-favorite endpoints.

---

## 2025-03-10

### Added
- **Favorites v1**: `is_favorite` flag on foods, toggle endpoint at `POST /api/foods/{id}/favorite`.
- **Food database**: Loaded USDA ABBREV dataset (`foods.arff`) into food seeder.
- **WorkoutController**: Calories-burned calculation endpoint.

---

## 2025-03-05

### Added
- **Exercise library**: `Exercise` model and `exercises` table seeded with common exercises (`ExerciseSeeder`). Used by v1 workout calorie calculation.
- **CORS middleware**: `CorsMiddleware` added and registered globally.

### Changed
- **AuthController**, **FoodController**, **GoalController**, **MealController**, **ProfileController**: Refactored for production readiness — improved validation, response formatting, and error handling.

---

## 2025-02-26

### Fixed
- **RoleMiddleware**: Fixed role check logic that blocked valid role assignments.
- **RoleSeeder**: Simplified seeder to avoid duplicate role creation.
- Fixed admin route middleware binding in `api.php`.

---

## 2025-02-25 — Initial Release

### Added
Core Laravel 12 REST API with Sanctum authentication and Spatie Permission RBAC:

- **Auth**: Register, login, logout (`POST /api/register`, `POST /api/login`, `POST /api/logout`). Returns Bearer token. Roles: `user`, `admin`.
- **Profile**: View and update user profile with body measurements.
- **Food**: CRUD for food items with macro data (calories, protein, carbs, fat). Seeded from USDA ABBREV dataset.
- **Meals**: Log meals composed of foods. `totalMacros` aggregate endpoint.
- **Progress**: Time-series body measurement tracking (weight, etc.).
- **Goals**: Fitness goal setting (`weight_loss`, `muscle_gain`, `maintenance`) with macro calculation.
- **Workouts (v1)**: Basic workout CRUD at `/api/workouts/`.
- **Admin**: User management endpoints restricted to `admin` role.
- Database migrations, seeders, and full project scaffold.
