<?php

namespace Tests\Unit;

use App\Models\NotificationPreference;
use App\Services\QuietHours;
use App\Services\SessionReminderWindow;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The command runs hourly, so an off-by-one here is not a missing log line —
 * it is either three notifications for one gym session or none at all.
 *
 * Extends PHPUnit's TestCase rather than Laravel's, like ReminderWindowTest:
 * the whole class under test is arithmetic, and this suite has to stay runnable
 * without a database.
 */
class SessionReminderWindowTest extends TestCase
{
    /**
     * A day with no DST transitions anywhere, so the exactly-once property
     * below tests the arithmetic rather than the tz database.
     */
    private const QUIET_DAY = '2026-06-15';

    public static function timedProvider(): array
    {
        // A session at 18:00. The window is [16:30, 17:30).
        return [
            'ninety minutes out, the moment it opens' => ['16:30', true],
            'an hour out'                             => ['17:00', true],
            'the last minute of the window'           => ['17:29', true],
            'thirty minutes out, just closed'         => ['17:30', false],
            'one minute too early'                    => ['16:29', false],
            'two hours out'                           => ['16:00', false],
            'as it starts'                            => ['18:00', false],
            'the morning of'                          => ['08:00', false],
            'the previous evening'                    => ['22:30', false],
        ];
    }

    #[DataProvider('timedProvider')]
    public function test_a_session_with_a_real_time_is_reminded_30_to_90_minutes_ahead(string $localTime, bool $expected): void
    {
        $this->assertSame($expected, SessionReminderWindow::isDue(
            Carbon::parse(self::QUIET_DAY . ' ' . $localTime),
            Carbon::parse(self::QUIET_DAY . ' 18:00:00'),
        ));
    }

    public static function dateOnlyProvider(): array
    {
        // Everything in production sits at 00:00 or 01:00, so both have to land
        // on the morning-of path. The window is [08:00, 09:00) on the day
        // itself — never the 22:30 the night before that a literal reading of
        // "90 minutes ahead" would produce.
        return [
            'midnight session, 08:00'  => ['00:00:00', '08:00', true],
            'midnight session, 08:59'  => ['00:00:00', '08:59', true],
            'midnight session, 09:00'  => ['00:00:00', '09:00', false],
            'midnight session, 07:59'  => ['00:00:00', '07:59', false],
            'midnight, 22:30 the night before is not it'
                                       => ['00:00:00', '22:30', false],
            'one am session, 08:00'    => ['01:00:00', '08:00', true],
            'one am session, 08:30'    => ['01:00:00', '08:30', true],
            'one am session, 09:00'    => ['01:00:00', '09:00', false],
            'one am, 23:30 the night before is not it'
                                       => ['01:00:00', '23:30', false],
        ];
    }

    #[DataProvider('dateOnlyProvider')]
    public function test_a_session_with_no_real_time_is_reminded_on_the_morning_of(string $scheduledTime, string $localTime, bool $expected): void
    {
        $this->assertSame($expected, SessionReminderWindow::isDue(
            Carbon::parse(self::QUIET_DAY . ' ' . $localTime),
            Carbon::parse(self::QUIET_DAY . ' ' . $scheduledTime),
        ));
    }

    /**
     * The threshold between "the user picked this" and "a date picker left it
     * there". 04:00 is the boundary, half-open like everything else here.
     */
    public function test_where_a_time_stops_being_an_artefact(): void
    {
        $day = self::QUIET_DAY . ' ';

        $this->assertFalse(SessionReminderWindow::hasMeaningfulTime(Carbon::parse($day . '00:00:00')));
        $this->assertFalse(SessionReminderWindow::hasMeaningfulTime(Carbon::parse($day . '01:00:00')));
        $this->assertFalse(SessionReminderWindow::hasMeaningfulTime(Carbon::parse($day . '03:59:00')));
        $this->assertTrue(SessionReminderWindow::hasMeaningfulTime(Carbon::parse($day . '04:00:00')));
        $this->assertTrue(SessionReminderWindow::hasMeaningfulTime(Carbon::parse($day . '06:30:00')));
        $this->assertTrue(SessionReminderWindow::hasMeaningfulTime(Carbon::parse($day . '18:00:00')));
    }

    /**
     * The 08:00 fallback has to clear the default quiet window, which is
     * 22:00-08:00, or the feature ships muted for every user who has never
     * opened the settings screen. Asserted against the real constants so that
     * moving either one fails here rather than in production silence.
     */
    public function test_the_fallback_hour_clears_the_default_quiet_window(): void
    {
        $localNow = Carbon::parse(self::QUIET_DAY . ' ')
            ->setTime(SessionReminderWindow::FALLBACK_HOUR, 0);

        $this->assertFalse(QuietHours::covers(
            $localNow,
            NotificationPreference::DEFAULTS['quiet_from'],
            NotificationPreference::DEFAULTS['quiet_to'],
        ));
    }

    public static function sessionProvider(): array
    {
        return [
            'an evening session at 18:00'  => ['18:00:00'],
            'an early session at 06:15'    => ['06:15:00'],
            'a half-past session at 19:30' => ['19:30:00'],
            'a date-only session'          => ['00:00:00'],
            'a date-only session at 01:00' => ['01:00:00'],
        ];
    }

    /**
     * The property the design rests on, checked against every zone PHP knows
     * rather than the handful someone thought of.
     *
     * Offsets are not all whole hours — Asia/Kolkata is +05:30, Asia/Kathmandu
     * +05:45, Pacific/Chatham +12:45. The scheduler fires on the server's UTC
     * clock at the top of each hour, so in those zones every run reads :30 or
     * :45 past. A window aligned to the hour boundary would miss them entirely;
     * one wider than the hour would catch them twice.
     */
    #[DataProvider('sessionProvider')]
    public function test_every_timezone_is_reminded_exactly_once(string $scheduledTime): void
    {
        $runsPerDay = intdiv(24 * 60, SessionReminderWindow::INTERVAL_MINUTES);

        foreach (timezone_identifiers_list() as $timezone) {
            $scheduledAt = Carbon::parse(self::QUIET_DAY . ' ' . $scheduledTime, $timezone);

            $matches = 0;

            // A full day of runs either side of the session's own day, so a
            // window that drifted onto the wrong date shows up as a second
            // match rather than being cropped out of the loop.
            $run = Carbon::parse(self::QUIET_DAY . ' 00:00:00', 'UTC')->subDay();

            for ($i = 0; $i < $runsPerDay * 3; $i++) {
                if (SessionReminderWindow::isDue($run->copy()->setTimezone($timezone), $scheduledAt)) {
                    $matches++;
                }
                $run->addMinutes(SessionReminderWindow::INTERVAL_MINUTES);
            }

            $this->assertSame(1, $matches, "{$timezone} matched {$matches} times, expected exactly 1");
        }
    }
}
