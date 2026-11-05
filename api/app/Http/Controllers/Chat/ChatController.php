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
    private const FILE_TYPES = 'png,jpg,jpeg,webp,gif,pdf,doc,docx,xls,xlsx,csv,ppt,pptx,txt,zip,mp4,mov,mp3,m4a';

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

        return response()->json(['unread' => $n]);
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

        $q = $conversation->messages()->with('user:id,name');
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
            'body' => ['nullable', 'string', 'max:5000', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:20480', 'mimes:'.self::FILE_TYPES],
        ], ['file.max' => 'Files must be under 20 MB.', 'file.mimes' => 'This type of file cannot be sent.', 'body.required_without' => 'Type a message.']);

        $msg = $this->post($conversation, $me, trim((string) ($d['body'] ?? '')) ?: null, file: $request->file('file'));

        return response()->json($msg->load('user:id,name')->toApiArray(), 201);
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
        $message->update(['deleted_at' => now(), 'body' => null, 'file_path' => null]);

        return response()->json(['deleted' => true]);
    }

    public function file(Request $request, Message $message): StreamedResponse
    {
        abort_unless($message->file_path && $message->conversation->hasMember($request->user()), 404);

        return Storage::disk('local')->download($message->file_path, $message->file_name, [
            'Content-Type' => $message->file_mime ?: 'application/octet-stream',
        ]);
    }

    // ---------------------------------------------------------------

    private function post(Conversation $c, User $by, ?string $body, ?\Illuminate\Http\UploadedFile $file = null, bool $system = false): Message
    {
        $msg = $c->messages()->create([
            'user_id' => $system ? null : $by->id,
            'body' => $body,
            ...($file ? [
                'file_path' => $file->store("chat/{$c->id}", 'local'),
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 250),
                'file_mime' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
            ] : []),
        ]);
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
                'text' => $last->deleted_at ? 'Message deleted' : ($last->body ?: ($last->file_name ? '📎 '.$last->file_name : '')),
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
