<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Forgetful',
            'email' => 'forgetful@example.test',
            'password' => Hash::make('old-password'),
        ]);
    }

    /** Requests a code and returns it, read from the faked email. */
    private function requestCode(string $email = 'forgetful@example.test'): string
    {
        $this->postJson('/api/forgot-password', ['email' => $email])->assertOk();

        $code = null;
        Notification::assertSentTo(
            User::where('email', $email)->first(),
            PasswordResetCode::class,
            function (PasswordResetCode $n) use (&$code, $email) {
                preg_match('/\*\*(\d{6})\*\*/', implode("\n", $n->toMail(User::where('email', $email)->first())->introLines), $m);
                $code = $m[1] ?? null;

                return $code !== null;
            },
        );

        return $code;
    }

    public function test_a_code_resets_the_password_and_signs_out_other_sessions(): void
    {
        Notification::fake();
        $user = $this->makeUser();
        $user->createToken('auth_token');

        $code = $this->requestCode();

        $this->postJson('/api/reset-password', [
            'email' => 'forgetful@example.test',
            'code' => $code,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        $this->postJson('/api/login', ['email' => 'forgetful@example.test', 'password' => 'new-password'])
            ->assertOk();
    }

    public function test_an_unknown_email_gets_the_same_answer_and_no_email(): void
    {
        Notification::fake();
        $this->makeUser();

        $known = $this->postJson('/api/forgot-password', ['email' => 'forgetful@example.test'])->json();
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.test'])->json();

        $this->assertSame($known, $unknown);
        Notification::assertSentTimes(PasswordResetCode::class, 1);
    }

    public function test_a_wrong_code_is_rejected_and_five_misses_kill_the_code(): void
    {
        Notification::fake();
        $user = $this->makeUser();
        $code = $this->requestCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/reset-password', [
                'email' => 'forgetful@example.test',
                'code' => $wrong,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertStatus(422)->assertJsonValidationErrors('code');
        }

        // The right code no longer works either.
        $this->postJson('/api/reset-password', [
            'email' => 'forgetful@example.test',
            'code' => $code,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_an_expired_code_is_rejected(): void
    {
        Notification::fake();
        $user = $this->makeUser();
        $code = $this->requestCode();

        $this->travel(31)->minutes();

        $this->postJson('/api/reset-password', [
            'email' => 'forgetful@example.test',
            'code' => $code,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_the_new_password_needs_eight_characters(): void
    {
        Notification::fake();
        $this->makeUser();
        $code = $this->requestCode();

        $this->postJson('/api/reset-password', [
            'email' => 'forgetful@example.test',
            'code' => $code,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
