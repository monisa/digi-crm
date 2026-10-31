<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $sort = 0;

        foreach (config('crm.modules') as $key => [$name, $clientAllowed]) {
            DB::table('modules')->updateOrInsert(
                ['key' => $key],
                ['name' => $name, 'sort' => $sort++, 'client_allowed' => $clientAllowed, 'updated_at' => $now, 'created_at' => $now],
            );
            $moduleId = DB::table('modules')->where('key', $key)->value('id');

            foreach (config('crm.actions') as $action) {
                $permKey = "$key.$action";
                if (DB::table('permissions')->where('key', $permKey)->exists()) {
                    continue;
                }

                $permissionId = DB::table('permissions')->insertGetId([
                    'module_id' => $moduleId, 'action' => $action, 'key' => $permKey,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                foreach (config('crm.role_defaults') as $role => $keys) {
                    if (in_array($permKey, $keys, true)) {
                        DB::table('role_permissions')->insert(['role' => $role, 'permission_id' => $permissionId]);
                    }
                }
            }
        }
    }
}
