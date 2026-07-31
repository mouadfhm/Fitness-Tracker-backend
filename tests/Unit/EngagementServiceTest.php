<?php

namespace Tests\Unit;

use App\Services\EngagementService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The backoff table is the part of this feature that decides whether a real
 * person gets pinged, so it is tested at every band edge rather than in the
 * middle of each band, where an off-by-one hides.
 *
 * Deliberately extends PHPUnit's TestCase, not Laravel's: these are pure
 * functions and this suite must stay runnable without a database.
 */
class EngagementServiceTest extends TestCase
{
    public static function boundaryProvider(): array
    {
        return [
            // description                  days inactive   expected gap
            'first day, still active'    => [0,             1],
            'last day of the daily band' => [7,             1],
            'first day of backing off'   => [8,             3],
            'last day of the 3-day band' => [21,            3],
            'first day of the weekly'    => [22,            7],
            'last day before dormant'    => [60,            7],
            'dormant'                    => [61,            null],
            'long gone'                  => [365,           null],
        ];
    }

    #[DataProvider('boundaryProvider')]
    public function test_minimum_gap_at_each_boundary(int $daysInactive, ?int $expectedGap): void
    {
        $this->assertSame($expectedGap, EngagementService::minimumGapDays($daysInactive));
    }

    public function test_a_user_who_never_logged_anything_counts_as_day_zero(): void
    {
        // A brand new account has no engagement to measure. Treating null as
        // "infinitely dormant" would silence onboarding reminders on day one,
        // for exactly the users who most need them.
        $this->assertSame(0, EngagementService::daysInactive(null));
        $this->assertSame(1, EngagementService::minimumGapDays(EngagementService::daysInactive(null)));
        $this->assertTrue(EngagementService::shouldSend(EngagementService::daysInactive(null), null));
    }

    public function test_days_inactive_counts_calendar_days(): void
    {
        $now = Carbon::parse('2026-07-31 10:00:00');

        $this->assertSame(0, EngagementService::daysInactive(Carbon::parse('2026-07-31 09:59:00'), $now));
        // Late last night is one day ago this morning, not zero.
        $this->assertSame(1, EngagementService::daysInactive(Carbon::parse('2026-07-30 23:59:00'), $now));
        $this->assertSame(30, EngagementService::daysInactive(Carbon::parse('2026-07-01 10:00:00'), $now));
    }

    public function test_a_future_timestamp_does_not_produce_a_negative_count(): void
    {
        // Clock skew between the app server and the database, or a backfill
        // reading a future-dated row, must not fall through every band.
        $now = Carbon::parse('2026-07-31 10:00:00');

        $this->assertSame(0, EngagementService::daysInactive(Carbon::parse('2026-08-05 10:00:00'), $now));
    }

    public function test_first_reminder_of_a_type_always_goes_out(): void
    {
        $this->assertTrue(EngagementService::shouldSend(0, null));
        $this->assertTrue(EngagementService::shouldSend(30, null));
    }

    public function test_dormant_users_get_nothing_even_if_never_reminded(): void
    {
        $this->assertFalse(EngagementService::shouldSend(61, null));
        $this->assertFalse(EngagementService::shouldSend(90, 30));
    }

    public function test_a_user_inactive_thirty_days_gets_at_most_one_reminder_a_week(): void
    {
        $daysInactive = 30;

        foreach ([0, 1, 2, 3, 4, 5, 6] as $daysSinceLastSent) {
            $this->assertFalse(
                EngagementService::shouldSend($daysInactive, $daysSinceLastSent),
                "Should stay quiet {$daysSinceLastSent} days after the last reminder"
            );
        }

        $this->assertTrue(EngagementService::shouldSend($daysInactive, 7));
    }

    public function test_logging_a_meal_returns_a_dormant_user_to_daily(): void
    {
        // Before: 45 days away, on the weekly band, silent 3 days after a send.
        $this->assertFalse(EngagementService::shouldSend(45, 3));

        // The observer stamps last_engaged_at, so the next run sees day 0 and
        // the same 3-day-old reminder no longer blocks anything.
        $this->assertTrue(EngagementService::shouldSend(0, 3));
    }

    public function test_the_daily_band_tolerates_a_run_that_fires_early(): void
    {
        // The gap is counted in whole days, not elapsed hours, so yesterday's
        // 10:00:05 reminder does not block a run at today's 09:59:58.
        $this->assertTrue(EngagementService::shouldSend(0, 1));
    }
}
