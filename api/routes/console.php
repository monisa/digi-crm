<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
| Runs from the Hostinger cron: `php artisan schedule:run` every minute.
|
| Hostinger's PHP has proc_open turned off, so jobs must not be started as
| separate processes (Schedule::command does that). Everything runs inside
| the scheduler's own PHP process via Artisan::call instead.
*/
$run = fn (string $command, array $options = []) => fn () => Artisan::call($command, $options);

// Background work: send queued emails, fetch Meta webhook leads.
Schedule::call($run('queue:work', ['--stop-when-empty' => true, '--max-time' => 50, '--tries' => 5]))
    ->name('queue-worker')->everyMinute()->withoutOverlapping(5);

// Safety net for Meta leads the webhook may have missed.
Schedule::call($run('meta:poll'))->name('meta-poll')->everyFifteenMinutes()->withoutOverlapping(20);

// Alerts
Schedule::call($run('crm:lead-sla'))->name('lead-sla')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::call($run('crm:invoice-reminders'))->name('invoice-reminders')->dailyAt('10:12');

// Razorpay: pick up payments a webhook missed.
Schedule::call($run('crm:razorpay-sync'))->name('razorpay-sync')->everyTenMinutes()->withoutOverlapping(15);

// Work
Schedule::call($run('crm:repeat-tasks'))->name('repeat-tasks')->dailyAt('00:10');
Schedule::call(fn () => app(\App\Services\AttendanceService::class)->closeOpenDays())->name('close-attendance')->dailyAt('00:20');

// Backups: database every night, uploaded files every Sunday night.
Schedule::call($run('crm:backup'))->name('backup-db')->dailyAt('02:17');
Schedule::call($run('crm:backup', ['--files' => true]))->name('backup-files')->weeklyOn(0, '03:23');

// Housekeeping
Schedule::call($run('sanctum:prune-expired', ['--hours' => 24]))->name('prune-tokens')->daily();
Schedule::call($run('auth:clear-resets'))->name('clear-resets')->everyFifteenMinutes();
// Keep the bell tidy: drop read notifications after 90 days.
Schedule::call(fn () => DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', now()->subDays(90))->delete())
    ->name('prune-notifications')->weekly();
