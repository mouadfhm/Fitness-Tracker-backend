<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\Progress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DELETE /api/profile has to remove the account and its data, not deactivate
 * it (App Store guideline 5.1.1(v)), and the email has to be free again
 * afterwards so the person can sign up from scratch.
 */
class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_the_account_removes_the_user_and_their_data(): void
    {
        Role::findOrCreate('user', 'web');

        $user = User::create([
            'name' => 'Leaving User',
            'email' => 'leaving@example.test',
            'password' => 'x',
        ]);
        $user->assignRole('user');
        $token = $user->createToken('auth_token')->plainTextToken;

        Meal::create(['user_id' => $user->id, 'date' => '2026-10-10', 'meal_time' => 'breakfast']);
        Progress::create(['user_id' => $user->id, 'date' => '2026-10-10', 'weight' => 80]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/profile')
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('meals', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('progresses', ['user_id' => $user->id]);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $user->id)->count());
    }

    public function test_the_email_can_register_again_after_deletion(): void
    {
        Role::findOrCreate('user', 'web');

        $user = User::create([
            'name' => 'Returning User',
            'email' => 'returning@example.test',
            'password' => 'x',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/profile')
            ->assertOk();

        $this->assertNull(User::where('email', 'returning@example.test')->first());
    }
}
