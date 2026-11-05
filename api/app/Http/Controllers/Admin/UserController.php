<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'role' => ['nullable', Rule::in(config('crm.roles'))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $users = User::query()
            ->when($request->role, fn ($q, $role) => $q->where('role', $role))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")->orWhere('company_name', 'like', "%$s%")))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($users->through(fn (User $u) => $u->toAuthArray()));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(config('crm.roles'))],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'status' => ['sometimes', Rule::in(config('crm.statuses'))],
            'leads_scope' => ['sometimes', Rule::in(['assigned', 'all'])],
            'clients_scope' => ['sometimes', Rule::in(['assigned', 'all'])],
        ]);

        // Prevent the admin from locking themselves out.
        if ($user->is($request->user()) && (($data['role'] ?? 'admin') !== 'admin' || ($data['status'] ?? 'active') !== 'active')) {
            return response()->json(['message' => 'You cannot change your own role or status.'], 422);
        }

        // Someone who signed up on the Register page starts as a client waiting
        // for the onboarding form; made staff, they can start straight away.
        if (isset($data['role']) && $data['role'] !== User::ROLE_CLIENT && ! isset($data['status'])
            && in_array($user->status, [User::STATUS_PENDING_ONBOARDING, User::STATUS_PENDING_APPROVAL], true)) {
            $data['status'] = User::STATUS_ACTIVE;
        }

        if (isset($data['email'])) {
            $data['email'] = \Illuminate\Support\Str::lower(trim($data['email']));
            if ($data['email'] !== $user->email) {
                $data['google_id'] = null; // the old Google account no longer signs in
            }
            // Keep the HR record in step.
            \App\Models\Employee::where('user_id', $user->id)->update(['office_email' => $data['email']]);
        }
        $user->forceFill($data)->save();

        if (($data['status'] ?? null) === User::STATUS_INACTIVE) {
            $user->tokens()->delete();
        }

        return response()->json($user->toAuthArray());
    }

    /** Per-user overrides: { "leads.view": true, "expenses.view": false } */
    public function permissions(User $user): JsonResponse
    {
        return response()->json([
            'user' => $user->toAuthArray(),
            'overrides' => DB::table('user_permissions')
                ->join('permissions', 'permissions.id', '=', 'user_permissions.permission_id')
                ->where('user_id', $user->id)
                ->pluck('allowed', 'permissions.key')
                ->map(fn ($v) => (bool) $v),
            'effective' => $user->permissionKeys(),
            // What the role alone gives, so the screen can show "default" states.
            'role_defaults' => $user->isAdmin()
                ? $user->permissionKeys()
                : DB::table('role_permissions')
                    ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                    ->where('role', $user->role)->orderBy('permissions.key')->pluck('permissions.key'),
        ]);
    }

    public function updatePermissions(Request $request, User $user, Permissions $permissions): JsonResponse
    {
        $data = $request->validate([
            'overrides' => ['present', 'array'],
            'overrides.*' => ['boolean'],
        ]);

        $ids = DB::table('permissions')->whereIn('key', array_keys($data['overrides']))->pluck('id', 'key');
        abort_if($ids->count() !== count($data['overrides']), 422, 'Unknown permission.');

        DB::transaction(function () use ($user, $ids, $data) {
            DB::table('user_permissions')->where('user_id', $user->id)->delete();
            DB::table('user_permissions')->insert($ids->map(fn ($id, $key) => [
                'user_id' => $user->id, 'permission_id' => $id, 'allowed' => (bool) $data['overrides'][$key],
            ])->values()->all());
        });
        $permissions->flush();

        return $this->permissions($user);
    }

    /** Delete an inactive login (e.g. a test account or someone who left). */
    public function destroy(Request $request, User $user, \App\Services\RemovePerson $remove): JsonResponse
    {
        $employee = \App\Models\Employee::where('user_id', $user->id)->first();
        // The login is what matters here; an attached employee record goes too.
        if ($why = $remove->check($user, null, $request->user())) {
            return response()->json(['message' => $why], 422);
        }
        $remove->remove($user, $employee);

        return response()->json(['deleted' => true]);
    }
}
