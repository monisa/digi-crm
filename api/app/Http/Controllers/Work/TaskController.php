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
            'view' => ['nullable', Rule::in(['today', 'upcoming', 'overdue', 'review', 'done', 'all'])],
            'assigned' => ['nullable', 'string'], // me | none | user id (team managers)
            'client_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $base = Task::visibleTo($user);
        $view = $f['view'] ?? 'today';
        $today = today()->toDateString();

        // Whose tasks: me | none | a person; empty = everyone this user can see.
        $who = fn () => (clone $base)->when($f['assigned'] ?? null, fn ($q, $a) => match ($a) {
            'me' => $q->where('assigned_to', $user->id),
            'none' => $q->whereNull('assigned_to'),
            default => $q->where('assigned_to', (int) $a),
        });

        $q = $who()->with(['assignee:id,name', 'creator:id,name', 'client:id,company_name', 'ticket:id,subject', 'series:id,repeat', 'reviewer:id,name'])
            ->when($f['client_id'] ?? null, fn ($q, $c) => $q->where('client_id', $c))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('title', 'like', "%$s%"));
        $this->applyView($q, $view, $today);

        $tasks = $q->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderByDesc('id')
            ->when($view === 'done', fn ($q) => $q->reorder()->orderByDesc('completed_at'))
            ->when($view === 'review', fn ($q) => $q->reorder()->orderBy('submitted_at'))
            ->limit($view === 'done' || $view === 'all' ? 200 : 500)->get();

        return response()->json([
            'data' => $tasks->map->toApiArray(),
            'counts' => [
                // The counts follow the same "whose tasks" choice as the list.
                'today' => $this->applyView($who(), 'today', $today)->count(),
                'overdue' => $this->applyView($who(), 'overdue', $today)->count(),
                'upcoming' => $this->applyView($who(), 'upcoming', $today)->count(),
                'done_today' => $who()->where('status', 'done')->whereDate('completed_at', $today)->count(),
                'review' => $who()->where('status', 'review')->count(),
            ],
            'can_manage' => $user->hasPermission('tasks.edit'),
            'can_approve' => Task::canApprove($user),
            'sees_team' => Task::seesTeam($user),
        ]);
    }

    private function applyView(Builder $q, string $view, string $today): Builder
    {
        return match ($view) {
            // Today: due today, overdue, or no date, and not finished.
            'today' => $q->whereIn('status', ['todo', 'in_progress'])->where(fn ($q) => $q->whereNull('due_date')->orWhereDate('due_date', '<=', $today)),
            'overdue' => $q->whereIn('status', ['todo', 'in_progress'])->whereDate('due_date', '<', $today),
            'upcoming' => $q->whereIn('status', ['todo', 'in_progress'])->whereDate('due_date', '>', $today),
            'review' => $q->where('status', 'review'),
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
        $approver = Task::canApprove($user);

        // The assignee moves it along and logs time; changing the task itself needs the creator or a manager.
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Task::STATUSES)],
            'add_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'note' => ['nullable', 'string', 'max:1000'], // manager's note when sending a task back
            ...($manage ? $this->rules(partial: true) : []),
        ]);
        $note = $data['note'] ?? null;
        unset($data['note']);
        if (array_key_exists('assigned_to', $data)) {
            $data['assigned_to'] = $this->assigneeFor($user, $data['assigned_to'] ?? $user->id);
        }
        if (isset($data['add_minutes'])) {
            $task->minutes_spent += $data['add_minutes'];
            unset($data['add_minutes']);
        }

        $from = $task->status;
        $to = $data['status'] ?? $from;
        if ($to !== $from) {
            // Done is a manager's decision; a done task is only reopened by a manager.
            abort_if($to === 'done' && ! $approver, 403, 'Only a manager can mark a task done. Send it for review instead.');
            abort_if($from === 'done' && ! $approver, 403, 'This task was approved. Ask a manager to reopen it.');
            abort_if($from === 'review' && $to !== 'done' && ! $approver && $user->id !== $task->assigned_to, 403, 'This task is waiting for a manager’s review.');
            if (in_array($to, ['review', 'done'], true) && $task->minutes_spent < 1) {
                throw \Illuminate\Validation\ValidationException::withMessages(['add_minutes' => 'Log the time spent on this task first.']);
            }
            $data['completed_at'] = $to === 'done' ? now() : null;
            if ($to === 'review') {
                $data['submitted_at'] = now();
                $data['review_note'] = null;
            }
            if ($to === 'done') {
                $data['reviewed_by'] = $user->id;
                $data['reviewed_at'] = now();
            }
            if ($from === 'review' && in_array($to, ['todo', 'in_progress'], true) && $approver && $user->id !== $task->assigned_to) {
                $data['review_note'] = $note ?: 'Sent back for changes.';
                $data['reviewed_by'] = $user->id;
                $data['reviewed_at'] = now();
            }
        }
        $before = $task->assigned_to;
        $task->fill($data)->save();

        if ($task->assigned_to !== $before) {
            $this->tellAssignee($task, $user);
        }
        $this->tellAboutStatus($task, $from, $to, $user);

        return response()->json($task->fresh(['assignee', 'creator', 'client', 'ticket', 'series', 'reviewer'])->toApiArray());
    }

    /** Review requests go to managers; approvals and send-backs go to the person doing the task. */
    private function tellAboutStatus(Task $task, string $from, string $to, User $by): void
    {
        if ($from === $to) {
            return;
        }
        $hrs = fn () => intdiv($task->minutes_spent, 60).'h '.str_pad((string) ($task->minutes_spent % 60), 2, '0', STR_PAD_LEFT).'m';
        if ($to === 'review') {
            $creator = $task->created_by ? User::find($task->created_by) : null;
            $who = $creator && $creator->id !== $by->id && Task::canApprove($creator) ? collect([$creator])
                : $this->notify->staffWith('tasks.approve')->where('id', '!=', $by->id);
            $this->notify->send($who, 'task_review', $by->name.' finished a task, please check', $task->title.' · '.$hrs(), '/tasks?view=review');
        } elseif ($to === 'done' && $task->assigned_to && $task->assigned_to !== $by->id) {
            $this->notify->send(User::whereKey($task->assigned_to)->get(), 'task_done', 'Task approved ✓', $task->title.' was checked by '.$by->name.'.', '/tasks?view=done');
        } elseif ($from === 'review' && $task->assigned_to && $task->assigned_to !== $by->id) {
            $this->notify->send(User::whereKey($task->assigned_to)->get(), 'task_returned', 'Task sent back by '.$by->name, $task->title.': '.$task->review_note, '/tasks');
        }
    }

    /** Managers: who is working on what right now. */
    public function team(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(Task::seesTeam($user), 403, 'Only managers can see the whole team.');
        $today = today();
        $people = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_EMPLOYEE])->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name']);
        $open = Task::whereIn('assigned_to', $people->pluck('id'))->whereIn('status', ['todo', 'in_progress', 'review'])
            ->with('client:id,company_name')->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'review' THEN 1 ELSE 2 END")->orderBy('due_date')->get()->groupBy('assigned_to');
        $doneToday = Task::where('status', 'done')->whereDate('completed_at', $today)->selectRaw('assigned_to, count(*) n, sum(minutes_spent) m')->groupBy('assigned_to')->get()->keyBy('assigned_to');

        return response()->json(['data' => $people->map(function (User $p) use ($open, $doneToday, $today) {
            $mine = $open->get($p->id, collect());

            return [
                'user' => ['id' => $p->id, 'name' => $p->name],
                'working_on' => $mine->where('status', 'in_progress')->take(4)->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'client' => $t->client?->company_name, 'minutes' => $t->minutes_spent])->values(),
                'in_progress' => $mine->where('status', 'in_progress')->count(),
                'todo' => $mine->where('status', 'todo')->count(),
                'review' => $mine->where('status', 'review')->count(),
                'overdue' => $mine->filter(fn (Task $t) => $t->status !== 'review' && $t->due_date && $t->due_date->lt($today))->count(),
                'done_today' => (int) ($doneToday[$p->id]->n ?? 0),
                'minutes_done_today' => (int) ($doneToday[$p->id]->m ?? 0),
            ];
        })->values()]);
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
