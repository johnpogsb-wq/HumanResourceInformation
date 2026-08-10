<?php

namespace App\Policies;

use App\Models\User;

class SettingPolicy
{
    /**
     * Company-wide configuration is an administrator's job. HR staff run the
     * modules; they do not reconfigure the application.
     */
    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Org structure is HR's to maintain, not just the administrator's. */
    public function manageOrganization(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /** Creating and deactivating logins is an administrator action. */
    public function manageUsers(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Everyone reaches their own appearance and security preferences. */
    public function managePersonal(User $user): bool
    {
        return true;
    }

    public function viewAuditLog(User $user): bool
    {
        return $user->isHrAdmin();
    }
}
