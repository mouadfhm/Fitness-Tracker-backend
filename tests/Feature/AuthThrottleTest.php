<?php

namespace Tests\Feature;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_blocked_after_five_wrong_passwords(): void
    {
        User::create([
            'name' => 'Target',
            'email' => 'target@example.test',
            'password' => Hash::make('right-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'target@example.test', 'password' => "guess-$i"])
                ->assertStatus(422);
        }

        // Even the right password waits until the window passes.
        $this->postJson('/api/login', ['email' => 'target@example.test', 'password' => 'right-password'])
            ->assertStatus(429);

        $this->travel(61)->seconds();

        $this->postJson('/api/login', ['email' => 'target@example.test', 'password' => 'right-password'])
            ->assertOk();
    }

    public function test_register_needs_an_eight_character_password(): void
    {
        $this->postJson('/api/register', [
            'name' => 'New',
            'email' => 'new@example.test',
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ])->assertStatus(422)->assertJsonPath('errors.password.0', fn ($m) => str_contains($m, '8'));
    }

    public function test_server_errors_do_not_reach_the_client(): void
    {
        $controller = new class extends Controller
        {
            public function fail()
            {
                return $this->serverError(new \RuntimeException('SQLSTATE[42S02]: users table secret'), 'error');
            }
        };

        $response = $controller->fail();

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertSame('Something went wrong. Please try again.', $response->getData(true)['error']);
    }
}
