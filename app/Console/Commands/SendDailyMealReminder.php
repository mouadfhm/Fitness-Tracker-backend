<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EngagementService;
use App\Services\NotificationService;
use App\Models\NotificationLog;
use App\Models\User;

class SendDailyMealReminder extends Command
{
    protected $signature = 'send:daily-meal-reminder';
    protected $description = 'Send daily meal logging reminders to users';

    private const TITLE = "🍽 Time for Your First Meal of The Day!";
    private const BODY  = "Don't forget to follow your diet!";

    public function handle()
    {
        $notificationService = new NotificationService();
        $engagement = new EngagementService();

        $users = User::whereDoesntHave('meals', function($query) {
            $query->whereDate('created_at', today());
        })->get();

        $sent = 0;
        $skipped = 0;

        foreach ($users as $user) {
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

        $this->info("Meal reminders: {$sent} sent, {$skipped} held back by engagement backoff.");
    }
}