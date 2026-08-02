<?php

namespace Tests\Unit;

use App\Services\HabitualHour;
use App\Services\QuietHours;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The things spec 07 says have to be true, checked against the arithmetic
 * rather than against a database.
 *
 * Like ReminderWindowTest next to it, this extends PHPUnit's TestCase and not
 * Laravel's: HabitualHour is pure, and it must stay runnable without a database
 * so that a broken migration cannot hide a broken median.
 */
class HabitualHourTest extends TestCase
{
    /**
     * "A user who consistently logs at 07:30 receives the meal reminder at
     * 07:00, not 10:00" — with the one deviation this build makes, and makes
     * deliberately: 08:00, not 07:00.
     *
     * 07:00 sits inside the 22:00-08:00 quiet window every user has by default
     * (spec 08), so a reminder computed to it would be suppressed and the user
     * would receive nothing at all — strictly worse than the 10:00 they have
     * today. The assertions below are what would break if someone lowered
     * EARLIEST_HOUR back to 7 without also moving that default.
     */
    public function test_an_early_riser_is_moved_off_the_ten_oclock_default(): void
    {
        $breakfastAtHalfPastSeven = [7, 7, 7, 7, 7, 7];

        $hour = HabitualHour::fromDailyHours($breakfastAtHalfPastSeven);

        $this->assertSame(8, $hour, 'An 07:30 logger should be reminded early, not at 10:00.');
        $this->assertNotSame(10, $hour);

        // And the reason it is 8 rather than 7: at 07:00 nobody would hear it.
        $this->assertTrue(
            QuietHours::covers(Carbon::createFromTime(7, 0), '22:00', '08:00'),
            'The default quiet window no longer covers 07:00 — EARLIEST_HOUR can be lowered.'
        );
        $this->assertFalse(QuietHours::covers(Carbon::createFromTime($hour, 0), '22:00', '08:00'));
    }

    /**
     * "A user with fewer than 5 entries receives it at the 10:00 default."
     */
    #[DataProvider('thinSampleProvider')]
    public function test_a_thin_sample_learns_nothing(array $hours): void
    {
        $this->assertNull(HabitualHour::fromDailyHours($hours));
    }

    public static function thinSampleProvider(): array
    {
        return [
            'never logged'         => [[]],
            'one day'              => [[9]],
            'four days'            => [[9, 9, 9, 9]],
            'four days, unanimous' => [[19, 19, 19, 19]],
        ];
    }

    public function test_the_sample_minimum_is_a_floor_not_a_range(): void
    {
        $this->assertNull(HabitualHour::fromDailyHours([9, 9, 9, 9]));
        $this->assertSame(9, HabitualHour::fromDailyHours([9, 9, 9, 9, 9]));
    }

    /**
     * "Nobody receives a reminder outside the clamp window."
     */
    #[DataProvider('clampProvider')]
    public function test_the_clamp_holds(array $hours, int $expected): void
    {
        $this->assertSame($expected, HabitualHour::fromDailyHours($hours));
    }

    public static function clampProvider(): array
    {
        return [
            // The spec's own example: someone who logs in the small hours must
            // not be woken at 03:00 because of it.
            'a three am logger'    => [[3, 3, 3, 3, 3, 3], HabitualHour::EARLIEST_HOUR],
            'a two am logger'      => [[2, 1, 2, 3, 2, 2], HabitualHour::EARLIEST_HOUR],
            'a midnight logger'    => [[0, 0, 0, 0, 0], HabitualHour::EARLIEST_HOUR],
            'a late night logger'  => [[23, 23, 23, 22, 23], HabitualHour::LATEST_HOUR],
            'right on the floor'   => [[8, 8, 8, 8, 8], HabitualHour::EARLIEST_HOUR],
            'right on the ceiling' => [[21, 21, 21, 21, 21], HabitualHour::LATEST_HOUR],
            'comfortably inside'   => [[13, 12, 13, 14, 13], 13],
        ];
    }

    public function test_no_reachable_input_escapes_the_window(): void
    {
        for ($hour = 0; $hour <= 23; $hour++) {
            $computed = HabitualHour::fromDailyHours(array_fill(0, HabitualHour::MIN_SAMPLE_DAYS, $hour));

            $this->assertGreaterThanOrEqual(HabitualHour::EARLIEST_HOUR, $computed);
            $this->assertLessThanOrEqual(HabitualHour::LATEST_HOUR, $computed);
        }
    }

    /**
     * Median, not mean: the point of choosing it is that one outlier cannot
     * move it. The mean of the second list below is a full hour later than the
     * first, which is nobody's habit.
     */
    public function test_one_odd_night_does_not_move_the_estimate(): void
    {
        $steady = [8, 8, 9, 8, 9, 8];

        $this->assertSame(
            HabitualHour::fromDailyHours($steady),
            HabitualHour::fromDailyHours([...$steady, 3]),
            'A single 3am entry moved the estimate — this is a mean, not a median.'
        );
    }

    public function test_an_even_sample_takes_the_earlier_of_the_two_middle_hours(): void
    {
        // A reminder half an hour early still arrives before the user logs; one
        // half an hour late arrives after, when it is suppressed anyway.
        $this->assertSame(9, HabitualHour::fromDailyHours([8, 9, 10, 11, 9, 10]));
    }

    /**
     * The read-side guard. A value the class could not have produced is
     * disbelieved rather than clamped, so a hand-edited row falls back to the
     * default instead of silently becoming 08:00.
     */
    #[DataProvider('sendTimeProvider')]
    public function test_send_time_falls_back_to_the_default(?int $stored, array $expected): void
    {
        $this->assertSame($expected, HabitualHour::sendTime($stored, 18, 30));
    }

    public static function sendTimeProvider(): array
    {
        return [
            'nothing learned'         => [null, [18, 30]],
            'learned, inside window'  => [9,    [9, 0]],
            'learned, on the floor'   => [8,    [8, 0]],
            'learned, on the ceiling' => [21,   [21, 0]],
            'below the floor'         => [3,    [18, 30]],
            'above the ceiling'       => [23,   [18, 30]],
            'impossible hour'         => [99,   [18, 30]],
        ];
    }

    /**
     * The default's minutes survive, and a learned hour means the hour exactly.
     * Getting this wrong moves the workout reminder from 18:30 to 18:00 for
     * every user in the table, which is the sort of change nobody reports and
     * everybody notices.
     */
    public function test_a_learned_hour_is_on_the_hour_and_the_default_is_not(): void
    {
        $this->assertSame([18, 30], HabitualHour::sendTime(null, 18, 30));
        $this->assertSame([18, 0], HabitualHour::sendTime(18, 18, 30));
        $this->assertSame([10, 0], HabitualHour::sendTime(null, 10, 0));
    }
}
