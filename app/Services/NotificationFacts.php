<?php

namespace App\Services;

/**
 * Everything the copy rules are allowed to know about one user.
 *
 * A plain carrier with no logic beyond two conveniences, and that is the point:
 * NotificationContentService::compose() takes one of these and returns text, so
 * every rule in the app can be exercised by constructing the state it fires on
 * instead of by building a user, a month of meals and a Firebase double. The
 * expensive half — turning several thousand rows into one of these per user —
 * stays on the other side of that line, where it can be batched.
 *
 * Every field is nullable or has a "nothing known" default, because that is the
 * normal case rather than an error. A new account has no weigh-ins, no training
 * history and quite possibly no height; the rules read those nulls and fall
 * through to the generic copy.
 */
final class NotificationFacts
{
    /**
     * @param int         $localHour           Hour on the user's own clock, 0-23.
     * @param float|null  $calorieTarget       Today's goal, null on an incomplete profile.
     * @param float       $caloriesEaten       Logged so far on the user's local date.
     * @param int|null    $daysTrainedRecently Distinct training days in the last week.
     * @param int|null    $bestDaysTrained     Best any seven-day stretch has managed.
     * @param float|null  $weightChangeKg      Latest weigh-in minus the oldest in the window; negative is a loss.
     * @param int|null    $weightSpanDays      Days between those two weigh-ins.
     * @param bool        $weighedInToday      Whether asking for a weigh-in would be redundant.
     * @param string|null $fitnessGoal         weight_loss | muscle_gain | maintenance.
     */
    public function __construct(
        public readonly int $localHour = 12,
        public readonly ?float $calorieTarget = null,
        public readonly float $caloriesEaten = 0.0,
        public readonly ?int $daysTrainedRecently = null,
        public readonly ?int $bestDaysTrained = null,
        public readonly ?float $weightChangeKg = null,
        public readonly ?int $weightSpanDays = null,
        public readonly bool $weighedInToday = false,
        public readonly ?string $fitnessGoal = null,
    ) {}

    /**
     * A user we know nothing notable about. Named rather than assembled at each
     * call site so the fallback path in the tests reads as the thing it is.
     */
    public static function none(int $localHour = 12): self
    {
        return new self($localHour);
    }

    /**
     * Calories left against today's goal, or null when there is no goal to
     * measure against.
     *
     * Negative when the user is over, which no rule currently uses. Telling
     * someone they have overshot is a different product decision from nudging
     * them to log — it needs to be right about people who simply have not
     * finished logging yet, and at reminder time that is most of them.
     */
    public function caloriesRemaining(): ?float
    {
        if ($this->calorieTarget === null) {
            return null;
        }

        return $this->calorieTarget - $this->caloriesEaten;
    }
}
