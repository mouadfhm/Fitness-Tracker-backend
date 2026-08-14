<?php

namespace Tests\Feature;

use App\Models\GymExercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomWorkoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_gym_exercises_with_pivot_data_and_persists_it(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $benchPress = $this->makeGymExercise('Bench Press');
        $plank = $this->makeGymExercise('Plank');

        $response = $this->postJson('/api/v2/workouts/custom-workouts', [
            'name' => 'Push Day',
            'description' => 'Chest and triceps',
            'gym_exercises' => [
                ['gym_exercise_id' => $benchPress->id, 'sets' => 3, 'reps' => 10, 'duration' => null, 'rest' => 60],
                ['gym_exercise_id' => $plank->id, 'sets' => 3, 'reps' => null, 'duration' => 30, 'rest' => 30],
            ],
        ]);

        $response->assertStatus(201);

        $workoutId = $response->json('id');
        $this->assertDatabaseHas('custom_workout_exercise', [
            'custom_workout_id' => $workoutId,
            'gym_exercise_id' => $benchPress->id,
            'sets' => 3,
            'reps' => 10,
            'duration' => null,
            'rest' => 60,
        ]);
        $this->assertDatabaseHas('custom_workout_exercise', [
            'custom_workout_id' => $workoutId,
            'gym_exercise_id' => $plank->id,
            'sets' => 3,
            'reps' => null,
            'duration' => 30,
            'rest' => 30,
        ]);
    }

    public function test_store_rejects_an_exercise_missing_gym_exercise_id(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v2/workouts/custom-workouts', [
            'name' => 'Push Day',
            'description' => '',
            'gym_exercises' => [
                ['sets' => 3, 'reps' => 10, 'duration' => null, 'rest' => 60],
            ],
        ]);

        // The controller's catch-all wraps ValidationException into a 500
        // with an {"error": "..."} body rather than a 422 — preserving the
        // existing (pre-fix) error-handling shape rather than changing it.
        $response->assertStatus(500);
        $this->assertStringContainsString('gym_exercise_id', $response->json('error'));
    }

    public function test_update_replaces_gym_exercises_and_their_pivot_data(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $benchPress = $this->makeGymExercise('Bench Press');
        $squat = $this->makeGymExercise('Squat');

        $created = $this->postJson('/api/v2/workouts/custom-workouts', [
            'name' => 'Push Day',
            'description' => '',
            'gym_exercises' => [
                ['gym_exercise_id' => $benchPress->id, 'sets' => 3, 'reps' => 10, 'duration' => null, 'rest' => 60],
            ],
        ])->json();

        $response = $this->putJson("/api/v2/workouts/custom-workouts/{$created['id']}", [
            'name' => 'Leg Day',
            'description' => 'Updated',
            'gym_exercises' => [
                ['gym_exercise_id' => $squat->id, 'sets' => 5, 'reps' => 5, 'duration' => null, 'rest' => 90],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('custom_workout_exercise', [
            'custom_workout_id' => $created['id'],
            'gym_exercise_id' => $squat->id,
            'sets' => 5,
            'reps' => 5,
            'rest' => 90,
        ]);
        $this->assertDatabaseMissing('custom_workout_exercise', [
            'custom_workout_id' => $created['id'],
            'gym_exercise_id' => $benchPress->id,
        ]);
    }

    // Mirrors StreakTest::makeUser() — the local fitness_testing database is
    // missing the email_verified_at column, so User::factory() (which sets
    // it) fails here; a plain User::create() with only the columns that
    // exist works around that drift.
    private function makeUser(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "Custom Workout User {$n}",
            'email' => "custom-workout-user-{$n}@example.test",
            'password' => 'x',
        ]);
    }

    private function makeGymExercise(string $name): GymExercise
    {
        return GymExercise::create([
            'name' => $name,
            'description' => "{$name} description",
            'type' => 'strength',
            'body_part' => 'chest',
            'equipment' => 'barbell',
            'level' => 'beginner',
        ]);
    }
}
