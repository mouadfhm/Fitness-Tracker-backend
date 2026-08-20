<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Services\EngagementService;
use App\Services\HabitualHour;
use App\Services\LocalDayWindow;
use App\Services\NotificationContentService;
use App\Services\NotificationService;
use App\Services\ReminderWindow;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\Workout;

class SendDailyReminder extends Command
{
    protected $signature = 'send:daily-reminder';
    protected $description = 'Send workout reminders to users whose local time is their habitual training hour, on a weekday';

    // The copy no longer lives here — NotificationContentService decides it per
    // user, and this command only decides who. The old text survives as that
    // class's fallback, which is what most users still get.

    // 18:30 on the user's own clock, not on the server's, and only for users
    // whose own training hour we have not learned — see App\Services\HabitualHour
    // and users.preferred_workout_hour. The half hour is why the default is a
    // pair rather than an hour: 18:30 is where this reminder has always been and
    // must stay for everyone we know nothing about, while a learned hour is an
    // hour and means :00.
    //
    // The scheduler runs this every 30 minutes and ReminderWindow picks out
    // whose turn it is.
    private const DEFAULT_HOUR   = 18;
    private const DEFAULT_MINUTE = 30;

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
        // instead of 600, and per-user copy costs the same as identical copy
        // because sendAll() carries a separate payload per message anyway.
        // Accumulated across chunks on purpose: batching per chunk would let a
        // chunk that happens to hold 40 due users spend a whole request on 40
        // messages.
        $due = [];

        // user id => why they were held back, logged after the facts load so the
        // skipped rows carry the copy the user would actually have received.
        $backedOff = [];

        // Chunked, and no longer pre-filtered in SQL. Both "is it a weekday"
        // and "has not logged today" depend on where the user is, so neither
        // can be decided once for the whole batch any more.
        // Three passes over each chunk rather than one over each user. The order
        // of the checks is the same as when they were nested — window, weekday,
        // logged-today, engagement — so the same users are dropped at the same
        // points, and only those who reach the last one get a backoff row.
        User::query()->chunkById(500, function ($users) use ($engagement, $now, &$due, &$backedOff) {
            // Their hour if we have one, 18:30 if not, evaluated per user since
            // HabitualHour::sendTime depends on each user's own learned hour.
            // The once-per-day guarantee on the day a user's hour moves is the
            // engagement backoff below, which refuses a second send of a type
            // the user has already had today — see the same note in
            // SendDailyMealReminder.
            $candidates = $users->filter(function (User $user) use ($now) {
                $localNow = $user->localNow($now);

                [$hour, $minute] = HabitualHour::sendTime(
                    $user->preferred_workout_hour,
                    self::DEFAULT_HOUR,
                    self::DEFAULT_MINUTE
                );

                if (!ReminderWindow::isDue($localNow, $hour, $minute)) {
                    return false;
                }

                // Weekends are the user's weekend. The server's UTC Friday
                // evening is already Saturday in Auckland, and its Sunday
                // morning is still Saturday in Honolulu — the old server-side
                // check got both of those wrong.
                return !in_array($localNow->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY], true);
            });

            if ($candidates->isEmpty()) {
                return;
            }

            $loggedToday = $this->loggedTodayIds($candidates, $now);
            $candidates = $candidates->reject(fn (User $user) => isset($loggedToday[$user->id]));

            if ($candidates->isEmpty()) {
                return;
            }

            // Not logging today makes someone a candidate; it does not make
            // them a target. Someone who has been away three weeks hears from
            // us every third day, not every evening.
            $decisions = $engagement->dueForReminderMany($candidates, NotificationLog::TYPE_WORKOUT_REMINDER);

            foreach ($candidates as $user) {
                if (!($decisions[$user->id] ?? false)) {
                    $backedOff[$user->id] = 'Engagement backoff: '
                        . EngagementService::daysInactive($user->last_engaged_at) . ' days inactive';
                    continue;
                }

                $due[] = $user->id;
            }
        });

        // One set of grouped queries for everyone this run will say anything
        // about, held back or not, before a single line of copy is composed.
        // Asking per user inside the loop above is exactly what this avoids.
        $content->prime(array_merge($due, array_keys($backedOff)), $now);

        foreach ($backedOff as $userId => $reason) {
            $copy = $content->forUser($userId, NotificationLog::TYPE_WORKOUT_REMINDER);

            $notificationService->logSkipped(
                $userId,
                $copy['title'],
                $copy['body'],
                NotificationLog::TYPE_WORKOUT_REMINDER,
                $reason
            );
        }

        $copyByUser = [];

        foreach ($due as $userId) {
            $copyByUser[$userId] = $content->forUser($userId, NotificationLog::TYPE_WORKOUT_REMINDER);
        }

        // Preferences, quiet hours and missing devices are still decided per
        // user — inside sendPersonalized, where every other sender already gets
        // them.
        $result = $notificationService->sendPersonalized($copyByUser, NotificationLog::TYPE_WORKOUT_REMINDER);

        $skipped = count($backedOff);

        $this->info(
            "Workout reminders: {$result['sent']} sent, {$result['failed']} failed, " .
            "{$skipped} held back by engagement backoff, {$result['skipped']} suppressed."
        );
    }

    /**
     * Which of these users have logged a workout on the day it currently is
     * where they are?
     *
     * created_at is stored in UTC, so their local midnights are converted before
     * being compared against it. Reusing the old whereDate(..., today()) would
     * ask "did they log on the UTC date", which is a different day entirely for
     * anyone far enough east or west.
     *
     * One query per distinct local day rather than one `exists` per user; see
     * LocalDayWindow for why a chunk holds so few distinct days.
     *
     * @param  Collection<int,User> $candidates
     * @return array<int,true> keyed by user id, for isset() lookup
     */
    private function loggedTodayIds(Collection $candidates, Carbon $now): array
    {
        $logged = [];

        foreach (LocalDayWindow::group($candidates, $now) as $window) {
            $ids = Workout::whereIn('user_id', $window->ids())
                ->whereBetween('created_at', [$window->start, $window->end])
                ->distinct()
                ->pluck('user_id');

            foreach ($ids as $id) {
                $logged[(int) $id] = true;
            }
        }

        return $logged;
    }
}
