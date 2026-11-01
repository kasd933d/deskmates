<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PRD 7.3 scheduled jobs — the timer-engine subset lands in Milestone 2;
// presence/sessions/stats jobs join in later milestones.
Schedule::command('rooms:broadcast-always-on')->everyMinute()->withoutOverlapping();
Schedule::command('rooms:stop-idle-timers')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('rooms:archive-empty')->everyFiveMinutes()->withoutOverlapping();
