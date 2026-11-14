<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Internal team chat (staff only): direct messages, groups and an
 * "Everyone" group. The app polls for new messages (no WebSockets on
 * shared hosting), so every read is cheap and keyed on message ids.
 */
class ChatController extends Controller
{
    /**
     * Any normal file may be shared (photos incl. iPhone HEIC, documents, design files, video,
     * voice notes, archives). Only programs and scripts that could run on someone's computer
     * or in the browser are refused. Files are stored privately and always downloaded, never opened inline.
     */
    private const BLOCKED = ['exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'pif', 'cpl', 'dll', 'sys', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh',
        'ps1', 'psm1', 'sh', 'bash', 'php', 'phtml', 'phar', 'pl', 'py', 'rb', 'jar', 'apk', 'app', 'dmg', 'html', 'htm', 'xhtml', 'svgz', 'hta', 'lnk', 'reg', 'msp', 'iso'];

    /** My chats, newest activity first, with unread counts. */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $this->joinEveryone($me);

        $convs = Conversation::query()
            ->join('conversation_members as cm', fn ($j) => $j->on('cm.conversation_id', '=', 'conversations.id')->where('cm.user_id', $me->id))
            ->select('conversations.*', 'cm.last_read_id')
            ->with(['members:id,name'])
            ->orderByRaw('COALESCE(conversations.last_message_at, conversations.created_at) DESC')
            ->get();

        $last = Message::with('user:id,name')->whereIn('id', Message::selectRaw('MAX(id)')->whereIn('conversation_id', $convs->pluck('id'))->groupBy('conversation_id'))->get()->keyBy('conversation_id');
        $unread = Message::selectRaw('conversation_id, COUNT(*) as n')->whereIn('conversation_id', $convs->pluck('id'))
            ->where(fn ($q) => $q->where('user_id', '!=', $me->id)->orWhereNull('user_id'))->whereNull('deleted_at')
            ->whereRaw('id > (SELECT last_read_id FROM conversation_members WHERE conversation_members.conversation_id = messages.conversation_id AND conversation_members.user_id = ?)', [$me->id])
            ->groupBy('conversation_id')->pluck('n', 'conversation_id');

