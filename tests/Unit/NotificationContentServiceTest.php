<?php

namespace Tests\Unit;

use App\Models\NotificationLog;
use App\Services\NotificationContentService;
use App\Services\NotificationFacts;
use PHPUnit\Framework\TestCase;

/**
 * The copy rules, exercised directly.
 *
 * Deliberately extends PHPUnit's TestCase, not Laravel's, exactly as
 * ReminderWindowTest does: compose() is a pure function of a NotificationFacts,
 * and keeping this suite runnable without a database is the payoff for having
 * split the rules away from the queries that feed them.
 *
 * What is being defended here is not the wording. It is that a rule fires only
 * on the state it claims to describe — a reminder that tells someone they are
 * 480 kcal short when they are not, or congratulates a loss at a user who is
 * bulking, is worse for trust than the generic copy it replaced.
 */
class NotificationContentServiceTest extends TestCase
{
    private const GENERIC_MEAL_TITLE    = "🍽 Time for Your First Meal of The Day!";
    private const GENERIC_WORKOUT_TITLE = "🏋️ Time for Your Workout!";

    // ---------------------------------------------------------------- fallback

    /**
     * The acceptance criterion the whole feature rests on: a user we know
     * nothing about still gets a sensible notification, not an empty one.
     */
    public function test_a_user_with_no_notable_facts_gets_the_generic_meal_reminder(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            NotificationFacts::none(10)
        );

