<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides what a reminder actually says.
 *
 * Every reminder used to send the same two sentences to everybody, which is the
 * fastest way to teach a user that the notification is never worth opening. The
 * app already stores enough to say something specific — what they ate today
 * against what they were aiming for, how their training week compares to their
 * best one, which way the scale has moved — and this is the one place that
 * turns any of that into text.
 *
 * ## Two halves, deliberately
 *
 * `compose()` is static, pure, and takes a NotificationFacts. Every rule below
 * can therefore be tested by constructing the state it fires on, with no
 * database and no Firebase. `prime()` is the other half: it turns a list of user
 * ids into those facts using a fixed number of grouped queries, whatever the
 * length of the list. Per-user queries inside the reminder loop are what turn a
 * command that finishes in seconds into one that times out at ten times the user
 * base, and the failure shows up as "reminders stopped going out" rather than as
 * anything pointing at this file.
 *
 * ## The fallback is not optional
 *
 * Most users, most days, have nothing notable about them: a new account has no
 * weigh-ins, no training history and often no height to compute a goal from.
 * Every rule here returns null in that case and the generic copy — the exact
 * text these reminders have always sent — goes out instead. Personalization that
 * cannot find a fact must still send a sensible sentence, not an empty one.
 *
 * ## A note on the calorie rule
 *
 * "You're 480 kcal under your goal — still time to log dinner" is the spec's
 * headline example and it will not fire today. It is evening copy, and the only
 * meal reminder in the app goes out at 10:00 local, where "under your goal" is
 * trivially true and says nothing. The rule is implemented and tested against
 * the hour rather than against the current schedule, so it starts working on its
 * own the day spec 07 moves a user's meal reminder into their evening. Sending a
 * food message on the 18:30 workout reminder instead was the alternative, and it
 * was rejected: it lands the tap on the wrong screen and mixes the two open
 * rates that specs 01 and 02 read.
 */
class NotificationContentService
{
    /**
     * When "still time to log dinner" is a true statement. Half-open: 22:00 is
     * already the default start of quiet hours.
     */
    public const EVENING_FROM_HOUR  = 17;
    public const EVENING_UNTIL_HOUR = 22;

    /**
     * How large a calorie gap is worth naming.
     *
     * Below 300 the number is inside the noise of how anybody logs food and
     * reads as pedantic. There is no upper bound because the rule already
     * requires the user to have logged *something* today: without that, the
     * message would fire on a full day's allowance and tell someone who has
     * logged nothing that they are 2,400 kcal short of eating.
     */
    private const MIN_CALORIE_GAP = 300;

    /**
     * The training window the copy talks about, and how far back its record is
     * looked for.
     *
     * The record is the best seven-day stretch in the last quarter rather than
     * the best ever. A year-old peak from a fitter season is not a target, it is
     * a discouragement, and an unbounded lookback also makes this the one query
     * here that grows with the age of the account.
     */
    private const TRAINING_WINDOW_DAYS   = 7;
    private const TRAINING_LOOKBACK_DAYS = 91;

    /**
     * Below three days in a week there is no record worth chasing — "one more
     * matches your record of 2" is a joke at the user's expense.
     */
    private const MIN_RECORD_DAYS = 3;

    /**
     * Weigh-ins: how far back to look, how far apart two of them must be before
     * the difference means anything, and how much movement is worth a sentence.
     *
     * Seven days because daily weight swings a kilo on water alone; half a kilo
     * because anything less is the scale rather than the person.
     */
    private const WEIGHT_WINDOW_DAYS   = 31;
    private const MIN_WEIGHT_SPAN_DAYS = 7;
    private const MIN_WEIGHT_CHANGE_KG = 0.5;

    /**
     * Above this many days, "this month" is a fair description of the span.
     */
    private const MONTHISH_DAYS = 21;

    /**
     * The copy every reminder sent before this class existed.
     *
     * Kept verbatim, including the emoji and the exclamation marks. This is what
     * most users will still receive, so it is the app's ordinary voice rather
     * than a degraded mode, and changing it is a separate decision from adding
     * personalization on top of it.
     */
    private const FALLBACK = [
        NotificationLog::TYPE_MEAL_REMINDER => [
            'title' => "🍽 Time for Your First Meal of The Day!",
            'body'  => "Don't forget to follow your diet!",
            'route' => 'foods',
        ],
        NotificationLog::TYPE_WORKOUT_REMINDER => [
            'title' => "🏋️ Time for Your Workout!",
            'body'  => "Don't forget to exercise today and stay fit!",
            'route' => 'workouts',
        ],
    ];

