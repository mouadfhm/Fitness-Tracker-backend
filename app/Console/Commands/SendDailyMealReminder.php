<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use App\Services\EngagementService;
use App\Services\NotificationService;
use App\Services\ReminderWindow;
use App\Models\NotificationLog;
use App\Models\User;

class SendDailyMealReminder extends Command
{
    protected $signature = 'send:daily-meal-reminder';
    protected $description = 'Send meal logging reminders to users whose local time is 10:00';

    private const TITLE = "🍽 Time for Your First Meal of The Day!";
    private const BODY  = "Don't forget to follow your diet!";

    // 10:00 on the user's own clock, not on the server's. The scheduler runs
    // this every 30 minutes and ReminderWindow picks out whose turn it is.
    private const TARGET_HOUR   = 10;
    private const TARGET_MINUTE = 0;

    public function handle()
    {
        $notificationService = new NotificationService();
        $engagement = new EngagementService();

        // One reading of the clock for the whole run. Calling now() per user
        // would let a slow batch drift across the window boundary, sending some
        // users' reminders on this run and then again on the next.
        $now = Carbon::now();

        $sent = 0;
        $skipped = 0;

        // Chunked, and no longer pre-filtered in SQL. "Has not logged today"
        // depends on where the user is, so it cannot be a whereDoesntHave on one
        // server-side date any more — see hasLoggedToday below.
        User::query()->chunkById(500, function ($users) use ($notificationService, $engagement, $now, &$sent, &$skipped) {
            foreach ($users as $user) {
                if (!ReminderWindow::isDue($user->localNow($now), self::TARGET_HOUR, self::TARGET_MINUTE)) {
                    continue;
                }

                if ($this->hasLoggedToday($user, $now)) {
                    continue;
                }

                // Not logging today makes someone a candidate; it does not make
                // them a target. Someone who has been away three weeks hears from
                // us every third day, not every morning.
                if (!$engagement->dueForReminder($user->id, NotificationLog::TYPE_MEAL_REMINDER)) {
                    $notificationService->logSkipped(
                        $user->id,
                        self::TITLE,
                        self::BODY,
                        NotificationLog::TYPE_MEAL_REMINDER,
                        'Engagement backoff: ' . EngagementService::daysInactive($user->last_engaged_at) . ' days inactive'
                    );
                    $skipped++;
                    continue;
                }

                $notificationService->sendNotification(
                    $user->id,
                    self::TITLE,
                    self::BODY,
                    NotificationLog::TYPE_MEAL_REMINDER
                );
                $sent++;
            }
        });

        $this->info("Meal reminders: {$sent} sent, {$skipped} held back by engagement backoff.");
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
