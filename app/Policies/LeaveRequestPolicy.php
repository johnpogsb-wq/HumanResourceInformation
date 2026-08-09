<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LeaveRequest $request): bool
    {
        if ($user->isHrAdmin()) {
            return true;
        }

        if ($request->employee?->user_id === $user->id) {
            return true;
        }

        return $this->supervises($user, $request);
    }

    public function create(User $user): bool
    {
        return $user->isHrAdmin() || $user->employee !== null;
    }

    /** Step one: the employee's own supervisor endorses a pending request. */
    public function endorse(User $user, LeaveRequest $request): bool
    {
        if ($request->status !== LeaveRequest::STATUS_PENDING) {
            return false;
        }

        if ($this->isOwn($user, $request)) {
            return false;
        }

        return $user->isHrAdmin() || $this->supervises($user, $request);
    }

    /** Step two: HR confirms an endorsed request and credits are deducted. */
    public function confirm(User $user, LeaveRequest $request): bool
    {
        if ($request->status !== LeaveRequest::STATUS_SUPERVISOR_APPROVED) {
            return false;
        }

        if ($this->isOwn($user, $request)) {
            return false;
        }

        return $user->isHrAdmin();
    }

    /** A request can be turned down at either stage. */
    public function reject(User $user, LeaveRequest $request): bool
    {
        return $this->endorse($user, $request) || $this->confirm($user, $request);
    }

    public function cancel(User $user, LeaveRequest $request): bool
    {
        if (! $request->isOpen()) {
            return false;
        }

        return $user->isHrAdmin() || $request->employee?->user_id === $user->id;
    }

    public function manageTypes(User $user): bool
    {
        return $user->isHrAdmin();
    }

    public function adjustBalances(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /** Nobody signs off on their own leave, HR included. */
    private function isOwn(User $user, LeaveRequest $request): bool
    {
        return $request->employee?->user_id === $user->id;
    }

    private function supervises(User $user, LeaveRequest $request): bool
    {
        return $user->isSupervisor()
            && $request->employee?->supervisor_id !== null
            && $request->employee->supervisor_id === $user->employee?->id;
    }
}
