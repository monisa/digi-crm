<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    /** Hostinger disables proc_open, so no scheduled job may start a separate process. */
    public function test_every_scheduled_job_runs_in_process(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertInstanceOf(CallbackEvent::class, $event, 'Uses a separate process: '.$event->command);
        }
        $names = collect($events)->map->description->all();
        foreach (['queue-worker', 'meta-poll', 'lead-sla', 'invoice-reminders'] as $job) {
            $this->assertContains($job, $names);
        }
    }

    public function test_scheduler_runs_without_proc_open(): void
    {
        // The queue worker and alert jobs run through Artisan::call inside this process.
        $this->artisan('schedule:run')->assertSuccessful();
    }
}
