<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Works out a user's effective permissions:
 *   admin  -> every permission
 *   others -> role defaults, plus per-user grants, minus per-user denials
 *   client -> additionally limited to modules marked client_allowed
 *
 * Results are memoised per request; call flush() after changing permissions.
 */
class Permissions
{
    /** @var array<int, list<string>> */
    private array $cache = [];

    /** @return list<string> */
    public function for(User $user): array
    {
        return $this->cache[$user->id] ??= $this->resolve($user);
    }

    public function allows(User $user, string $key): bool
    {
        return in_array($key, $this->for($user), true);
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /** @return list<string> */
    private function resolve(User $user): array
    {
        if ($user->isAdmin()) {
            return DB::table('permissions')->orderBy('id')->pluck('key')->all();
        }

        $keys = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role', $user->role)
            ->pluck('permissions.key')
            ->all();

        $overrides = DB::table('user_permissions')
            ->join('permissions', 'permissions.id', '=', 'user_permissions.permission_id')
            ->where('user_permissions.user_id', $user->id)
            ->pluck('user_permissions.allowed', 'permissions.key');

        foreach ($overrides as $key => $allowed) {
            if ($allowed) {
                $keys[] = $key;
            } else {
                $keys = array_diff($keys, [$key]);
            }
        }

        if ($user->isClient()) {
            $clientModules = DB::table('modules')->where('client_allowed', true)->pluck('key')->all();
            $keys = array_filter($keys, fn ($k) => in_array(strtok($k, '.'), $clientModules, true));
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }
}
