<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Example: Run the Laravel queue worker every minute
        // $schedule->command('queue:work')->everyMinute();
        
        $schedule->command('send:daily-reminder')->dailyAt('16:00'); // Every day at 10 AM
        $schedule->command('send:daily-meal-reminder')->dailyAt('10:00'); // Every day at 10 AM
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
