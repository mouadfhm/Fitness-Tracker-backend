<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/goals/search, the calorie and macro goal the Today screen shows.
 */
class GoalMacrosTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'name' => 'Lifter', 'email' => 'lift@example.test', 'password' => 'x',
            'weight' => 70, 'height' => 175, 'age' => 30, 'gender' => 'male',
            'activity_level' => 'active', 'fitness_goal' => 'weight_loss',
        ]);
    }

    public function test_logged_workouts_do_not_raise_the_goal(): void
    {
        $user = $this->user();
        $before = $this->actingAs($user)->getJson('/api/goals/search')->json('macros');

        Workout::create([
            'user_id' => $user->id, 'activity_type' => 'Running', 'duration' => 60,
            'calories_burned' => 600, 'workout_date' => now()->toDateString(),
        ]);

        $after = $this->actingAs($user)->getJson('/api/goals/search')->json('macros');

        $this->assertSame($before, $after);
        // 1648.75 * 1.725 - 500
        $this->assertEquals(round(1648.75 * 1.725 - 500), $after['calories']);
        $this->assertEqualsWithDelta(1648.75 * 1.725, $after['tdee'], 0.01);
        $this->assertEquals(140, $after['protein']);
    }

    public function test_protein_is_counted_against_a_healthy_weight(): void
    {
        // 130 kg at 180 cm: protein counts 81 kg (BMI 25), not 130
        $user = $this->user(['weight' => 130, 'height' => 180]);

        $macros = $this->actingAs($user)->getJson('/api/goals/search')->json('macros');

        $this->assertEquals(round(25 * 1.8 * 1.8 * 2.0), $macros['protein']);
    }

    public function test_old_app_capitalised_activity_level_is_accepted(): void
    {
        $user = $this->user();

        $this->actingAs($user)->putJson('/api/profile', ['activity_level' => 'Moderate'])->assertOk();
        $this->assertSame('moderate', $user->fresh()->activity_level);

        $this->actingAs($user)->putJson('/api/profile', ['activity_level' => 'couch'])->assertStatus(422);
    }
}
