<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\LeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Each morning, add today's copy of every repeating task:
 * daily = every working day, weekly = same weekday, monthly = same date.
 */
class RepeatTasks extends Command
{
    protected $signature = 'crm:repeat-tasks';

    protected $description = 'Create today\'s copies of repeating tasks';

    public function handle(LeaveService $leave): int
    {
        $today = CarbonImmutable::today();
        $working = $leave->workingDays($today, $today) > 0;
        $made = 0;

        Task::whereNull('series_id')->where('repeat', '!=', 'none')->each(function (Task $series) use ($today, $working, &$made) {
            $start = CarbonImmutable::parse($series->due_date ?? $series->created_at)->startOfDay();
            if ($start->gte($today)) {
                return; // the first one is still today or later
            }
            $due = match ($series->repeat) {
                'daily' => $working,
                'weekly' => $today->dayOfWeek === $start->dayOfWeek,
                'monthly' => $today->day === min($start->day, $today->daysInMonth),
                default => false,
            };
            if (! $due || Task::where('series_id', $series->id)->whereDate('due_date', $today)->exists()) {
                return;
            }
            Task::create([
                'series_id' => $series->id,
                'title' => $series->title,
                'description' => $series->description,
                'assigned_to' => $series->assigned_to,
                'created_by' => $series->created_by,
                'client_id' => $series->client_id,
                'priority' => $series->priority,
                'due_date' => $today,
                'status' => 'todo',
            ]);
            $made++;
        });

        $this->info("Created $made repeating task(s).");

        return self::SUCCESS;
    }
}
