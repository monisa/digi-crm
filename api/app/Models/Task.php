<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use LogsActivity;

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['todo', 'in_progress', 'review', 'done'];

    public const REPEATS = ['none', 'daily', 'weekly', 'monthly'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'completed_at' => 'datetime', 'minutes_spent' => 'integer', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** Team managers (tasks.edit) see every task; others the ones assigned to or made by them. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return self::seesTeam($user) ? $q
            : $q->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhere('created_by', $user->id));
    }

    /** Managers (tasks.edit or tasks.approve) and admins see every task. */
    public static function seesTeam(User $user): bool
    {
        return $user->hasPermission('tasks.edit') || $user->hasPermission('tasks.approve');
    }

    /** Only managers / admins may verify a task and mark it done. */
    public static function canApprove(User $user): bool
    {
        return $user->hasPermission('tasks.approve');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'done' && $this->due_date && $this->due_date->lt(today());
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'assigned_to' => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null,
            'created_by' => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null,
            'client' => $this->client ? ['id' => $this->client->id, 'company_name' => $this->client->company_name] : null,
            'ticket' => $this->ticket ? ['id' => $this->ticket->id, 'subject' => $this->ticket->subject] : null,
            'due_date' => $this->due_date?->toDateString(),
            'priority' => $this->priority,
            'status' => $this->status,
            'overdue' => $this->isOverdue(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by && $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'minutes_spent' => $this->minutes_spent,
            'repeat' => $this->series_id ? ($this->series?->repeat ?? 'none') : $this->repeat,
            'series_id' => $this->series_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'series_id');
    }
}
