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
//
// The 10 is the lock expiry in minutes, and it is not the default. Locks live
// in the database (CACHE_STORE=database), so a run killed part-way — container
// restart, OOM — leaves its lock behind, and the default expiry of 24 hours
// would mean every reminder silently stops for a day. A run takes seconds and
// the next slot is 30 minutes out, so 10 minutes is loose enough to still catch
// a genuinely stuck run and tight enough to heal before the next one.
Schedule::command('send:daily-meal-reminder')
    ->everyThirtyMinutes()
    ->withoutOverlapping(10);

Schedule::command('send:daily-reminder')
    ->everyThirtyMinutes()
    ->withoutOverlapping(10);
