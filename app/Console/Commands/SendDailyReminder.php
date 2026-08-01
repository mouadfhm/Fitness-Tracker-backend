<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use App\Services\EngagementService;
use App\Services\NotificationService;
use App\Services\ReminderWindow;
use App\Models\NotificationLog;
use App\Models\User;

class SendDailyReminder extends Command
{
    protected $signature = 'send:daily-reminder';
    protected $description = 'Send workout reminders to users whose local time is 18:30 on a weekday';

    private const TITLE = "🏋️ Time for Your Workout!";
    private const BODY  = "Don't forget to exercise today and stay fit!";

    // 18:30 on the user's own clock, not on the server's. The scheduler runs
    // this every 30 minutes and ReminderWindow picks out whose turn it is.
    private const TARGET_HOUR   = 18;
    private const TARGET_MINUTE = 30;

    public function handle()
    {
        $notificationService = new NotificationService();
        $engagement = new EngagementService();

        // One reading of the clock for the whole run. Calling now() per user
        // would let a slow batch drift across the window boundary, sending some
        // users' reminders on this run and then again on the next.
        $now = Carbon::now();

        $skipped = 0;

        // Collected rather than sent one at a time, then handed to sendBulk in
        // a single call. 600 due users cost two FCM requests instead of 600.
        // Accumulated across chunks on purpose: batching per chunk would let a
        // chunk that happens to hold 40 due users spend a whole request on 40
        // messages.
        $due = [];

        // Chunked, and no longer pre-filtered in SQL. Both "is it a weekday"
        // and "has not logged today" depend on where the user is, so neither
        // can be decided once for the whole batch any more.
        User::query()->chunkById(500, function ($users) use ($notificationService, $engagement, $now, &$due, &$skipped) {
            foreach ($users as $user) {
                $localNow = $user->localNow($now);

                if (!ReminderWindow::isDue($localNow, self::TARGET_HOUR, self::TARGET_MINUTE)) {
                    continue;
                }

                // Weekends are the user's weekend. The server's UTC Friday
                // evening is already Saturday in Auckland, and its Sunday
                // morning is still Saturday in Honolulu — the old server-side
                // check got both of those wrong.
                if (in_array($localNow->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY], true)) {
                    continue;
                }

                if ($this->hasLoggedToday($user, $now)) {
                    continue;
                }

                // Not logging today makes someone a candidate; it does not make
                // them a target. Someone who has been away three weeks hears from
                // us every third day, not every evening.
                if (!$engagement->dueForReminder($user->id, NotificationLog::TYPE_WORKOUT_REMINDER)) {
                    $notificationService->logSkipped(
                        $user->id,
                        self::TITLE,
                        self::BODY,
                        NotificationLog::TYPE_WORKOUT_REMINDER,
                        'Engagement backoff: ' . EngagementService::daysInactive($user->last_engaged_at) . ' days inactive'
                    );
                    $skipped++;
                    continue;
                }

                $due[] = $user->id;
            }
        });

        // Preferences, quiet hours and missing devices are still decided per
        // user — inside sendBulk, where every other sender already gets them.
        $result = $notificationService->sendBulk(
            $due,
            self::TITLE,
            self::BODY,
            NotificationLog::TYPE_WORKOUT_REMINDER
        );

        $this->info(
            "Workout reminders: {$result['sent']} sent, {$result['failed']} failed, " .
            "{$skipped} held back by engagement backoff, {$result['skipped']} suppressed."
        );
    }

    /**
     * Has the user logged a workout on the day it currently is where they are?
     *
     * created_at is stored in UTC, so their local midnights are converted before
     * being compared against it. Reusing the old whereDate(..., today()) would
     * ask "did they log on the UTC date", which is a different day entirely for
     * anyone far enough east or west.
     */
    private function hasLoggedToday(User $user, Carbon $now): bool
    {
        $localNow = $user->localNow($now);

        return $user->workouts()
            ->whereBetween('created_at', [
                $localNow->copy()->startOfDay()->utc(),
                $localNow->copy()->endOfDay()->utc(),
            ])
            ->exists();
    }
}
