<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NotificationService;
use App\Models\User;

class SendDailyReminder extends Command
{
    protected $signature = 'send:daily-reminder';
    protected $description = 'Send daily workout reminders to users';

    public function handle()
    {
        // Skip if it's weekend
        if (in_array(now()->dayOfWeek, [6, 0])) {
            $this->info('No reminders sent on weekends.');
            return;
        }

        $notificationService = new NotificationService();
        
        // Get users who haven't logged workout today
        $users = User::whereDoesntHave('workouts', function ($query) {
            $query->whereDate('created_at', today());
        })->get();

        foreach ($users as $user) {
            $notificationService->sendNotification(
                $user->id,
                "🏋️ Time for Your Workout!",
                "Don't forget to exercise today and stay fit!"
            );
        }

        $this->info('Daily reminders sent to ' . $users->count() . ' users!');
    }
}