        return response()->json(['data' => $convs->map(fn (Conversation $c) => $this->summary($c, $me, $last->get($c->id), (int) ($unread[$c->id] ?? 0)))]);
    }

    /** Total unread, for the badge in the top bar (polled). */
    public function unread(Request $request): JsonResponse
    {
        $me = $request->user();
        $n = Message::join('conversation_members as cm', fn ($j) => $j->on('cm.conversation_id', '=', 'messages.conversation_id')->where('cm.user_id', $me->id))
            ->whereColumn('messages.id', '>', 'cm.last_read_id')->whereNull('messages.deleted_at')
            ->where(fn ($q) => $q->where('messages.user_id', '!=', $me->id)->orWhereNull('messages.user_id'))->count();

        // New messages since the app last asked (for pop-up / desktop notifications).
        $since = $request->has('since_id') ? (int) $request->query('since_id') : null;
        $base = Message::join('conversation_members as cm', fn ($j) => $j->on('cm.conversation_id', '=', 'messages.conversation_id')->where('cm.user_id', $me->id))
            ->whereNull('messages.deleted_at');
        $latest = [];
        if ($since !== null) {
            $latest = (clone $base)->where('messages.id', '>', $since)->whereColumn('messages.id', '>', 'cm.last_read_id')
                ->whereNotNull('messages.user_id')->where('messages.user_id', '!=', $me->id)
                ->orderByDesc('messages.id')->limit(5)->select('messages.*')->with(['user:id,name', 'conversation.members:id,name'])->get()
                ->map(fn (Message $m) => [
                    'id' => $m->id,
                    'conversation_id' => $m->conversation_id,
                    'chat' => $m->conversation->type === 'direct' ? null : $m->conversation->name,
                    'from' => $m->user?->name,
                    'text' => $m->body ? mb_strimwidth($m->body, 0, 140, '…') : $m->attachmentLabel(),
                ])->reverse()->values();
        }

        return response()->json(['unread' => $n, 'max_id' => (int) (clone $base)->max('messages.id'), 'latest' => $latest]);
    }

    /** Team members you can chat with. */
    public function people(Request $request): JsonResponse
    {
        return response()->json(['data' => User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_EMPLOYEE])->where('status', User::STATUS_ACTIVE)
            ->whereKeyNot($request->user()->id)->orderBy('name')->get(['id', 'name', 'role'])]);
    }

    /** Open (or start) a direct chat with someone. */
    public function direct(Request $request): JsonResponse
    {
        $me = $request->user();
        $other = $this->staff((int) $request->validate(['user_id' => ['required', 'integer']])['user_id']);
        abort_if($other->is($me), 422, 'Pick someone else.');
        $key = min($me->id, $other->id).':'.max($me->id, $other->id);

        $c = DB::transaction(function () use ($key, $me, $other) {
            $c = Conversation::firstOrCreate(['direct_key' => $key], ['type' => 'direct', 'created_by' => $me->id]);
            $c->members()->syncWithoutDetaching([$me->id, $other->id]);

            return $c;
        });

        return response()->json($this->summary($c->load('members:id,name'), $me, null, 0), 201);
    }

    public function createGroup(Request $request): JsonResponse
    {
        $me = $request->user();
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'member_ids' => ['required', 'array', 'min:1', 'max:100'], 'member_ids.*' => ['integer']]);
        $ids = collect($d['member_ids'])->map(fn ($id) => $this->staff((int) $id)->id)->push($me->id)->unique();

        $c = DB::transaction(function () use ($d, $me, $ids) {
            $c = Conversation::create(['type' => 'group', 'name' => trim($d['name']), 'created_by' => $me->id]);
            $c->members()->attach($ids->all());

            return $c;
        });
        $this->post($c, $me, $me->name.' created the group', system: true);

        return response()->json($this->summary($c->load('members:id,name'), $me, null, 0), 201);
    }

    /** Rename a group or change who is in it (its creator or an admin). */
    public function updateGroup(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->type === 'group' && ! $conversation->is_everyone, 422, 'This chat cannot be changed.');
        abort_unless($conversation->hasMember($me), 404);
        abort_unless($conversation->created_by === $me->id || $me->isAdmin(), 403, 'Only the person who made the group (or an admin) can change it.');
        $d = $request->validate(['name' => ['sometimes', 'string', 'max:80'], 'member_ids' => ['sometimes', 'array', 'min:1', 'max:100'], 'member_ids.*' => ['integer']]);

        if (isset($d['name'])) {
            $conversation->update(['name' => trim($d['name'])]);
        }
        if (isset($d['member_ids'])) {
            $ids = collect($d['member_ids'])->map(fn ($id) => $this->staff((int) $id)->id)->push($me->id)->unique();
            $conversation->members()->sync($ids->all());
        }

        return response()->json($this->summary($conversation->fresh()->load('members:id,name'), $me, null, 0));
    }

    /** Leave a group (not the Everyone group). */
    public function leave(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->type === 'group' && ! $conversation->is_everyone, 422, 'You cannot leave this chat.');
        abort_unless($conversation->hasMember($me), 404);
        $conversation->members()->detach($me->id);
        $this->post($conversation, $me, $me->name.' left the group', system: true);

        return response()->json(['left' => true]);
    }

    /** Messages: the latest 50, older ones (before_id) or new ones (after_id, for polling). */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 404);
        $d = $request->validate(['after_id' => ['nullable', 'integer'], 'before_id' => ['nullable', 'integer']]);

        $q = $conversation->messages()->with(['user:id,name', 'files']);
        if (isset($d['after_id'])) {
            $list = $q->where('id', '>', $d['after_id'])->orderBy('id')->limit(200)->get();
        } else {
            $list = $q->when($d['before_id'] ?? null, fn ($q, $b) => $q->where('id', '<', $b))->orderByDesc('id')->limit(50)->get()->reverse()->values();
        }
        // Deleted since the last poll: send their ids so the app can grey them out.
        $deleted = isset($d['after_id']) ? $conversation->messages()->whereNotNull('deleted_at')->where('deleted_at', '>=', now()->subMinutes(2))->pluck('id') : [];

        return response()->json([
            'data' => $list->map->toApiArray(),
            'deleted_ids' => $deleted,
            'has_more' => ! isset($d['after_id']) && $list->count() === 50,
            // When the others last read, for "Seen" ticks in direct chats.
            'read_upto' => DB::table('conversation_members')->where('conversation_id', $conversation->id)->where('user_id', '!=', $me->id)->max('last_read_id') ?? 0,
        ]);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 404);
        $d = $request->validate([
            'body' => ['nullable', 'string', 'max:5000', 'required_without_all:file,files'],
            'file' => ['nullable', 'file', 'max:20480', $this->safeFile()],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:20480', $this->safeFile()],
        ], [
            'file.max' => 'Files must be under 20 MB.', 'files.*.max' => 'Each file must be under 20 MB.',
            'files.max' => 'Send up to 10 files at a time.', 'body.required_without_all' => 'Type a message.',
        ]);
        $files = array_values(array_filter([$request->file('file'), ...(array) $request->file('files', [])]));
        $total = array_sum(array_map(fn ($f) => $f->getSize(), $files));
        abort_if($total > 60 * 1024 * 1024, 422, 'Files together must be under 60 MB.');

        $msg = $this->post($conversation, $me, trim((string) ($d['body'] ?? '')) ?: null, files: $files);

        return response()->json($msg->load(['user:id,name', 'files'])->toApiArray(), 201);
    }

    /** Mark everything up to a message as read. */
    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        $id = (int) $request->validate(['message_id' => ['required', 'integer']])['message_id'];
        DB::table('conversation_members')->where('conversation_id', $conversation->id)->where('user_id', $me->id)
            ->where('last_read_id', '<', $id)->update(['last_read_id' => $id, 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Delete your own message (shown as "message deleted"). */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        abort_unless($message->user_id === $request->user()->id, 403, 'You can only delete your own messages.');
        if ($message->file_path) {
            Storage::disk('local')->delete($message->file_path);
        }
        foreach ($message->files as $f) {
            Storage::disk('local')->delete($f->path);
        }
        $message->files()->delete();
        $message->update(['deleted_at' => now(), 'body' => null, 'file_path' => null]);

        return response()->json(['deleted' => true]);
    }

    public function file(Request $request, Message $message): StreamedResponse
    {
        abort_unless($message->file_path && $message->conversation->hasMember($request->user()), 404);

        return Storage::disk('local')->download($message->file_path, $message->file_name, [
            'Content-Type' => $this->safeType($message->file_mime),
        ]);
    }

    /** One of several attachments of a message. */
    public function attachment(Request $request, \App\Models\MessageFile $file): StreamedResponse
    {
        abort_unless($file->message->conversation->hasMember($request->user()), 404);

        return Storage::disk('local')->download($file->path, $file->name, ['Content-Type' => $this->safeType($file->mime)]);
    }

    // ---------------------------------------------------------------

    /** Only plain media types are passed on; anything else downloads as a generic file. */
    private function safeType(?string $mime): string
    {
        $mime = strtolower((string) $mime);

        return preg_match('#^(image/(png|jpe?g|gif|webp|heic|heif|bmp)|application/pdf|audio/[a-z0-9.+-]+|video/[a-z0-9.+-]+)$#', $mime) ? $mime : 'application/octet-stream';
    }

    /** Refuse programs and scripts (by name and by content), naming the file. */
    private function safeFile(): \Closure
    {
        return function (string $attribute, mixed $file, \Closure $fail) {
            if (! $file instanceof \Illuminate\Http\UploadedFile) {
                return;
            }
            $name = $file->getClientOriginalName();
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $real = strtolower((string) $file->guessExtension());
            $mime = strtolower((string) $file->getMimeType());
            $scriptish = in_array($ext, self::BLOCKED, true) || in_array($real, self::BLOCKED, true)
                || str_contains($mime, 'x-msdownload') || str_contains($mime, 'x-executable') || str_contains($mime, 'x-dosexec')
                || str_contains($mime, 'x-sh') || str_contains($mime, 'x-php') || $mime === 'text/html';
            if ($scriptish) {
                $fail("“{$name}” cannot be sent: programs and scripts are blocked for safety. Put it in a ZIP if a teammate really needs it.");
            }
        };
    }

    /** @param list<\Illuminate\Http\UploadedFile> $files */
    private function post(Conversation $c, User $by, ?string $body, array $files = [], bool $system = false): Message
    {
        $msg = $c->messages()->create([
            'user_id' => $system ? null : $by->id,
            'body' => $body,
        ]);
        foreach ($files as $file) {
            $msg->files()->create([
                'path' => $file->store("chat/{$c->id}", 'local'),
                'name' => mb_substr($file->getClientOriginalName(), 0, 250),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }
        $c->forceFill(['last_message_at' => $msg->created_at])->save();
        // Your own message counts as read.
        DB::table('conversation_members')->where('conversation_id', $c->id)->where('user_id', $by->id)->update(['last_read_id' => $msg->id]);

        return $msg;
    }

    private function summary(Conversation $c, User $me, ?Message $last, int $unread): array
    {
        $others = $c->members->where('id', '!=', $me->id)->values();

        return [
            'id' => $c->id,
            'type' => $c->type,
            'everyone' => $c->is_everyone,
            'name' => $c->type === 'direct' ? ($others->first()?->name ?? 'Just you') : $c->name,
            'members' => $c->members->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'can_edit' => $c->type === 'group' && ! $c->is_everyone && ($c->created_by === $me->id || $me->isAdmin()),
            'last' => $last ? [
                'text' => $last->deleted_at ? 'Message deleted' : ($last->body ?: $last->attachmentLabel()),
                'by' => $last->user_id === $me->id ? 'You' : ($last->user?->name ? explode(' ', $last->user->name)[0] : null),
                'at' => $last->created_at?->toIso8601String(),
            ] : null,
            'unread' => $unread,
        ];
    }

    /** Everyone is in the whole-team group (made on first use). */
    private function joinEveryone(User $me): void
    {
        $c = Conversation::firstOrCreate(['is_everyone' => true, 'type' => 'group'], ['name' => 'Everyone · DigiFox team']);
        $c->members()->syncWithoutDetaching([$me->id]);
    }

    private function staff(int $id): User
    {
        $u = User::whereKey($id)->whereIn('role', [User::ROLE_ADMIN, User::ROLE_EMPLOYEE])->where('status', User::STATUS_ACTIVE)->first();
        abort_unless($u, 422, 'Pick an active team member.');

        return $u;
    }
}
