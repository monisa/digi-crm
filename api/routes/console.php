<?php

use Illuminate\Support\Facades\Schedule;

// Runs from the Hostinger cron: `php artisan schedule:run` every minute.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();

// Background work on shared hosting: the cron runs schedule:run every minute,
// which processes queued jobs (e.g. Meta webhook leads) and then exits.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=5')->everyMinute()->withoutOverlapping();

// Safety net for Meta leads the webhook may have missed.
Schedule::command('meta:poll')->everyFifteenMinutes()->withoutOverlapping();
