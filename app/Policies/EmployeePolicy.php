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

    /**
     * The archive screen, which lists deleted employees *and* clients.
     *
     * Separate from `restore` because that one needs a record to judge, and
     * this is asked before any record is in hand. Same answer — putting a
     * deleted record back is an admin act — but it has to be askable of the
     * class rather than of an instance.
     */
    public function viewArchive(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Filing a batch of documents, before it is known whose they are.
     *
     * The same relationship `viewArchive` has to `restore`: `manageDocuments`
     * judges one employee's file and needs that employee, and a batch is
     * asked of the class because the scanner has not yet said who the stack
     * belongs to. The answer must stay the same as `manageDocuments` — filing
     * forty documents cannot be open to somebody who may not file one — so it
     * is written as the same expression rather than a different rule that
     * happens to agree today.
     */
    public function fileDocumentBatch(User $user): bool
    {
        return $user->isHrAdmin();
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
}
