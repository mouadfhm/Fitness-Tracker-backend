<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BMR, typical daily burn and the per-goal calorie target the Today screen and
 * the reminders both show.
 */
class CalorieTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(array $attributes = []): User
    {
        // BMR 1648.75: 700 + 1093.75 - 150 + 5
        return new User($attributes + [
            'weight' => 70, 'height' => 175, 'age' => 30,
            'gender' => 'male', 'activity_level' => 'moderate', 'fitness_goal' => 'maintenance',
        ]);
    }

    public function test_every_activity_level_the_app_offers_has_its_own_factor(): void
    {
        $expected = [
            'sedentary'   => 1.2,
            'light'       => 1.375,
            'moderate'    => 1.55,
            'active'      => 1.725,
            'very_active' => 1.9,
        ];

        foreach ($expected as $level => $factor) {
            $this->assertEqualsWithDelta(
                1648.75 * $factor,
                $this->user(['activity_level' => $level])->totalDailyEnergyExpenditure(),
                0.001,
                $level
            );
        }
    }

    public function test_old_app_capitalisation_still_counts(): void
    {
        $this->assertEqualsWithDelta(
            1648.75 * 1.55,
            $this->user(['activity_level' => 'Moderate'])->totalDailyEnergyExpenditure(),
            0.001
        );
    }

    public function test_unknown_or_missing_level_is_sedentary(): void
    {
        $this->assertEqualsWithDelta(1648.75 * 1.2, $this->user(['activity_level' => null])->totalDailyEnergyExpenditure(), 0.001);
    }

    public function test_gender_constants(): void
    {
        $this->assertEqualsWithDelta(1648.75 - 166, $this->user(['gender' => 'female'])->basalMetabolicRate(), 0.001);
        $this->assertEqualsWithDelta(1648.75 - 83, $this->user(['gender' => 'other'])->basalMetabolicRate(), 0.001);
        $this->assertEqualsWithDelta(1648.75 - 83, $this->user(['gender' => null])->basalMetabolicRate(), 0.001);
    }

    public function test_goal_adjustments_for_an_average_adult(): void
    {
        $tdee = 1648.75 * 1.55;

        $this->assertEqualsWithDelta($tdee, $this->user()->dailyCalorieTarget(), 0.001);
        $this->assertEqualsWithDelta($tdee - 500, $this->user(['fitness_goal' => 'weight_loss'])->dailyCalorieTarget(), 0.001);
        $this->assertEqualsWithDelta($tdee + 255.55625, $this->user(['fitness_goal' => 'muscle_gain'])->dailyCalorieTarget(), 0.001);
    }

    public function test_a_small_burn_gets_a_proportional_deficit(): void
    {
        // 60 kg, 165 cm, 30, female, sedentary: BMR 1320.25, burn 1584.3
        $user = new User([
            'weight' => 60, 'height' => 165, 'age' => 30, 'gender' => 'female',
            'activity_level' => 'sedentary', 'fitness_goal' => 'weight_loss',
        ]);

        $this->assertEqualsWithDelta(1584.3 * 0.8, $user->dailyCalorieTarget(), 0.001);
    }

    public function test_weight_loss_never_goes_below_the_floor(): void
    {
        // 50 kg, 155 cm, 45, female, sedentary: BMR 1082.75, burn 1299.3,
        // 20% off would be 1039.44
        $user = new User([
            'weight' => 50, 'height' => 155, 'age' => 45, 'gender' => 'female',
            'activity_level' => 'sedentary', 'fitness_goal' => 'weight_loss',
        ]);

        $this->assertEqualsWithDelta(1200, $user->dailyCalorieTarget(), 0.001);
    }

    public function test_the_floor_never_raises_a_target_above_the_burn(): void
    {
        // BMR 926.5, burn 1111.8: below the floor already, so no deficit at all
        $user = new User([
            'weight' => 45, 'height' => 150, 'age' => 60, 'gender' => 'female',
            'activity_level' => 'sedentary', 'fitness_goal' => 'weight_loss',
        ]);

        $this->assertEqualsWithDelta(1111.8, $user->dailyCalorieTarget(), 0.001);
    }

    public function test_incomplete_profile_has_no_target(): void
    {
        $this->assertNull((new User(['weight' => 70, 'height' => 175]))->dailyCalorieTarget());
        $this->assertNull((new User(['weight' => 70, 'height' => 175]))->totalDailyEnergyExpenditure());
    }
}
