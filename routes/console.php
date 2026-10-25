<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Shared hosting has no long-running processes, so a single cron entry runs
| "php artisan schedule:run" every minute and everything happens from here.
| See DEPLOYMENT.md for the cron line.
|
*/

// Queue reminder emails that are due (claimed atomically; safe if runs overlap).
Schedule::command('bookings:send-reminders')
    ->everyMinute()
    ->withoutOverlapping(5);

// Mark meetings that ended a while ago as completed (queues CRM webhooks when configured).
Schedule::command('bookings:complete')
    ->everyFiveMinutes()
    ->withoutOverlapping(5);

// Work through the database queue (emails, calendar retries) for up to ~50 s,
// so it finishes before the next minute's run starts. The lock expires after
// 5 minutes in case the host kills the process mid-run.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --sleep=1')
    ->everyMinute()
    ->withoutOverlapping(5);

// Housekeeping.
Schedule::command('queue:prune-failed --hours=720')->daily();
