<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EngagementService;
use App\Services\NotificationService;
use App\Models\NotificationLog;
use App\Models\User;

class SendDailyReminder extends Command
{
    protected $signature = 'send:daily-reminder';
    protected $description = 'Send daily workout reminders to users';

    private const TITLE = "🏋️ Time for Your Workout!";
    private const BODY  = "Don't forget to exercise today and stay fit!";

    public function handle()
    {
        // Skip if it's weekend
        if (in_array(now()->dayOfWeek, [6, 0])) {
            $this->info('No reminders sent on weekends.');
            return;
        }

        $notificationService = new NotificationService();
        $engagement = new EngagementService();

        // Get users who haven't logged workout today
        $users = User::whereDoesntHave('workouts', function ($query) {
            $query->whereDate('created_at', today());
        })->get();

        $sent = 0;
        $skipped = 0;

        foreach ($users as $user) {
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

            $notificationService->sendNotification(
                $user->id,
                self::TITLE,
                self::BODY,
                NotificationLog::TYPE_WORKOUT_REMINDER
            );
            $sent++;
        }

        $this->info("Workout reminders: {$sent} sent, {$skipped} held back by engagement backoff.");
    }
}