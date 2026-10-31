<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PermissionController extends Controller
{
    /** Modules × actions, plus what each non-admin role currently has. */
    public function index(): JsonResponse
    {
        $roles = ['employee', 'client'];

        return response()->json([
            'actions' => config('crm.actions'),
            'modules' => Module::orderBy('sort')->get(['id', 'key', 'name', 'client_allowed']),
            'roles' => collect($roles)->mapWithKeys(fn ($role) => [
                $role => DB::table('role_permissions')
                    ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                    ->where('role', $role)->orderBy('permissions.key')->pluck('permissions.key'),
            ]),
        ]);
    }

    public function updateRole(Request $request, string $role, Permissions $permissions): JsonResponse
    {
        abort_unless(in_array($role, ['employee', 'client'], true), 404);

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
        ]);

        $query = DB::table('permissions')->whereIn('key', $data['permissions']);
        if ($role === 'client') {
            // Clients can never be given internal modules.
            $query->whereIn('module_id', Module::where('client_allowed', true)->pluck('id'));
        }
        $ids = $query->pluck('id');

        DB::transaction(function () use ($role, $ids) {
            DB::table('role_permissions')->where('role', $role)->delete();
            DB::table('role_permissions')->insert($ids->map(fn ($id) => ['role' => $role, 'permission_id' => $id])->all());
        });
        $permissions->flush();

        return $this->index();
    }
}
