<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    /** Every authenticated role can reach the directory; scoping happens in the query. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($user->isHrAdmin()) {
            return true;
        }

        // Own 201 file.
        if ($employee->user_id === $user->id) {
            return true;
        }

        // Supervisors see their direct reports.
        return $user->isSupervisor()
            && $employee->supervisor_id !== null
            && $employee->supervisor_id === $user->employee?->id;
    }

    public function create(User $user): bool
    {
        return $user->isHrAdmin();
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->isHrAdmin();
    }

    public function delete(User $user, Employee $employee): bool
    {
        // Guard against an admin removing their own record.
        return $user->isAdmin() && $employee->user_id !== $user->id;
    }

    public function restore(User $user, Employee $employee): bool
    {
        return $user->isAdmin();
    }

    public function manageDocuments(User $user, Employee $employee): bool
    {
        return $user->isHrAdmin();
    }

    /** Salary, bank details, and government IDs are HR-only. */
    public function viewSensitive(User $user, Employee $employee): bool
    {
        return $user->isHrAdmin() || $employee->user_id === $user->id;
    }

    public function viewAudits(User $user): bool
    {
        return $user->isHrAdmin();
    }
}
