<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NotificationService;
use App\Models\User;

class SendDailyMealReminder extends Command
{
    protected $signature = 'send:daily-meal-reminder';
    protected $description = 'Send daily meal logging reminders to users';

    public function handle()
    {
        $notificationService = new NotificationService();
        $users = User::whereDoesntHave('meals', function($query) {
            $query->whereDate('created_at', today());
        })->get();

        foreach ($users as $user) {
            $notificationService->sendNotification(
                $user->id,
                "🍽 Time for Your First Meal of The Day!",
                "Don't forget to follow your diet!"
            );
        }

        $this->info('Daily reminders sent to users who haven\'t logged meals!');
    }
}