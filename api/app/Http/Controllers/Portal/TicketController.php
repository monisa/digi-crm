<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Client requests with a conversation. Staff can add internal notes the client never sees. */
class TicketController extends Controller
{
    private const FILES = 'pdf,png,jpg,jpeg,webp,docx,xlsx,csv,zip,mp4';

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'waiting', 'resolved', 'closed', 'active', 'all'])],
            'client_id' => ['nullable', 'integer'],
            'assigned' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $base = Ticket::visibleTo($user);
        $status = $f['status'] ?? 'active';

        $list = (clone $base)->with(['client:id,company_name', 'raiser:id,name', 'assignee:id,name'])
            ->when($status === 'active', fn ($q) => $q->whereIn('status', Ticket::OPEN))
            ->when(! in_array($status, ['active', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($f['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($f['assigned'] ?? null, fn ($q, $a) => $a === 'me' ? $q->where('assigned_to', $user->id) : ($a === 'none' ? $q->whereNull('assigned_to') : $q))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('subject', 'like', "%$s%"))
            ->orderByRaw("case priority when 'high' then 0 when 'medium' then 1 else 2 end")
            ->orderByDesc(DB::raw('coalesce(last_reply_at, created_at)'))->limit(300)->get();

        return response()->json([
            'data' => $list->map(fn (Ticket $t) => $t->toApiArray($user)),
            'counts' => [
                'active' => (clone $base)->whereIn('status', Ticket::OPEN)->count(),
                'needs_reply' => (clone $base)->whereIn('status', Ticket::OPEN)->where('awaiting_staff', true)->count(),
            ],
            'categories' => config('crm.ticket_categories'),
        ]);
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);

        return response()->json($ticket->load(['client:id,company_name', 'raiser:id,name', 'assignee:id,name', 'messages'])->toApiArray($request->user(), true));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'client_id' => [$user->isClient() ? 'prohibited' : 'required', 'integer', Rule::exists('clients', 'id')],
            'subject' => ['required', 'string', 'max:160'],
            'category' => ['nullable', Rule::in(config('crm.ticket_categories'))],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'body' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:20480', 'mimes:'.self::FILES],
        ], ['body.required' => 'Please describe what you need.']);
        $clientId = $user->isClient() ? $user->client_id : $data['client_id'];
        abort_unless($clientId && Client::visibleTo($user)->whereKey($clientId)->exists(), 403);

        $ticket = DB::transaction(function () use ($data, $user, $clientId, $request) {
            $ticket = Ticket::create([
                'client_id' => $clientId,
                'raised_by' => $user->id,
                'subject' => $data['subject'],
                'category' => $data['category'] ?? null,
                'priority' => $data['priority'] ?? 'medium',
                'status' => 'open',
                'assigned_to' => $user->isClient() ? Client::find($clientId)->account_manager_id : null,
                'last_reply_at' => now(),
                'awaiting_staff' => $user->isClient(),
            ]);
            $this->addMessage($ticket, $user, $data['body'], false, $request);

            return $ticket;
        });
        $this->notifyNew($ticket->load('client'), $user, $data['body']);

        return response()->json($ticket->fresh(['client:id,company_name', 'raiser:id,name', 'assignee:id,name', 'messages'])->toApiArray($user, true), 201);
    }

    public function reply(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);
        $user = $request->user();
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'internal' => ['sometimes', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:20480', 'mimes:'.self::FILES],
        ]);
        $internal = ! $user->isClient() && $request->boolean('internal');

        DB::transaction(function () use ($ticket, $user, $data, $internal, $request) {
            $this->addMessage($ticket, $user, $data['body'], $internal, $request);
            if (! $internal) {
                $ticket->last_reply_at = now();
                $ticket->awaiting_staff = $user->isClient();
                if ($user->isClient() && in_array($ticket->status, ['waiting', 'resolved', 'closed'], true)) {
                    $ticket->status = 'open'; // the client wrote back, so it needs attention again
                    $ticket->resolved_at = null;
                } elseif (! $user->isClient() && $ticket->status === 'open') {
                    $ticket->status = 'in_progress';
                }
            }
            $ticket->save();
        });
        if (! $internal) {
            $this->notifyReply($ticket->load('client'), $user, $data['body']);
        }

        return response()->json($ticket->fresh(['client:id,company_name', 'raiser:id,name', 'assignee:id,name', 'messages'])->toApiArray($user, true), 201);
    }

    /** Staff: status, priority, assignment. Clients may only close (or reopen) their own ticket. */
    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);
        $user = $request->user();
        $data = $user->isClient()
            ? $request->validate(['status' => ['required', Rule::in(['closed', 'open'])]])
            : $request->validate([
                'status' => ['sometimes', Rule::in(['open', 'in_progress', 'waiting', 'resolved', 'closed'])],
                'priority' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
                'assigned_to' => ['sometimes', 'nullable', Rule::exists('users', 'id')->whereIn('role', ['admin', 'employee'])],
                'category' => ['sometimes', 'nullable', Rule::in(config('crm.ticket_categories'))],
            ]);
        if (! $user->isClient()) {
            abort_unless($user->hasPermission('tickets.edit'), 403, 'You cannot change tickets.');
        }
        if (isset($data['status'])) {
            $data['resolved_at'] = in_array($data['status'], ['resolved', 'closed'], true) ? ($ticket->resolved_at ?? now()) : null;
            if ($data['status'] !== $ticket->status) {
                $this->addMessage($ticket, $user, 'Status changed to '.str_replace('_', ' ', $data['status']).'.', true, null);
            }
        }
        $ticket->update($data);
        if (! $user->isClient() && in_array($data['status'] ?? null, ['waiting', 'resolved'], true)) {
            $n = app(\App\Services\Notify::class);
            $n->send($n->clientUsers($ticket->client_id), 'ticket_update',
                $data['status'] === 'resolved' ? 'Request resolved: '.$ticket->subject : 'We need something from you: '.$ticket->subject,
                $data['status'] === 'resolved' ? 'Reply if anything still needs fixing.' : 'Please reply to your request so we can continue.',
                '/tickets/'.$ticket->id, $data['status'] === 'waiting', $user);
        }

        return response()->json($ticket->fresh(['client:id,company_name', 'raiser:id,name', 'assignee:id,name', 'messages'])->toApiArray($user, true));
    }

    public function attachment(Request $request, TicketMessage $message): StreamedResponse
    {
        $this->authorizeTicket($request, $message->ticket);
        abort_if($message->internal && $request->user()->isClient(), 404);
        abort_unless($message->attachment_path, 404);

        return Storage::disk('local')->download($message->attachment_path, $message->attachment_name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function staffFor(Ticket $ticket): \Illuminate\Support\Collection
    {
        $n = app(\App\Services\Notify::class);

        return $ticket->assigned_to ? collect([$ticket->assignee()->first()]) : $n->admins();
    }

    private function notifyNew(Ticket $ticket, $user, string $body): void
    {
        $n = app(\App\Services\Notify::class);
        if ($user->isClient()) {
            $n->send($this->staffFor($ticket), 'ticket_new', ($ticket->priority === 'high' ? 'Urgent request: ' : 'New request: ').$ticket->subject,
                $ticket->client->company_name.': '.\Illuminate\Support\Str::limit($body, 160), '/tickets/'.$ticket->id, true, $user);
        } else {
            $n->send($n->clientUsers($ticket->client_id), 'ticket_update', 'DigiFox opened a request for you: '.$ticket->subject,
                \Illuminate\Support\Str::limit($body, 160), '/tickets/'.$ticket->id, true, $user);
        }
    }

    private function notifyReply(Ticket $ticket, $user, string $body): void
    {
        $n = app(\App\Services\Notify::class);
        $text = $user->name.': '.\Illuminate\Support\Str::limit($body, 160);
        if ($user->isClient()) {
            $n->send($this->staffFor($ticket), 'ticket_reply', $ticket->client->company_name.' replied: '.$ticket->subject, $text, '/tickets/'.$ticket->id, true, $user);
        } else {
            $n->send($n->clientUsers($ticket->client_id), 'ticket_update', 'New reply: '.$ticket->subject, $text, '/tickets/'.$ticket->id, true, $user);
        }
    }

    private function addMessage(Ticket $ticket, $user, string $body, bool $internal, ?Request $request): void
    {
        $file = $request?->file('attachment');
        $ticket->messages()->create([
            'user_id' => $user->id,
            'body' => $body,
            'internal' => $internal,
            'attachment_name' => $file ? mb_substr($file->getClientOriginalName(), 0, 250) : null,
            'attachment_path' => $file?->store("tickets/{$ticket->id}", 'local'),
        ]);
    }

    private function authorizeTicket(Request $request, Ticket $ticket): void
    {
        abort_unless(Ticket::visibleTo($request->user())->whereKey($ticket->id)->exists(), 404);
    }
}