        $this->assertSame(self::GENERIC_MEAL_TITLE, $copy['title']);
        $this->assertSame("Don't forget to follow your diet!", $copy['body']);
        $this->assertSame('foods', $copy['route']);
    }

    public function test_a_user_with_no_notable_facts_gets_the_generic_workout_reminder(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_WORKOUT_REMINDER,
            NotificationFacts::none(18)
        );

        $this->assertSame(self::GENERIC_WORKOUT_TITLE, $copy['title']);
        $this->assertSame('workouts', $copy['route']);
    }

    /**
     * A type this class was never taught must not throw inside a scheduled
     * batch, where the exception would take every remaining user with it.
     */
    public function test_an_unknown_type_still_produces_copy(): void
    {
        $copy = NotificationContentService::compose('something_new', NotificationFacts::none());

        $this->assertNotSame('', $copy['title']);
        $this->assertNotSame('', $copy['body']);
        $this->assertSame('home', $copy['route']);
    }

    /**
     * Two users in different states receive different copy — the spec's first
     * acceptance criterion, stated as directly as it can be.
     */
    public function test_two_users_in_different_states_get_different_copy(): void
    {
        $onARun = NotificationContentService::compose(
            NotificationLog::TYPE_WORKOUT_REMINDER,
            new NotificationFacts(localHour: 18, daysTrainedRecently: 4, bestDaysTrained: 5)
        );

        $newAccount = NotificationContentService::compose(
            NotificationLog::TYPE_WORKOUT_REMINDER,
            NotificationFacts::none(18)
        );

        $this->assertNotSame($onARun['title'], $newAccount['title']);
        $this->assertNotSame($onARun['body'], $newAccount['body']);
    }

    // ------------------------------------------------------------ calorie gap

    public function test_the_calorie_gap_is_named_in_the_evening(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(localHour: 18, calorieTarget: 2200.0, caloriesEaten: 1720.0)
        );

        $this->assertStringContainsString('480 kcal under your goal', $copy['body']);
        $this->assertSame('foods', $copy['route']);
    }

    /**
     * The same user at 10:00 — which is when the only meal reminder in the app
     * currently goes out. "You are under your goal" in the morning is true of
     * everybody and worth saying to nobody.
     */
    public function test_the_calorie_gap_is_not_named_in_the_morning(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(localHour: 10, calorieTarget: 2200.0, caloriesEaten: 1720.0)
        );

        $this->assertSame(self::GENERIC_MEAL_TITLE, $copy['title']);
    }

    /**
     * Nothing logged today is not the same as being short of food, and telling
     * someone they are 2,200 kcal under is how a personalized reminder gets
     * turned off for good.
     */
    public function test_the_calorie_gap_needs_something_logged_first(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(localHour: 18, calorieTarget: 2200.0, caloriesEaten: 0.0)
        );

        $this->assertSame(self::GENERIC_MEAL_TITLE, $copy['title']);
    }

    public function test_a_small_calorie_gap_is_not_worth_a_sentence(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(localHour: 18, calorieTarget: 2200.0, caloriesEaten: 2050.0)
        );

        $this->assertSame(self::GENERIC_MEAL_TITLE, $copy['title']);
    }

    /**
     * An incomplete profile has no goal to be under.
     */
    public function test_no_calorie_target_means_no_calorie_copy(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(localHour: 18, calorieTarget: null, caloriesEaten: 1200.0)
        );

        $this->assertSame(self::GENERIC_MEAL_TITLE, $copy['title']);
    }

    // -------------------------------------------------------- training record

    public function test_a_record_one_workout_away_is_named(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_WORKOUT_REMINDER,
            new NotificationFacts(localHour: 18, daysTrainedRecently: 4, bestDaysTrained: 5)
        );

        $this->assertStringContainsString('4 days this week', $copy['body']);
        $this->assertStringContainsString('matches your record of 5', $copy['body']);
        $this->assertSame('workouts', $copy['route']);
    }

    /**
     * Already level with the best week in the window: today would beat it, and
     * the copy has to say so rather than claim a tie.
     */
    public function test_matching_the_record_already_promises_a_new_one(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_WORKOUT_REMINDER,
            new NotificationFacts(localHour: 18, daysTrainedRecently: 5, bestDaysTrained: 5)
        );

        $this->assertStringContainsString('new record', $copy['body']);
    }

    public function test_a_record_out_of_reach_is_not_mentioned(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_WORKOUT_REMINDER,
            new NotificationFacts(localHour: 18, daysTrainedRecently: 1, bestDaysTrained: 6)
        );

        $this->assertSame(self::GENERIC_WORKOUT_TITLE, $copy['title']);
    }

    /**
     * "One more matches your record of 2" is a joke at the user's expense.
     */
    public function test_a_trivial_record_is_not_worth_chasing(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_WORKOUT_REMINDER,
            new NotificationFacts(localHour: 18, daysTrainedRecently: 1, bestDaysTrained: 2)
        );

        $this->assertSame(self::GENERIC_WORKOUT_TITLE, $copy['title']);
    }

    /**
     * A record needs a week in progress to be within reach of. Someone who has
     * trained nothing this week is being told about a streak they do not have.
     */
    public function test_a_week_not_yet_started_gets_the_generic_reminder(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_WORKOUT_REMINDER,
            new NotificationFacts(localHour: 18, daysTrainedRecently: 0, bestDaysTrained: 3)
        );

        $this->assertSame(self::GENERIC_WORKOUT_TITLE, $copy['title']);
    }

    // --------------------------------------------------------------- weigh-in

    public function test_weight_progress_towards_a_loss_goal_is_named(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(
                localHour: 10,
                weightChangeKg: -1.2,
                weightSpanDays: 28,
                fitnessGoal: 'weight_loss',
            )
        );

        $this->assertSame('⚖️ Down 1.2 kg this month', $copy['title']);
        $this->assertStringContainsString("Log today's weigh-in", $copy['body']);

        // The nudge asks for a weigh-in, so the tap has to land on the screen
        // the weigh-in is behind — not on the foods screen this notification
        // type usually points at. This is the whole reason 06 depends on 03.
        $this->assertSame('profile', $copy['route']);
    }

    public function test_weight_gain_towards_a_muscle_goal_is_named(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(
                localHour: 10,
                weightChangeKg: 0.8,
                weightSpanDays: 24,
                fitnessGoal: 'muscle_gain',
            )
        );

        $this->assertSame('⚖️ Up 0.8 kg this month', $copy['title']);
    }

    /**
     * The scale moving is only good news relative to what the user asked for.
     */
    public function test_a_loss_is_not_celebrated_at_someone_bulking(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(
                localHour: 10,
                weightChangeKg: -1.2,
                weightSpanDays: 28,
                fitnessGoal: 'muscle_gain',
            )
        );

        $this->assertSame(self::GENERIC_MEAL_TITLE, $copy['title']);
    }

    public function test_movement_is_not_celebrated_at_someone_maintaining(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(
                localHour: 10,
                weightChangeKg: -1.2,
                weightSpanDays: 28,
                fitnessGoal: 'maintenance',
            )
        );

        $this->assertSame(self::GENERIC_MEAL_TITLE, $copy['title']);
    }

    /**
     * The message asks for a weigh-in. Asking someone who has already weighed in
     * today is the kind of small wrongness that makes the whole feature look
     * automated rather than attentive.
     */
    public function test_no_weigh_in_nudge_when_they_already_weighed_in_today(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(
                localHour: 10,
                weightChangeKg: -1.2,
                weightSpanDays: 28,
                weighedInToday: true,
                fitnessGoal: 'weight_loss',
            )
        );

        $this->assertSame(self::GENERIC_MEAL_TITLE, $copy['title']);
    }

    /**
     * Two weigh-ins three days apart differ by water, not by fat.
     */
    public function test_two_weigh_ins_too_close_together_prove_nothing(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(
                localHour: 10,
                weightChangeKg: -1.2,
                weightSpanDays: 3,
                fitnessGoal: 'weight_loss',
            )
        );

        $this->assertSame(self::GENERIC_MEAL_TITLE, $copy['title']);
    }

    public function test_movement_below_the_threshold_is_the_scale_not_the_person(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(
                localHour: 10,
                weightChangeKg: -0.3,
                weightSpanDays: 28,
                fitnessGoal: 'weight_loss',
            )
        );

        $this->assertSame(self::GENERIC_MEAL_TITLE, $copy['title']);
    }

    /**
     * A span shorter than a month must not be described as one.
     */
    public function test_a_short_span_is_described_in_days(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(
                localHour: 10,
                weightChangeKg: -0.9,
                weightSpanDays: 9,
                fitnessGoal: 'weight_loss',
            )
        );

        $this->assertSame('⚖️ Down 0.9 kg in 9 days', $copy['title']);
    }

    /**
     * "Down 1.0 kg" reads like a spreadsheet wrote it.
     */
    public function test_a_whole_number_of_kilos_loses_its_decimal(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(
                localHour: 10,
                weightChangeKg: -1.0,
                weightSpanDays: 28,
                fitnessGoal: 'weight_loss',
            )
        );

        $this->assertSame('⚖️ Down 1 kg this month', $copy['title']);
    }

    // --------------------------------------------------------------- priority

    /**
     * Both rules apply; the evening meal reminder is about food.
     */
    public function test_the_calorie_gap_outranks_the_weigh_in_nudge(): void
    {
        $copy = NotificationContentService::compose(
            NotificationLog::TYPE_MEAL_REMINDER,
            new NotificationFacts(
                localHour: 19,
                calorieTarget: 2200.0,
                caloriesEaten: 1500.0,
                weightChangeKg: -1.2,
                weightSpanDays: 28,
                fitnessGoal: 'weight_loss',
            )
        );

        $this->assertStringContainsString('kcal under your goal', $copy['body']);
    }
}
