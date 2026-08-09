<?php

namespace App\Policies;

use App\Models\OvertimeRequest;
use App\Models\User;

class OvertimeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, OvertimeRequest $request): bool
    {
        if ($user->isHrAdmin()) {
            return true;
        }

        $employee = $request->employee;

        if ($employee?->user_id === $user->id) {
            return true;
        }

        return $this->supervises($user, $request);
    }

    /** Anyone with a 201 file can file overtime for themselves. */
    public function create(User $user): bool
    {
        return $user->isHrAdmin() || $user->employee !== null;
    }

    /** Only a pending request can still be edited, and only by its owner. */
    public function update(User $user, OvertimeRequest $request): bool
    {
        if ($request->status !== OvertimeRequest::STATUS_PENDING) {
            return false;
        }

        return $user->isHrAdmin() || $request->employee?->user_id === $user->id;
    }

    /**
     * Approvers are HR and the employee's own supervisor — never the requester,
     * so nobody signs off on their own overtime.
     */
    public function decide(User $user, OvertimeRequest $request): bool
    {
        if ($request->status !== OvertimeRequest::STATUS_PENDING) {
            return false;
        }

        if ($request->employee?->user_id === $user->id) {
            return false;
        }

        return $user->isHrAdmin() || $this->supervises($user, $request);
    }

    public function cancel(User $user, OvertimeRequest $request): bool
    {
        if ($request->status !== OvertimeRequest::STATUS_PENDING) {
            return false;
        }

        return $user->isHrAdmin() || $request->employee?->user_id === $user->id;
    }

    private function supervises(User $user, OvertimeRequest $request): bool
    {
        return $user->isSupervisor()
            && $request->employee?->supervisor_id !== null
            && $request->employee->supervisor_id === $user->employee?->id;
    }
}
