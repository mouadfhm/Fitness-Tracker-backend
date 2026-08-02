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
// users whose own clock reads their own target — the hour they habitually log
// at, from users.preferred_meal_hour / preferred_workout_hour, falling back to
// 10:00 and 18:30 for everyone we have not learned one for. See
// App\Services\ReminderWindow; its window width and this cadence have to stay
// equal, or users get either two reminders a day or none.
//
// Spec 07 asks for these to become hourly runs matching on the hour. They are
// not, and must not be: an hour-wide match sends twice to the half-hour zones
// (Asia/Kolkata, Australia/Adelaide) and cannot express the 18:30 default at
// all. The structural change it wanted — a per-user target instead of one time
// for everybody — is what spec 09 already built here, and a per-user *hour*
// slots into it without touching the cadence.
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

// Hourly, not every thirty minutes, and that difference is load-bearing rather
// than a preference. Its window is one hour wide — see
// App\Services\SessionReminderWindow::INTERVAL_MINUTES — and the two numbers
// have to match for the same reason the pair above do: a window wider than the
// cadence sends twice, a narrower one sends to nobody in the zones whose offset
// is not a whole hour. Change one and change the other in the same commit.
//
// The two above cannot be folded into this cadence to share it: their 30-minute
// window is what keeps the workout reminder at 18:30 instead of rounding it to
// the hour.
Schedule::command('send:workout-session-reminder')
    ->hourly()
    ->withoutOverlapping(10);

// Learns what hour each user actually logs at and writes it to
// users.preferred_meal_hour / preferred_workout_hour, which the two daily
// commands above read. See App\Console\Commands\RecomputeHabitualSendHours.
//
// Weekly because a habit measured over thirty days does not move meaningfully
// in less than that, and this walks every user and their last thirty days of
// logging — the one job here whose cost grows with the size of the tables.
//
// Monday 03:15 UTC. The hour is the quietest for a job nothing user-facing is
// waiting on; the :15 keeps it off the :00 and :30 slots where all three
// senders above fire, so a long run cannot delay somebody's reminder. No
// ->timezone(...) call, unlike the reminders: nobody experiences this one, so
// the server's clock is the right clock.
//
// The 30-minute lock is longer than the senders' 10 because this genuinely can
// take minutes, and a second copy running over the top of the first would have
// them writing the same rows.
Schedule::command('notifications:recompute-habitual-hours')
    ->weeklyOn(1, '03:15')
    ->withoutOverlapping(30);
