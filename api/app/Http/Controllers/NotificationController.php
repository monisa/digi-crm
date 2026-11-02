<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

/** The bell: list, unread count (polled by the app), mark read, email preferences. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['category' => ['nullable', 'string', 'max:20'], 'unread' => ['nullable', 'boolean'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $q = $request->user()->notifications()
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('data->category', $c))
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'));

        return response()->json([
            'data' => $q->limit($f['limit'] ?? 50)->get()->map(fn (DatabaseNotification $n) => [
                'id' => $n->id, ...$n->data, 'read' => (bool) $n->read_at, 'at' => $n->created_at->toIso8601String(),
            ]),
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:200'], 'ids.*' => ['string'], 'all' => ['nullable', 'boolean']]);
        $q = $request->user()->unreadNotifications();
        if (! $request->boolean('all')) {
            $q->whereIn('id', $data['ids'] ?? []);
        }
        $q->update(['read_at' => now()]);

        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json($this->prefs($request));
    }

    public function savePreferences(Request $request): JsonResponse
    {
        $types = array_keys(config('crm.notification_types'));
        $data = $request->validate(['email' => ['required', 'array'], 'email.*' => ['boolean']]);
        $request->user()->forceFill(['notification_prefs' => array_intersect_key($data['email'], array_flip($types))])->save();

        return response()->json($this->prefs($request));
    }

    private function prefs(Request $request): array
    {
        $user = $request->user();
        $audience = $user->isClient() ? 'client' : 'staff';

        return ['types' => collect(config('crm.notification_types'))
            ->filter(fn ($t) => $t[2] === $audience)
            ->map(fn ($t, $key) => ['key' => $key, 'label' => $t[0], 'category' => $t[1], 'email' => $user->wantsEmail($key)])
            ->values()];
    }
}
