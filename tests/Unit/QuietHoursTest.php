<?php

namespace Tests\Unit;

use App\Services\QuietHours;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The wrap past midnight is the whole reason this class exists, so most of what
 * follows is aimed at it. A quiet window written as a plain `between` matches
 * nothing at all for 22:00-08:00, and the symptom — everyone still hearing from
 * us at 3am — looks like the feature was never wired up rather than like an
 * off-by-one.
 *
 * Extends PHPUnit's TestCase rather than Laravel's, matching ReminderWindowTest:
 * covers() is a pure function and this suite must stay runnable without a
 * database.
 */
class QuietHoursTest extends TestCase
{
    private static function at(string $time): Carbon
    {
        return Carbon::parse('2026-06-15 ' . $time);
    }

    public static function windowProvider(): array
    {
        return [
            // description                              now      from     to       quiet?

            // The default window, and the case a naive between gets wrong.
            'late evening, inside the wrap'         => ['23:30', '22:00', '08:00', true],
            'after midnight, still inside the wrap' => ['03:00', '22:00', '08:00', true],
            'mid-afternoon, plainly outside'        => ['15:00', '22:00', '08:00', false],
            'the 10:00 meal reminder is audible'    => ['10:00', '22:00', '08:00', false],
            'the 18:30 workout reminder is audible' => ['18:30', '22:00', '08:00', false],

            // Half-open at both ends: quiet begins the minute it says and ends
            // the minute it says.
            'first minute of the window'            => ['22:00', '22:00', '08:00', true],
            'one minute before it starts'           => ['21:59', '22:00', '08:00', false],
            'last minute of the window'             => ['07:59', '22:00', '08:00', true],
            'the minute it ends'                    => ['08:00', '22:00', '08:00', false],
            'midnight itself'                       => ['00:00', '22:00', '08:00', true],

            // A window that does not wrap still has to work.
            'inside a same-day window'              => ['13:30', '13:00', '14:00', true],
            'before a same-day window'              => ['12:59', '13:00', '14:00', false],
            'after a same-day window'               => ['14:00', '13:00', '14:00', false],

            // Quiet hours switched off.
            'both ends null'                        => ['03:00', null,    null,    false],
            'only the start set'                    => ['03:00', '22:00', null,    false],
            'only the end set'                      => ['03:00', null,    '08:00', false],

            // Equal ends are zero-length, not all day — otherwise two pickers
            // dragged to the same value mute the app permanently.
            'equal ends, at that time'              => ['22:00', '22:00', '22:00', false],
            'equal ends, elsewhere in the day'      => ['03:00', '22:00', '22:00', false],

            // Seconds survive the round trip from a MySQL `time` column.
            'HH:MM:SS from the database'            => ['23:30', '22:00:00', '08:00:00', true],

            // Junk fails open. A user hearing from us once too often is
            // recoverable; a user silenced forever by an unparseable string is
            // not.
            'unparseable start'                     => ['03:00', 'later', '08:00', false],
            'hour out of range'                     => ['03:00', '24:00', '08:00', false],
        ];
    }

    #[DataProvider('windowProvider')]
    public function test_covers(string $now, ?string $from, ?string $to, bool $expected): void
    {
        $this->assertSame($expected, QuietHours::covers(self::at($now), $from, $to));
    }

    /**
     * Enumerating the day catches an off-by-one at either edge that the
     * hand-picked cases above could agree on and still both be wrong.
     */
    public function test_the_default_window_covers_exactly_ten_hours(): void
    {
        $quiet = 0;

        for ($minute = 0; $minute < 1440; $minute++) {
            if (QuietHours::covers(self::at('00:00')->addMinutes($minute), '22:00', '08:00')) {
                $quiet++;
            }
        }

        // 22:00 to midnight is 120 minutes, midnight to 08:00 is 480.
        $this->assertSame(600, $quiet);
    }

    public function test_format_normalises_to_hours_and_minutes(): void
    {
        $this->assertSame('22:00', QuietHours::format('22:00:00'));
        $this->assertSame('08:00', QuietHours::format('08:00'));
        $this->assertSame('09:05', QuietHours::format('9:05'));
        $this->assertNull(QuietHours::format(null));
        $this->assertNull(QuietHours::format('not a time'));
    }
}
