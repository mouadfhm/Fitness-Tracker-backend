<?php

namespace Tests\Unit;

use App\Services\StreakCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The things spec 10 says have to be true, checked against the arithmetic
 * rather than against a database.
 *
 * Like HabitualHourTest next to it, this extends PHPUnit's TestCase and not
 * Laravel's: StreakCalendar is pure, and it must stay runnable without a
 * database so a broken migration cannot hide a broken streak.
 */
class StreakCalendarTest extends TestCase
{
    /**
     * "Logging on consecutive days increments."
     */
    public function test_consecutive_days_increment(): void
    {
        $state = $this->replay(['2026-07-28', '2026-07-29', '2026-07-30']);

        $this->assertSame(3, $state['current_days']);
        $this->assertSame('2026-07-30', $state['last_day']);
    }

    /**
     * "Skipping a day resets to 1 on the next log."
     *
     * Note where the reset lands: on the next log, not at the missed midnight.
     * Nothing runs at midnight to zero anybody, which is why the column has to
     * be read through settled() rather than trusted raw.
     */
    public function test_a_missed_day_resets_to_one(): void
    {
        $state = $this->replay(['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-31']);

        $this->assertSame(1, $state['current_days']);
        $this->assertSame(3, $state['longest_days']);
    }

    public function test_two_logs_on_one_day_are_one_day(): void
    {
        $state = $this->replay(['2026-07-29', '2026-07-30', '2026-07-30', '2026-07-30']);

        $this->assertSame(2, $state['current_days']);
    }

    /**
     * "`longest_days` never decreases."
     *
     * Walked one day at a time rather than asserted at the end, because the
     * property is about every intermediate state and an end-state assertion
     * would pass on an implementation that dipped in the middle.
     */
    public function test_longest_never_decreases(): void
    {
        $days = [
            '2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05',
            '2026-06-11',
            '2026-06-12', '2026-06-13',
            '2026-07-01',
        ];

        $state = ['current_days' => 0, 'longest_days' => 0, 'last_day' => null];
        $highest = 0;

        foreach ($days as $day) {
            $state = StreakCalendar::advance(
                $state['current_days'],
                $state['longest_days'],
                $state['last_day'],
                $day
            );

            $this->assertGreaterThanOrEqual($highest, $state['longest_days']);
            $this->assertGreaterThanOrEqual($state['current_days'], $state['longest_days']);

            $highest = $state['longest_days'];
        }

        $this->assertSame(5, $state['longest_days']);
        $this->assertSame(1, $state['current_days']);
    }

    /**
     * A write dated before the streak's last day must not reset it.
     *
     * The realistic sources are a device with a skewed clock and a user whose
     * timezone moved west, and both would otherwise cost the user a run they
     * earned, on the strength of a log they made *earlier*.
     */
    public function test_an_out_of_order_day_is_ignored(): void
    {
        $state = $this->replay(['2026-07-28', '2026-07-29', '2026-07-30']);

        $after = StreakCalendar::advance(
            $state['current_days'],
            $state['longest_days'],
            $state['last_day'],
            '2026-07-25'
        );

        $this->assertSame($state, $after);
    }

    public function test_a_month_boundary_is_consecutive(): void
    {
        $this->assertSame(3, $this->replay(['2026-07-30', '2026-07-31', '2026-08-01'])['current_days']);
    }

    public function test_a_leap_day_is_consecutive(): void
    {
        $this->assertSame(3, $this->replay(['2028-02-28', '2028-02-29', '2028-03-01'])['current_days']);
    }

    /**
     * The stored number is only true as of the day it was written, and settled()
     * is what every reader has to go through to get today's answer.
     */
    #[DataProvider('settledProvider')]
    public function test_a_streak_is_over_once_its_last_day_is_not_yesterday(
        ?string $lastDay,
        int $expected
    ): void {
        $this->assertSame($expected, StreakCalendar::settled(6, $lastDay, '2026-07-30'));
    }

    public static function settledProvider(): array
    {
        return [
            'logged today'      => ['2026-07-30', 6],
            'logged yesterday'  => ['2026-07-29', 6],
            'logged two ago'    => ['2026-07-28', 0],
            'logged last month' => ['2026-06-30', 0],
            'never logged'      => [null, 0],
        ];
    }

    /**
     * "The at-risk notification fires only for streaks of 3+ with nothing logged
     * today."
     */
    #[DataProvider('atRiskProvider')]
    public function test_who_is_at_risk(int $current, ?string $lastDay, bool $expected): void
    {
        $this->assertSame($expected, StreakCalendar::isAtRisk($current, $lastDay, '2026-07-30'));
    }

    public static function atRiskProvider(): array
    {
        return [
            // The threshold. Two days is not a run anyone is afraid of losing.
            'one day, yesterday'    => [1, '2026-07-29', false],
            'two days, yesterday'   => [2, '2026-07-29', false],
            'three days, yesterday' => [3, '2026-07-29', true],
            'six days, yesterday'   => [6, '2026-07-29', true],

            // Already logged today: there is nothing at risk.
            'six days, today'       => [6, '2026-07-30', false],

            // Already broken. Telling someone they have failed demotivates;
            // this is the case the send-before-it-breaks rule exists for.
            'six days, two ago'     => [6, '2026-07-28', false],
            'never logged'          => [0, null, false],
        ];
    }

    /**
     * The invariant tying the two writers together.
     *
     * The observer applies advance() once per log and the backfill applies it
     * once per historical day, so replaying a history has to land exactly where
     * living through it does. If those ever diverge, a user's streak changes on
     * a deploy rather than on anything they did — and this is the assertion that
     * would fail first.
     */
    public function test_replaying_a_history_matches_living_through_it(): void
    {
        $lived = ['2026-07-20', '2026-07-21', '2026-07-21', '2026-07-23', '2026-07-24', '2026-07-25'];

        // The backfill's input: deduplicated by the GROUP BY, arriving from
        // three tables in no particular order, sorted before replay.
        $backfilled = ['2026-07-24', '2026-07-20', '2026-07-25', '2026-07-21', '2026-07-23'];
        sort($backfilled);

        $this->assertSame($this->replay($lived), $this->replay($backfilled));
    }

    /**
     * @param string[] $days
     * @return array{current_days:int,longest_days:int,last_day:string|null}
     */
    private function replay(array $days): array
    {
        $state = ['current_days' => 0, 'longest_days' => 0, 'last_day' => null];

        foreach ($days as $day) {
            $state = StreakCalendar::advance(
                $state['current_days'],
                $state['longest_days'],
                $state['last_day'],
                $day
            );
        }

        return $state;
    }
}
