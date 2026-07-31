<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Laravel 12 does not read app/Console/Kernel.php, so the schedule lives here.
//
// These no longer name a time. They used to be dailyAt(...) pinned to
// Africa/Casablanca, which fired once for everybody and was the right hour only
// for users in Morocco. Each command now runs every 30 minutes and selects the
// users whose own clock reads its target — 10:00 for meals, 18:30 for workouts.
// See App\Services\ReminderWindow; its window width and this cadence have to
// stay equal, or users get either two reminders a day or none.
//
// withoutOverlapping because a run that outlives its slot would otherwise be
// joined by the next one, which would double-send to everyone not yet processed.
Schedule::command('send:daily-meal-reminder')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('send:daily-reminder')
    ->everyThirtyMinutes()
    ->withoutOverlapping();
