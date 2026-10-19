<?php

namespace App\Policies;

use App\Models\AttendanceLog;
use App\Models\AttendancePunch;
use App\Models\User;

/**
 * Time & Attendance's own screens.
 *
 * Reading follows the employee's record (HR everybody, a supervisor the team,
 * everyone their own), narrowed by `TimekeepingService::scopedLogs()`. Writing
 * a day is HR's alone: a DTR somebody can rewrite about themselves is not a
 * record of anything, which is why everybody else files a correction.
 *
 * Shifts, holidays, cutoffs and client timesheets are asked of this class too
 * (`manage`, `closeCutoff`, `reopenCutoff`) — they are all "the people who keep
 * the time records", and one place to say who that is cannot drift.
 */
class AttendanceLogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AttendanceLog $log): bool
    {
        if ($user->isHrAdmin() || $log->employee?->user_id === $user->id) {
            return true;
        }

        return $user->isSupervisor()
            && $log->employee?->supervisor_id !== null
            && $log->employee->supervisor_id === $user->employee?->id;
    }

    /** Recording, importing and deleting days; shifts, holidays and client timesheets. */
    public function manage(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /**
     * Punching one's own day on the face-verified time clock — the one write
     * to a DTR its owner makes. Safe because the server picks the time and
     * the face has to match; editing a day afterwards is still a correction.
     */
    public function punch(User $user): bool
    {
        return $user->employee !== null && $user->employee->status !== 'inactive';
    }

    /**
     * Running the Time Clock as a shared kiosk, where the face identifies the
     * employee. HR's: it is a device HR sets up at the door, and it sees every
     * punch of the day.
     */
    public function kiosk(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /** Taking, replacing and removing anybody's face template. HR's. */
    public function enrollFaces(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /**
     * Enrolling one's own face, in any role, the first time only — replacing
     * it is HR's (`TimeClockService::enroll()` says why).
     */
    public function enrollOwnFace(User $user): bool
    {
        return $user->employee !== null && $user->employee->status !== 'inactive';
    }

    /** A punch and its snapshot follow the same reach as the day it belongs to. */
    public function viewPunch(User $user, AttendancePunch $punch): bool
    {
        if ($user->isHrAdmin() || $punch->employee?->user_id === $user->id) {
            return true;
        }

        return $user->isSupervisor()
            && $punch->employee?->supervisor_id !== null
            && $punch->employee->supervisor_id === $user->employee?->id;
    }

    public function closeCutoff(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /**
     * Reopening a closed cutoff is an admin's, with a reason: it lets figures
     * payroll may already have computed from move again.
     */
    public function reopenCutoff(User $user): bool
    {
        return $user->isAdmin();
    }
}
