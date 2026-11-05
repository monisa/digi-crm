<?php

namespace App\Http\Controllers\Work;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Notify;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Daily work: tasks assigned to people, optionally for a client or a ticket,
 * one-off or repeating. Everyone sees their own; tasks.edit sees the team.
 */
class TaskController extends Controller
{
    public function __construct(private Notify $notify) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'view' => ['nullable', Rule::in(['today', 'upcoming', 'overdue', 'done', 'all'])],
            'assigned' => ['nullable', 'string'], // me | none | user id (team managers)
            'client_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $base = Task::visibleTo($user);
        $view = $f['view'] ?? 'today';
        $today = today()->toDateString();

        $q = (clone $base)->with(['assignee:id,name', 'creator:id,name', 'client:id,company_name', 'ticket:id,subject', 'series:id,repeat'])
            ->when($f['assigned'] ?? null, fn ($q, $a) => match ($a) {
                'me' => $q->where('assigned_to', $user->id),
                'none' => $q->whereNull('assigned_to'),
                default => $q->where('assigned_to', (int) $a),
            })
            ->when($f['client_id'] ?? null, fn ($q, $c) => $q->where('client_id', $c))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('title', 'like', "%$s%"));
        $this->applyView($q, $view, $today);

        $tasks = $q->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderByDesc('id')
            ->limit($view === 'done' || $view === 'all' ? 200 : 500)->get();

        $mine = fn () => (clone $base)->where('assigned_to', $user->id);

        return response()->json([
            'data' => $tasks->map->toApiArray(),
            'counts' => [
                'today' => $this->applyView($mine(), 'today', $today)->count(),
                'overdue' => $this->applyView($mine(), 'overdue', $today)->count(),
                'upcoming' => $this->applyView($mine(), 'upcoming', $today)->count(),
                'done_today' => $mine()->where('status', 'done')->whereDate('completed_at', $today)->count(),
            ],
            'can_manage' => $user->hasPermission('tasks.edit'),
        ]);
    }

    private function applyView(Builder $q, string $view, string $today): Builder
    {
        return match ($view) {
            // Today: due today, overdue, or no date, and not finished.
            'today' => $q->where('status', '!=', 'done')->where(fn ($q) => $q->whereNull('due_date')->orWhereDate('due_date', '<=', $today)),
            'overdue' => $q->where('status', '!=', 'done')->whereDate('due_date', '<', $today),
            'upcoming' => $q->where('status', '!=', 'done')->whereDate('due_date', '>', $today),
            'done' => $q->where('status', 'done'),
            default => $q,
        };
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request);
        $data['assigned_to'] = $this->assigneeFor($user, $data['assigned_to'] ?? $user->id);

        $task = Task::create([...$data, 'created_by' => $user->id, 'status' => 'todo', 'due_date' => $data['due_date'] ?? today()]);
        $this->tellAssignee($task, $user);

        return response()->json($task->load(['assignee', 'creator', 'client', 'ticket'])->toApiArray(), 201);
    }

    /** Turn a client ticket into a task for someone (also assigns the ticket to them). */
    public function fromTicket(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        abort_if($user->isClient(), 403);
        abort_unless(Ticket::visibleTo($user)->whereKey($ticket->id)->exists(), 404);
        $data = $request->validate([
            'assigned_to' => ['required', 'integer'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'title' => ['nullable', 'string', 'max:200'],
        ]);
        $assignee = $this->assigneeFor($user, $data['assigned_to']);

        $task = Task::create([
            'title' => $data['title'] ?? ('Ticket: '.$ticket->subject),
            'assigned_to' => $assignee,
            'created_by' => $user->id,
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'due_date' => $data['due_date'] ?? today(),
            'priority' => $data['priority'] ?? ($ticket->priority === 'high' ? 'urgent' : ($ticket->priority === 'low' ? 'low' : 'normal')), // ticket "high" is shown as Urgent
        ]);
        if (! $ticket->assigned_to || $ticket->assigned_to !== $assignee) {
            $ticket->update(['assigned_to' => $assignee]);
        }
        $this->tellAssignee($task, $user);

        return response()->json($task->load(['assignee', 'creator', 'client', 'ticket'])->toApiArray(), 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        abort_unless(Task::visibleTo($user)->whereKey($task->id)->exists(), 404);
        $manage = $user->hasPermission('tasks.edit') || $task->created_by === $user->id;

        // The assignee may move it along and log time; changing the task itself needs the creator or a manager.
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Task::STATUSES)],
            'add_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            ...($manage ? $this->rules(partial: true) : []),
        ]);
        if (array_key_exists('assigned_to', $data)) {
            $data['assigned_to'] = $this->assigneeFor($user, $data['assigned_to'] ?? $user->id);
        }
        if (isset($data['add_minutes'])) {
            $task->minutes_spent += $data['add_minutes'];
            unset($data['add_minutes']);
        }
        $wasDone = $task->status === 'done';
        $before = $task->assigned_to;
        if (isset($data['status'])) {
            $data['completed_at'] = $data['status'] === 'done' ? ($task->completed_at ?? now()) : null;
        }
        $task->fill($data)->save();

        if ($task->assigned_to !== $before) {
            $this->tellAssignee($task, $user);
        }
        if (! $wasDone && $task->status === 'done' && $task->created_by && $task->created_by !== $user->id) {
            $this->notify->send(User::whereKey($task->created_by)->get(), 'task_done', $user->name.' finished a task', $task->title, '/tasks');
        }

        return response()->json($task->fresh(['assignee', 'creator', 'client', 'ticket', 'series'])->toApiArray());
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        abort_unless(Task::visibleTo($user)->whereKey($task->id)->exists(), 404);
        abort_unless($user->hasPermission('tasks.edit') || $task->created_by === $user->id, 403, 'Only the person who made this task can delete it.');
        $task->delete(); // a repeating task takes its copies with it

        return response()->json(['deleted' => true]);
    }

    /** People tasks can go to: yourself, or anyone if you manage the team. */
    private function assigneeFor(User $user, mixed $id): ?int
    {
        $id = $id ? (int) $id : null;
        if ($id === null || $id === $user->id) {
            return $id ?? $user->id;
        }
        abort_unless($user->hasPermission('tasks.edit'), 403, 'You can only add tasks for yourself.');
        abort_unless(User::whereKey($id)->whereIn('role', ['admin', 'employee'])->where('status', 'active')->exists(), 422, 'Pick an active team member.');

        return $id;
    }

    private function tellAssignee(Task $task, User $by): void
    {
        if ($task->assigned_to && $task->assigned_to !== $by->id) {
            $due = $task->due_date ? ' · due '.$task->due_date->format('j M') : '';
            $this->notify->send(User::whereKey($task->assigned_to)->get(), 'task_assigned', $by->name.' gave you a task', $task->title.$due, '/tasks');
        }
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$req, 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'ticket_id' => ['nullable', 'integer', Rule::exists('tickets', 'id')],
            'due_date' => ['nullable', 'date'],
            'priority' => ['sometimes', Rule::in(Task::PRIORITIES)],
            'repeat' => ['sometimes', Rule::in(Task::REPEATS)],
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate($this->rules());
        if ($data['client_id'] ?? null) {
            abort_unless(Client::visibleTo($request->user())->whereKey($data['client_id'])->exists() || $request->user()->hasPermission('tasks.edit'), 403);
        }

        return $data;
    }
}
