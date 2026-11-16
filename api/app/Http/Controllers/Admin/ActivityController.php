<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Settings → Activity log: who did what, and when (admins only). */
class ActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only admins can see the activity log.');
        $f = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'max:40'],
            'action' => ['nullable', 'string', 'max:30'],
            'subject_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $page = ActivityLog::with('user:id,name')
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($f['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', date('Y-m-d', strtotime($v.' +1 day'))))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('label', 'like', "%$s%")->orWhere('user_name', 'like', "%$s%")))
            ->orderByDesc('id')
            ->paginate($f['per_page'] ?? 50);

        return response()->json([
            'data' => collect($page->items())->map->toApiArray(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'people' => User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_EMPLOYEE])->orderBy('name')->get(['id', 'name']),
            'types' => ActivityLog::query()->distinct()->orderBy('subject_type')->whereNotNull('subject_type')->pluck('subject_type'),
            'keep_days' => 365,
        ]);
    }
}
