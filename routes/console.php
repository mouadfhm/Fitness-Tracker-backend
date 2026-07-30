<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Laravel 12 does not read app/Console/Kernel.php, so the schedule lives here.
// Times are local (Casablanca); the app itself runs on UTC.
Schedule::command('send:daily-meal-reminder')
    ->dailyAt('10:00')
    ->timezone('Africa/Casablanca');

Schedule::command('send:daily-reminder')
    ->dailyAt('18:30')
    ->timezone('Africa/Casablanca');
