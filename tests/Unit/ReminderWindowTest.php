<?php

namespace Tests\Unit;

use App\Services\ReminderWindow;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scheduler no longer decides when a reminder goes out — this does. A bug
 * here is not a wrong log line, it is every user in a whole timezone either
 * being reminded twice a day or never hearing from us again.
 *
 * Deliberately extends PHPUnit's TestCase, not Laravel's: isDue is a pure
 * function and this suite must stay runnable without a database.
 */
class ReminderWindowTest extends TestCase
{
    /**
     * A day with no DST transitions anywhere, so the exactly-once property
     * below tests the arithmetic rather than the tz database.
     */
    private const QUIET_DAY = '2026-06-15';

    public static function windowProvider(): array
    {
        return [
            // description                        local    target     expected
            'exactly on the hour target'       => ['10:00', [10, 0],  true],
            'one minute in'                    => ['10:01', [10, 0],  true],
            'last minute of the hour window'   => ['10:29', [10, 0],  true],
            'first minute past the window'     => ['10:30', [10, 0],  false],
            'one minute early'                 => ['09:59', [10, 0],  false],
            'twelve hours out'                 => ['22:00', [10, 0],  false],

            // 18:30 is where the workout reminder has always been. An
            // hour-boundary check would have quietly moved it to 18:00.
            'exactly on the half-hour target'  => ['18:30', [18, 30], true],
            'quarter past the target'          => ['18:45', [18, 30], true],
            'last minute of the half window'   => ['18:59', [18, 30], true],
            'the half hour before'             => ['18:00', [18, 30], false],
            'the half hour after'              => ['19:00', [18, 30], false],
        ];
    }

    #[DataProvider('windowProvider')]
    public function test_window_boundaries(string $localTime, array $target, bool $expected): void
    {
        $localNow = Carbon::parse(self::QUIET_DAY . ' ' . $localTime);

        $this->assertSame($expected, ReminderWindow::isDue($localNow, $target[0], $target[1]));
    }

    public function test_a_window_that_straddles_midnight_still_matches(): void
    {
        // Nothing targets 00:xx today, but the modulo is the kind of thing that
        // works right up until the first time someone picks an early target.
        $this->assertTrue(ReminderWindow::isDue(Carbon::parse('2026-06-15 00:20:00'), 0, 15));
        $this->assertTrue(ReminderWindow::isDue(Carbon::parse('2026-06-15 00:00:00'), 23, 45));
        $this->assertTrue(ReminderWindow::isDue(Carbon::parse('2026-06-15 00:14:00'), 23, 45));
        $this->assertFalse(ReminderWindow::isDue(Carbon::parse('2026-06-15 00:15:00'), 23, 45));
    }

    public static function targetProvider(): array
    {
        return [
            'meal reminder, 10:00'    => [10, 0],
            'workout reminder, 18:30' => [18, 30],
        ];
    }

    /**
     * The property the whole design rests on, checked against every zone PHP
     * knows rather than the handful someone thought of.
     *
     * Offsets are not all whole hours — Asia/Kolkata is +05:30, Asia/Kathmandu
     * +05:45, Australia/Adelaide +09:30, Pacific/Chatham +12:45. Matching on the
     * hour alone would fire twice for the half-hour zones; matching hour and
     * minute exactly would never fire for any of them.
     */
    #[DataProvider('targetProvider')]
    public function test_every_timezone_matches_exactly_once_a_day(int $hour, int $minute): void
    {
        $runsPerDay = intdiv(24 * 60, ReminderWindow::INTERVAL_MINUTES);

        foreach (timezone_identifiers_list() as $timezone) {
            $matches = 0;

            // The scheduler fires on the server's clock, which is UTC.
            $run = Carbon::parse(self::QUIET_DAY . ' 00:00:00', 'UTC');

            for ($i = 0; $i < $runsPerDay; $i++) {
                if (ReminderWindow::isDue($run->copy()->setTimezone($timezone), $hour, $minute)) {
                    $matches++;
                }
                $run->addMinutes(ReminderWindow::INTERVAL_MINUTES);
            }

            $this->assertSame(1, $matches, "{$timezone} matched {$matches} times in a day, expected exactly 1");
        }
    }
}
