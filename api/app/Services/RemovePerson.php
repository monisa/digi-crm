<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Permanently remove a person who has left (or a test account): their login,
 * employee record, salary, payslips, leave and attendance. Records they
 * created elsewhere (leads, invoices, tickets…) stay, without their name.
 * Only inactive (or never-activated) people can be removed, never yourself
 * or the last admin.
 */
class RemovePerson
{
    public function check(?User $user, ?Employee $employee, User $by): ?string
    {
        if ($user?->is($by)) {
            return 'You cannot delete your own account.';
        }
        if ($user && $user->status === User::STATUS_ACTIVE) {
            return 'Set this person to inactive first, then delete.';
        }
        if ($employee && $employee->status !== 'inactive') {
            return 'Mark this employee as left the company (inactive) first, then delete.';
        }
        if ($user?->isAdmin() && User::where('role', User::ROLE_ADMIN)->where('status', User::STATUS_ACTIVE)->whereKeyNot($user->id)->doesntExist()) {
            return 'This is the only admin.';
        }

        return null;
    }

    public function remove(?User $user, ?Employee $employee): void
    {
        DB::transaction(function () use ($user, $employee) {
            $employee ??= $user ? Employee::where('user_id', $user->id)->first() : null;
            $user ??= $employee?->user;
            $employee?->delete(); // salary, payslips, leave go with it
            if ($user) {
                $user->tokens()->delete();
                DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->delete();
                $user->delete(); // attendance and permissions go with it; other records keep, unnamed
            }
        });
    }
}
