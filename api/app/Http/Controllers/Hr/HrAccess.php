<?php

namespace App\Http\Controllers\Hr;

use App\Models\Employee;
use App\Models\User;

/** Who may see which HR records. */
trait HrAccess
{
    /** Admins and anyone with employees.edit see every employee; others only themselves. */
    protected function managesEmployees(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('employees.edit');
    }

    protected function authorizeEmployee(User $user, Employee $employee): void
    {
        abort_unless($this->managesEmployees($user) || $employee->user_id === $user->id, 403, 'You can only see your own record.');
    }

    protected function ownEmployee(User $user): Employee
    {
        $employee = $user->employee;
        abort_unless($employee, 404, 'Your login is not linked to an employee record yet. Please ask the admin.');

        return $employee;
    }
}
