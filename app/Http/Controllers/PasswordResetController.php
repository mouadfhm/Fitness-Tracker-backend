<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\PasswordResetCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * "Forgot password" for the mobile app: email a 6-digit code, then exchange
 * the code for a new password.
 *
 * Codes live hashed in the stock password_reset_tokens table (one row per
 * email, so asking again replaces the previous code). Six digits is only a
 * million guesses, so a code dies after MAX_ATTEMPTS wrong tries; together
 * with the route throttles that keeps guessing hopeless.
 */
class PasswordResetController extends Controller
{
    public const EXPIRES_MINUTES = 30;

    public const MAX_ATTEMPTS = 5;

    public function sendCode(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        $email = strtolower($data['email']);

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => Hash::make($code), 'created_at' => now()],
            );
            RateLimiter::clear($this->attemptsKey($email));

            try {
                $user->notify(new PasswordResetCode($code, self::EXPIRES_MINUTES, app()->getLocale()));
            } catch (\Throwable $e) {
                // Still answer the same way: the response must not tell
                // anyone whether the email has an account.
                report($e);
            }
        }

        return response()->json([
            'message' => 'If an account exists for this email, we sent a code to it.',
        ]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'code' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);
        $email = strtolower($data['email']);
        $attemptsKey = $this->attemptsKey($email);

        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        $valid = $row
            && now()->subMinutes(self::EXPIRES_MINUTES)->lt($row->created_at)
            && ! RateLimiter::tooManyAttempts($attemptsKey, self::MAX_ATTEMPTS)
            && Hash::check(trim($data['code']), $row->token);

        if (! $valid) {
            if ($row) {
                RateLimiter::hit($attemptsKey, self::EXPIRES_MINUTES * 60);
                if (RateLimiter::tooManyAttempts($attemptsKey, self::MAX_ATTEMPTS)) {
                    DB::table('password_reset_tokens')->where('email', $email)->delete();
                }
            }
            throw ValidationException::withMessages([
                'code' => ['This code is wrong or has expired. Ask for a new one.'],
            ]);
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            throw ValidationException::withMessages([
                'code' => ['This code is wrong or has expired. Ask for a new one.'],
            ]);
        }

        DB::transaction(function () use ($user, $data, $email) {
            $user->forceFill(['password' => Hash::make($data['password'])])->save();
            // Whoever knew the old password is signed out everywhere.
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();
        });
        RateLimiter::clear($attemptsKey);

        return response()->json(['message' => 'Your password has been changed. You can log in now.']);
    }

    private function attemptsKey(string $email): string
    {
        return 'password-reset-attempts:'.$email;
    }
}
