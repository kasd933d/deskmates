<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PRD 7.3 scheduled jobs.
Schedule::command('presence:reap-stale')->everyThirtySeconds()->withoutOverlapping();
Schedule::command('rooms:broadcast-always-on')->everyMinute()->withoutOverlapping();
Schedule::command('rooms:stop-idle-timers')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('rooms:archive-empty')->everyFiveMinutes()->withoutOverlapping();
// PRD 7.3: nightly chat prune (last 200 messages per room, PRD 4.6).
Schedule::command('messages:prune')->daily()->withoutOverlapping();
// PRD 7.3: nightly focus rollup (rebuild user_daily_focus, repair stalled
// credits) and attendance prune (rows only feed the credit job, PRD 4.8).
Schedule::command('focus:rollup')->daily()->withoutOverlapping();
Schedule::command('focus:prune-attendance')->daily()->withoutOverlapping();
