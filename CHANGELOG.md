# Changelog

## [Unreleased]

### Security
- **FoodController**: Fixed SQL injection vector — `$userId` was concatenated directly into a raw SQL fragment. Replaced with parameterized `selectRaw(..., [$userId])`.
- **FoodController**: Added ownership check on `update` and `destroy` — any authenticated user could previously edit or delete another user's food entries. Now scoped to `added_by = Auth::id()`.
- **WeeklyCyclePlanController**: Fixed IDOR vulnerability on `update` — `findOrFail($id)` had no ownership check, allowing any authenticated user to overwrite another user's weekly cycle plan. Changed to `where('user_id', Auth::id())->firstOrFail()`.
- **AuthController**: Revoke all existing tokens on login to prevent unbounded token accumulation. A stolen token from a previous session is now invalidated on next login.
- **Sanctum**: Set token expiration to 30 days (`config/sanctum.php`). Tokens were previously valid indefinitely.

### Fixed
- **WorkoutController**: Fixed fatal `TypeError` crash when logging a workout with an unrecognised `activity_type`. `Exercise::first()` could return `null`; accessing `->caloriesPerKg` on null now returns a 422 instead of a 500.
- **ProfileController**: Fixed crash on profile update when `weight` is not included in the request. `Progress::create` now only runs when `weight` is present in the validated data.
- **GoalController**: Fixed `calculateMacros` crashing with null arithmetic when `weight`, `height`, or `age` are not set on the user profile. Both `index` and `update` now return a 422 with a descriptive message instead of producing corrupt macro values.
- **GoalController**: Fixed negative `carbs` value returned when protein and fat requirements exceeded total daily calories. Carbs are now clamped to `max(0, ...)`.
- **NotificationController / NotificationService**: Fixed `ClassNotFoundException` that broke all push notifications. The model class was named `UserDevices` but imported everywhere as `UserDevice`. Renamed class and file to `UserDevice` and updated all references.
- **WorkoutLogController**: Fixed `details` field validation — was `nullable|json` (requiring a pre-encoded JSON string), changed to `nullable|array` so mobile clients can send a native object.

### Performance
- **MealController**: Eliminated N+1 query in the meal-logging streak check. The previous implementation fired up to 31 separate `EXISTS` queries (one per day). Replaced with a single `pluck('date')` query and an in-memory check.

### Removed
- **WorkoutController**: Removed dead methods `indexx()` and `showw()` which referenced the unimported `WorkoutPlan` model and had no route bindings. Also removed the unused `WorkoutPlan` import.

### Routing
- Removed duplicate `apiResource('/')` registrations in the `foods`, `meals`, and `workouts` route groups that conflicted with explicit route definitions.
- Reordered routes in `foods` and `workouts` groups so that static paths (`/search`, `/exercises`, `/calories-burned`) are declared before the `/{id}` wildcard. Previously, `GET /search` was unreachable in the `foods` group because the wildcard intercepted it first.
- Removed redundant `->middleware('auth:sanctum')` calls on `/user/achievements` and `/save-device-token` routes that were already inside the `auth:sanctum` middleware group.

### Code Quality
- Standardised `auth::id()` (lowercase, non-standard) to `Auth::id()` across `CustomWorkoutController`, `WorkoutLogController`, `ScheduledWorkoutController`, `WeeklyCyclePlanController`, and `NotificationController`.
- **CustomWorkoutController**: Added per-element validation for the `gym_exercises` array (`integer|exists:gym_exercises,id`). Previously any payload shape was accepted, causing unhandled exceptions on malformed input.
- **ProgressController**: Replaced `limit(10)` hard cap with `paginate(30)`. Users with more than 10 progress entries could not access their full history.
