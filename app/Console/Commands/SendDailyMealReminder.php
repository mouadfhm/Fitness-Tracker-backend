<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use App\Services\EngagementService;
use App\Services\HabitualHour;
use App\Services\NotificationContentService;
use App\Services\NotificationService;
use App\Services\ReminderWindow;
use App\Models\NotificationLog;
use App\Models\User;

class SendDailyMealReminder extends Command
{
    protected $signature = 'send:daily-meal-reminder';
    protected $description = 'Send meal logging reminders to users whose local time is their habitual logging hour';

    // The copy no longer lives here. Every reminder used to send one fixed pair
    // of sentences, which is why they were constants; now NotificationContentService
    // decides per user and this command only decides *who*. The old text is still
    // the fallback — see that class — so a user with nothing notable about them
    // receives exactly what they received yesterday.

    // 10:00 on the user's own clock, not on the server's, and only for users
    // we have learned nothing about. Someone who logs breakfast at 07:30 every
    // day has either already logged by 10:00 — in which case the check below
    // suppresses this and they hear nothing — or forgot two hours ago; they get
    // it at their own hour instead, from users.preferred_meal_hour. See
    // App\Services\HabitualHour and the weekly command that fills that column.
    //
    // The scheduler runs this every 30 minutes and ReminderWindow picks out
    // whose turn it is.
    private const DEFAULT_HOUR   = 10;
    private const DEFAULT_MINUTE = 0;

    public function handle()
    {
        $notificationService = new NotificationService();
        $engagement = new EngagementService();
        $content = new NotificationContentService();

        // One reading of the clock for the whole run. Calling now() per user
        // would let a slow batch drift across the window boundary, sending some
        // users' reminders on this run and then again on the next.
        $now = Carbon::now();

        // Collected rather than sent one at a time, then handed to
        // sendPersonalized in a single call. 600 due users cost two FCM requests
        // instead of 600, and personalized copy costs the same as generic copy
        // because sendAll() carries a separate payload per message anyway.
        // Accumulated across chunks on purpose: batching per chunk would let a
        // chunk that happens to hold 40 due users spend a whole request on 40
        // messages.
        $due = [];

        // user id => why they were held back. Collected rather than logged in
        // the loop so that the skipped rows can carry the copy the user would
        // actually have received, which needs the facts below to be loaded first.
        $backedOff = [];

        // Chunked, and no longer pre-filtered in SQL. "Has not logged today"
        // depends on where the user is, so it cannot be a whereDoesntHave on one
        // server-side date any more — see hasLoggedToday below.
        User::query()->chunkById(500, function ($users) use ($engagement, $now, &$due, &$backedOff) {
            foreach ($users as $user) {
                // Their hour if we have one, 10:00 if not.
                //
                // Nothing here guards against a user being caught by two
                // different windows on the day their hour moves — the backoff
                // below already does, and it is the only check that can: it
                // refuses to send a second reminder of a type the user has
                // already had today, whatever hour the first one went out at.
                // Spec 07 asks for an explicit "already sent today" check "if
                // the once-per-day-per-type check from spec 02 is not present";
                // it is present, so a second one would be the same query run
                // twice.
                [$hour, $minute] = HabitualHour::sendTime(
                    $user->preferred_meal_hour,
                    self::DEFAULT_HOUR,
                    self::DEFAULT_MINUTE
                );

                if (!ReminderWindow::isDue($user->localNow($now), $hour, $minute)) {
                    continue;
                }

                if ($this->hasLoggedToday($user, $now)) {
                    continue;
                }

                // Not logging today makes someone a candidate; it does not make
                // them a target. Someone who has been away three weeks hears from
                // us every third day, not every morning.
                if (!$engagement->dueForReminder($user->id, NotificationLog::TYPE_MEAL_REMINDER)) {
                    $backedOff[$user->id] = 'Engagement backoff: '
                        . EngagementService::daysInactive($user->last_engaged_at) . ' days inactive';
                    continue;
                }

                $due[] = $user->id;
            }
        });

        // One set of grouped queries for everyone this run will say anything
        // about, held back or not, before a single line of copy is composed.
        // Asking per user inside the loop above is the whole thing this is here
        // to avoid: it is invisible at today's size and it is what makes the
        // command time out at ten times it.
        $content->prime(array_merge($due, array_keys($backedOff)), $now);

        foreach ($backedOff as $userId => $reason) {
            $copy = $content->forUser($userId, NotificationLog::TYPE_MEAL_REMINDER);

            $notificationService->logSkipped(
                $userId,
                $copy['title'],
                $copy['body'],
                NotificationLog::TYPE_MEAL_REMINDER,
                $reason
            );
        }

        $copyByUser = [];

        foreach ($due as $userId) {
            $copyByUser[$userId] = $content->forUser($userId, NotificationLog::TYPE_MEAL_REMINDER);
        }

        // Preferences, quiet hours and missing devices are still decided per
        // user — inside sendPersonalized, where every other sender already gets
        // them.
        $result = $notificationService->sendPersonalized($copyByUser, NotificationLog::TYPE_MEAL_REMINDER);

        $skipped = count($backedOff);

        $this->info(
            "Meal reminders: {$result['sent']} sent, {$result['failed']} failed, " .
            "{$skipped} held back by engagement backoff, {$result['skipped']} suppressed."
        );
    }

    /**
     * Has the user logged a meal on the day it currently is where they are?
     *
     * created_at is stored in UTC, so their local midnights are converted before
     * being compared against it. Reusing the old whereDate(..., today()) would
     * ask "did they log on the UTC date", which is a different day entirely for
     * anyone far enough east or west — a user in Auckland would get their 10:00
     * reminder judged against the previous day's meals.
     */
    private function hasLoggedToday(User $user, Carbon $now): bool
    {
        $localNow = $user->localNow($now);

        return $user->meals()
            ->whereBetween('created_at', [
                $localNow->copy()->startOfDay()->utc(),
                $localNow->copy()->endOfDay()->utc(),
            ])
            ->exists();
    }
}
