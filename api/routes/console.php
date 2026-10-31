<?php

use Illuminate\Support\Facades\Schedule;

// Runs from the Hostinger cron: `php artisan schedule:run` every minute.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