    /**
     * Last resort for a type this class was never taught. Unreachable from the
     * two commands, and here so that a future caller gets a dull sentence rather
     * than an exception thrown inside a scheduled batch.
     */
    private const NEUTRAL = [
        'title' => 'Fitness Tracker',
        'body'  => 'Open the app to keep your log up to date.',
        'route' => 'home',
    ];

    /**
     * userId => facts. Also the record of which users have been primed: an id
     * present here is never queried again, which is what keeps forUser() free.
     *
     * @var array<int,NotificationFacts>
     */
    private array $facts = [];

    /**
     * Work out, for every one of these users at once, what there is to say.
     *
     * Five queries — the users, their calories, their workouts, their v2 workout
     * logs, their weigh-ins — regardless of whether the list holds one user or
     * ten thousand. The `whereIn`s are deliberately not chunked: chunking would
     * make the query count grow with the user base, which is the one property
     * this method exists to have. The lists come from a single 30-minute
     * reminder window, so they are a slice of the user base rather than all of
     * it.
     *
     * @param int[] $userIds
     */
    public function prime(array $userIds, ?Carbon $now = null): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds === []) {
            return;
        }

        $now ??= Carbon::now();

        // Seeded before anything can throw. If the loading below fails, these
        // users keep an empty fact set and receive the generic copy — and,
        // crucially, forUser() sees them as primed and does not fall back to
        // querying one user at a time, which is how a transient database error
        // would otherwise become the slow command this class is here to avoid.
        foreach ($userIds as $userId) {
            $this->facts[$userId] = NotificationFacts::none();
        }

        try {
            $users = User::whereIn('id', $userIds)->get();

            // A day either side of the server's date covers every user's local
            // date: the tz database spans -12 to +14, so no user's "today" can
            // be further than one calendar day from the server's.
            $to        = $now->copy()->addDay()->toDateString();
            $mealsFrom = $now->copy()->subDay()->toDateString();
            $trainFrom = $now->copy()->subDays(self::TRAINING_LOOKBACK_DAYS)->toDateString();
            $weighFrom = $now->copy()->subDays(self::WEIGHT_WINDOW_DAYS)->toDateString();

            $calories = $this->caloriesByUserAndDate($userIds, $mealsFrom, $to);
            [$burned, $trainingDates] = $this->trainingByUser($userIds, $trainFrom, $to);
            $weighIns = $this->weighInsByUser($userIds, $weighFrom, $to);

            foreach ($users as $user) {
                $this->facts[$user->id] = $this->factsFor(
                    $user,
                    $now,
                    $calories,
                    $burned,
                    $trainingDates[$user->id] ?? [],
                    $weighIns[$user->id] ?? []
                );
            }
        } catch (Throwable $e) {
            // Copy is decoration on top of a notification that still needs to go
            // out. Everyone keeps the generic text seeded above.
            Log::error('Could not load notification content facts', [
                'users' => count($userIds),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The title, body and destination screen for one user.
     *
     * Priming first is what makes this free; calling it unprimed is supported —
     * it primes that one user — because the single-send paths have no batch to
     * prime from and one user is still a constant number of queries.
     *
     * @param string $type One of the NotificationLog::TYPE_* constants.
     * @return array{title:string,body:string,route:string}
     */
    public function forUser(int $userId, string $type): array
    {
        if (!array_key_exists($userId, $this->facts)) {
            $this->prime([$userId]);
        }

        return self::compose($type, $this->facts[$userId] ?? NotificationFacts::none());
    }

    /**
     * Every copy decision in the app, in one pure function.
     *
     * Ordered most specific first. A user who is both mid-record and under their
     * calorie goal hears about the thing the notification they are getting is
     * actually about.
     *
     * @return array{title:string,body:string,route:string}
     */
    public static function compose(string $type, NotificationFacts $facts): array
    {
        $personal = match ($type) {
            NotificationLog::TYPE_MEAL_REMINDER    => self::calorieGapCopy($facts) ?? self::weighInCopy($facts),
            NotificationLog::TYPE_WORKOUT_REMINDER => self::recordInReachCopy($facts),
            default => null,
        };

        return $personal ?? self::FALLBACK[$type] ?? self::NEUTRAL;
    }

    /**
     * "You're 480 kcal under your goal — still time to log dinner"
     *
     * Requires the user to have logged something already. Someone who has logged
     * nothing today is not 2,000 kcal under their goal in any sense they would
     * recognise; they simply have not opened the app, which is what the generic
     * reminder is for.
     *
     * @return array{title:string,body:string,route:string}|null
     */
    private static function calorieGapCopy(NotificationFacts $facts): ?array
    {
        if (!self::isEvening($facts)) {
            return null;
        }

        if ($facts->caloriesEaten <= 0) {
            return null;
        }

        $remaining = $facts->caloriesRemaining();

        if ($remaining === null || $remaining < self::MIN_CALORIE_GAP) {
            return null;
        }

        $kcal = (int) round($remaining);

        return [
            'title' => '🍽 Still room on today’s goal',
            'body'  => "You're {$kcal} kcal under your goal — still time to log dinner.",
            'route' => 'foods',
        ];
    }

    /**
     * "Down 1.2 kg this month — log today's weigh-in"
     *
     * Only fires when the scale has moved the way the user asked it to. On
     * `maintenance`, and on an account that never picked a goal, movement is not
     * news worth congratulating and the rule stays quiet — an app that cheers a
     * gain at someone maintaining, or a loss at someone bulking, is worse than
     * one that says nothing.
     *
     * @return array{title:string,body:string,route:string}|null
     */
    private static function weighInCopy(NotificationFacts $facts): ?array
    {
        // Nothing to ask for: they have already stood on the scale today.
        if ($facts->weighedInToday) {
            return null;
        }

        if ($facts->weightChangeKg === null || $facts->weightSpanDays === null) {
            return null;
        }

        if ($facts->weightSpanDays < self::MIN_WEIGHT_SPAN_DAYS) {
            return null;
        }

        $change = $facts->weightChangeKg;

        if (abs($change) < self::MIN_WEIGHT_CHANGE_KG) {
            return null;
        }

        $towardsGoal = match ($facts->fitnessGoal) {
            'weight_loss' => $change < 0,
            'muscle_gain' => $change > 0,
            default       => false,
        };

        if (!$towardsGoal) {
            return null;
        }

        $direction = $change < 0 ? 'Down' : 'Up';
        $amount    = self::formatKg(abs($change));
        $span      = $facts->weightSpanDays >= self::MONTHISH_DAYS
            ? 'this month'
            : "in {$facts->weightSpanDays} days";

        return [
            'title' => "⚖️ {$direction} {$amount} kg {$span}",
            'body'  => "Log today's weigh-in to keep the trend honest.",
            'route' => 'profile',
        ];
    }

    /**
     * "You logged 4 workouts last week. One more today matches your record."
     *
     * Counted in training *days* rather than sessions, and that is not a
     * paraphrase of the spec so much as the only version that survives contact
     * with the data: completed workouts land in the v1 `workouts` table today
     * and in `workout_logs` once v2 logging is switched on, so counting rows
     * would start double-counting on the day both are written. A day the user
     * trained is a day the user trained however many tables say so.
     *
     * The reminder only reaches users who have not trained today (the commands
     * check that first), so "one more today" is always a claim about a day still
     * open.
     *
     * @return array{title:string,body:string,route:string}|null
     */
    private static function recordInReachCopy(NotificationFacts $facts): ?array
    {
        $recent = $facts->daysTrainedRecently;
        $record = $facts->bestDaysTrained;

        if ($recent === null || $record === null) {
            return null;
        }

        // No history worth chasing, or nothing already going this week to build
        // on. "One more matches your record" needs both ends to be true.
        if ($record < self::MIN_RECORD_DAYS || $recent < 1) {
            return null;
        }

        if ($recent + 1 < $record) {
            return null;
        }

        // The record is the best of every seven-day stretch, including the one
        // just counted, so `recent` can equal it but never beat it. Equality
        // means today would set a new one.
        if ($recent + 1 === $record) {
            return [
                'title' => '🏋️ One workout from your record',
                'body'  => "You've trained {$recent} days this week. One more today matches your record of {$record}.",
                'route' => 'workouts',
            ];
        }

        return [
            'title' => '🏋️ A new record is one workout away',
            'body'  => "You've trained {$recent} days this week — already your best. Train today and it's a new record.",
            'route' => 'workouts',
        ];
    }

    private static function isEvening(NotificationFacts $facts): bool
    {
        return $facts->localHour >= self::EVENING_FROM_HOUR
            && $facts->localHour < self::EVENING_UNTIL_HOUR;
    }

    /**
     * One decimal, and no trailing ".0" — "Down 1 kg" reads like a person wrote
     * it and "Down 1.0 kg" reads like a spreadsheet did.
     */
    private static function formatKg(float $kg): string
    {
        $rounded = round($kg, 1);

        return rtrim(rtrim(number_format($rounded, 1, '.', ''), '0'), '.');
    }

    /**
     * Calories logged, per user per calendar date.
     *
     * The same arithmetic MealController::totalMacros does per request, done
     * once in SQL for everybody: quantity is grams and the food columns are per
     * 100g.
     *
     * @param int[] $userIds
     * @return array<string,float> "userId|Y-m-d" => calories
     */
    private function caloriesByUserAndDate(array $userIds, string $from, string $to): array
    {
        $rows = DB::table('meals')
            ->join('food_meal', 'food_meal.meal_id', '=', 'meals.id')
            ->join('foods', 'foods.id', '=', 'food_meal.food_id')
            ->whereIn('meals.user_id', $userIds)
            ->whereBetween('meals.date', [$from, $to])
            ->groupBy('meals.user_id', 'meals.date')
            ->selectRaw('meals.user_id as user_id, meals.date as date, SUM(foods.calories * food_meal.quantity / 100) as calories')
            ->get();

        $calories = [];

        foreach ($rows as $row) {
            $calories[self::key((int) $row->user_id, $row->date)] = (float) $row->calories;
        }

        return $calories;
    }

    /**
     * Calories burned per user per date, and the set of dates each user trained.
     *
     * Two queries rather than one because the two workout tables are not
     * interchangeable. `workouts` (v1) is what the app writes today and what the
     * calorie goal has always been adjusted by, so the burn comes from there
     * alone — pulling in v2 rows would move every user's goal the day v2 logging
     * starts. Training *days* come from both, because the record should not
     * reset itself when the app switches tables.
     *
     * @param int[] $userIds
     * @return array{0:array<string,float>,1:array<int,string[]>} burned by "userId|date", dates by user id
     */
    private function trainingByUser(array $userIds, string $from, string $to): array
    {
        $burned = [];
        $dates  = [];

        $workouts = DB::table('workouts')
            ->whereIn('user_id', $userIds)
            ->whereBetween('workout_date', [$from, $to])
            ->groupBy('user_id', 'workout_date')
            ->selectRaw('user_id, workout_date, SUM(calories_burned) as burned')
            ->get();

        foreach ($workouts as $row) {
            $userId = (int) $row->user_id;

            $burned[self::key($userId, $row->workout_date)] = (float) $row->burned;
            $dates[$userId][] = self::date($row->workout_date);
        }

        $logs = DB::table('workout_logs')
            ->whereIn('user_id', $userIds)
            ->whereBetween('workout_date', [$from, $to])
            ->groupBy('user_id', 'workout_date')
            ->select('user_id', 'workout_date')
            ->get();

        foreach ($logs as $row) {
            $dates[(int) $row->user_id][] = self::date($row->workout_date);
        }

        return [$burned, $dates];
    }

    /**
     * Weigh-ins per user, oldest first.
     *
     * Rows rather than an aggregate: the trend needs the first and last weights
     * in the window and the dates they were taken on, which no single SQL
     * aggregate gives back together. Bounded by WEIGHT_WINDOW_DAYS, so this is
     * at most a month of rows per user however long the account has existed.
     *
     * @param int[] $userIds
     * @return array<int,array<int,array{date:string,weight:float}>>
     */
    private function weighInsByUser(array $userIds, string $from, string $to): array
    {
        $rows = DB::table('progresses')
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from, $to])
            ->orderBy('user_id')
            ->orderBy('date')
            ->select('user_id', 'date', 'weight')
            ->get();

        $weighIns = [];

        foreach ($rows as $row) {
            $weighIns[(int) $row->user_id][] = [
                'date'   => self::date($row->date),
                'weight' => (float) $row->weight,
            ];
        }

        return $weighIns;
    }

    /**
     * @param array<string,float> $calories
     * @param array<string,float> $burned
     * @param string[] $trainingDates
     * @param array<int,array{date:string,weight:float}> $weighIns
     */
    private function factsFor(
        User $user,
        Carbon $now,
        array $calories,
        array $burned,
        array $trainingDates,
        array $weighIns
    ): NotificationFacts {
        // Everything below is asked as of the day it is where the user is, not
        // where the server is. Their 18:30 is the server's anything.
        $localNow = $user->localNow($now);
        $today    = $localNow->toDateString();

        $burnedToday = $burned[self::key($user->id, $today)] ?? 0.0;

        [$recent, $record] = self::trainingCounts($trainingDates, $today);
        [$change, $span, $weighedToday] = self::weightTrend($weighIns, $today);

        return new NotificationFacts(
            localHour: $localNow->hour,
            calorieTarget: $user->dailyCalorieTarget($burnedToday),
            caloriesEaten: $calories[self::key($user->id, $today)] ?? 0.0,
            daysTrainedRecently: $recent,
            bestDaysTrained: $record,
            weightChangeKg: $change,
            weightSpanDays: $span,
            weighedInToday: $weighedToday,
            fitnessGoal: $user->fitness_goal,
        );
    }

    /**
     * Days trained in the last week, and the best any week in the window managed.
     *
     * The record is a sliding seven-day window rather than a calendar week: a
     * user who trains Thursday to Monday has done five days in a week by any
     * reading a person would accept, and calendar weeks would score it as three
     * and two.
     *
     * @param string[] $dates Training dates, any order, duplicates allowed.
     * @return array{0:int,1:int} days trained recently, best week
     */
    private static function trainingCounts(array $dates, string $today): array
    {
        $todayTs = self::timestamp($today);
        $window  = (self::TRAINING_WINDOW_DAYS - 1) * 86400;

        $stamps = [];

        foreach (array_unique($dates) as $date) {
            $ts = self::timestamp($date);

            // A future-dated row is a client clock or a typo, and counting one
            // would inflate both numbers.
            if ($ts <= $todayTs) {
                $stamps[] = $ts;
            }
        }

        if ($stamps === []) {
            return [0, 0];
        }

        sort($stamps);

        $recent = 0;

        foreach ($stamps as $ts) {
            if ($ts >= $todayTs - $window) {
                $recent++;
            }
        }

        // Two pointers over the sorted days: for each day, how many days fall
        // inside the seven-day window ending on it. Linear, where the obvious
        // nested loop is quadratic in a quarter's worth of dates times every
        // user in the batch.
        $record = 0;
        $left   = 0;

        foreach ($stamps as $right => $ts) {
            while ($ts - $stamps[$left] > $window) {
                $left++;
            }

            $record = max($record, $right - $left + 1);
        }

        return [$recent, $record];
    }

    /**
     * Movement between the oldest and newest weigh-in in the window.
     *
     * @param array<int,array{date:string,weight:float}> $weighIns Oldest first.
     * @return array{0:float|null,1:int|null,2:bool} change in kg, days spanned, weighed in today
     */
    private static function weightTrend(array $weighIns, string $today): array
    {
        $todayTs = self::timestamp($today);

        $past = array_values(array_filter(
            $weighIns,
            fn (array $row) => self::timestamp($row['date']) <= $todayTs
        ));

        $weighedToday = false;

        foreach ($past as $row) {
            if ($row['date'] === $today) {
                $weighedToday = true;
                break;
            }
        }

        if (count($past) < 2) {
            return [null, null, $weighedToday];
        }

        $first = $past[0];
        $last  = $past[count($past) - 1];

        $span = (int) round((self::timestamp($last['date']) - self::timestamp($first['date'])) / 86400);

        return [$last['weight'] - $first['weight'], $span, $weighedToday];
    }

    /**
     * Midnight UTC for a date string, so that differences between two of them
     * are exact multiples of a day. Parsing in a zone that observes DST would
     * make two dates 23 or 25 hours apart twice a year, which is enough to move
     * a span across the seven-day threshold.
     */
    private static function timestamp(string $date): int
    {
        return (int) strtotime(self::date($date) . ' 00:00:00 UTC');
    }

    /**
     * Date columns come back as 'Y-m-d' on some drivers and 'Y-m-d H:i:s' on
     * others. Only the day half is ever compared.
     */
    private static function date(string $value): string
    {
        return substr($value, 0, 10);
    }

    private static function key(int $userId, string $date): string
    {
        return $userId . '|' . self::date($date);
    }
}
