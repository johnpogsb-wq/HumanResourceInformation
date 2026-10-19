<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\AttendancePunch;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The face-verified time clock: an employee punches their own day.
 *
 * This is the one place an employee writes to their own DTR, and what makes
 * that defensible is that they choose nothing about it. The time is the
 * server's clock at the moment of the request, never a value the page sends;
 * the face has to match the one HR enrolled; and every press is kept as an
 * `AttendancePunch` that nothing updates. Changing a day afterwards is still a
 * time correction somebody else decides — the clock writes the record, it
 * does not open it to editing.
 *
 * Every write goes through `TimekeepingService::writeDay()`, so a punch is
 * computed by the same calculator as an HR entry or a biometric import, and
 * refused the same way inside a closed cutoff.
 */
class TimeClockService
{
    public function __construct(
        private readonly TimekeepingService $timekeeping,
        private readonly FaceMatcher $faces,
    ) {}

    /**
     * Where the employee's day stands, and what they may press next.
     *
     * @return array{state: string, log: ?AttendanceLog, next: array<int, string>}
     */
    public function state(Employee $employee, ?Carbon $now = null): array
    {
        $now ??= now();
        $open = $this->openLog($employee, $now);

        if ($open) {
            $state = match (true) {
                $open->break_start && ! $open->break_end => 'on_break',
                default => 'working',
            };

            $next = match ($state) {
                'on_break' => [AttendancePunch::ACTION_BREAK_END],
                default => $open->break_start
                    ? [AttendancePunch::ACTION_TIME_OUT]
                    : [AttendancePunch::ACTION_BREAK_START, AttendancePunch::ACTION_TIME_OUT],
            };

            return ['state' => $state, 'log' => $open, 'next' => $next];
        }

        $today = $this->timekeeping->logOn($employee, $now);

        if ($today?->time_in && $today->time_out) {
            return ['state' => 'done', 'log' => $today, 'next' => []];
        }

        // Timed in today but too long ago to still be open: a forgotten
        // time-out. Timing in again would overwrite the morning's punch, so
        // the day waits for a correction instead.
        if ($today?->time_in) {
            return ['state' => 'stale', 'log' => $today, 'next' => []];
        }

        return ['state' => 'out', 'log' => $today, 'next' => [AttendancePunch::ACTION_TIME_IN]];
    }

    /**
     * Records one press of the clock, after checking the face.
     *
     * A face that does not match writes an audit row *before* refusing — a
     * stranger's face against somebody's account is the event this whole
     * feature exists to catch, and a refusal that left no trace would let
     * somebody try until the light was kind to them.
     */
    public function punch(
        Employee $employee,
        string $action,
        array $descriptor,
        ?UploadedFile $snapshot,
        Request $request,
    ): AttendancePunch {
        $enrollment = $employee->faceEnrollment;

        if (! $enrollment) {
            throw ValidationException::withMessages([
                'face' => 'Your face is not enrolled yet. Ask HR to enroll you before using the time clock.',
            ]);
        }

        $distance = $this->faces->distance($enrollment, $descriptor);

        if ($distance > (float) config('timeclock.match_threshold')) {
            $this->auditRejection($employee, $action, $distance, $request);

            throw ValidationException::withMessages([
                'face' => 'Your face did not match the one enrolled for this account. Face the camera in good light and try again.',
            ]);
        }

        return $this->record($employee, $action, $distance, $snapshot, $request, null);
    }

    /**
     * A shared kiosk — HR's laptop at the office door — where the face says
     * who is punching. Nobody chooses the employee: the nearest enrolled face
     * does, and only when it is clearly nearer than the next (see
     * `FaceMatcher::identify()`). The operator signed in on the kiosk is kept
     * on the punch, so "whose session recorded this" is never a guess.
     */
    public function punchAtKiosk(
        string $action,
        array $descriptor,
        ?UploadedFile $snapshot,
        Request $request,
    ): AttendancePunch {
        $match = $this->faces->identify($descriptor);

        if (! $match['enrollment']) {
            AuditLog::create([
                'user_id' => $request->user()?->id,
                'auditable_type' => FaceEnrollment::class,
                'auditable_id' => null,
                'event' => 'clock_face_unrecognised',
                'old_values' => null,
                'new_values' => ['action' => $action, 'ambiguous' => $match['ambiguous']],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            throw ValidationException::withMessages([
                'face' => $match['ambiguous']
                    ? 'The face is too close to more than one enrolled employee. Face the camera directly, in good light, and try again.'
                    : 'This face is not enrolled. Ask HR to enroll it first, or try again in better light.',
            ]);
        }

        return $this->record(
            $match['enrollment']->employee,
            $action,
            $match['distance'],
            $snapshot,
            $request,
            $request->user(),
        );
    }

    /**
     * Enrolls (or re-enrolls) an employee's face.
     *
     * Anybody with a 201 file may enroll their own, in any role — on the
     * owner's request — but **only once**. The first enrollment is the
     * person's own face on their own login; replacing it is HR's, because a
     * self-service re-enrollment is exactly how somebody holding a colleague's
     * password would swap in their own face and clock in for them. HR enrolls
     * and re-enrolls everybody else. Who did it is kept in `enrolled_by`, so
     * HR's list can tell a self-enrollment from one they witnessed.
     *
     * @param  array<int, array<int, float>>  $descriptors
     */
    public function enroll(Employee $employee, array $descriptors, ?UploadedFile $snapshot, User $by): FaceEnrollment
    {
        if ($employee->user_id !== null && $employee->user_id === $by->id && $employee->faceEnrollment) {
            throw ValidationException::withMessages([
                'employee' => 'Your face is already enrolled. Ask HR to re-enroll it if it needs to change.',
            ]);
        }

        $disk = config('timeclock.snapshot_disk');
        $path = $snapshot ? (new PhotoStore($disk))->store($snapshot, "face-enrollments/{$employee->id}") : null;
        $previous = $employee->faceEnrollment?->snapshot_path;

        $enrollment = FaceEnrollment::updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'descriptors' => array_map(fn (array $sample) => $this->faces->normalise($sample), $descriptors),
                'snapshot_path' => $path,
                'consented_at' => now(),
                'enrolled_by' => $by->id,
            ],
        );

        if ($previous && $previous !== $path) {
            Storage::disk($disk)->delete($previous);
        }

        return $enrollment;
    }

    public function removeEnrollment(FaceEnrollment $enrollment): void
    {
        if ($enrollment->snapshot_path) {
            Storage::disk(config('timeclock.snapshot_disk'))->delete($enrollment->snapshot_path);
        }

        $enrollment->delete();
    }

    /**
     * Deletes punch snapshots past the retention window. The punch rows and
     * their times stay — they are the record; the picture was the evidence
     * for a dispute that has had its chance to be raised.
     */
    public function pruneSnapshots(): int
    {
        $disk = Storage::disk(config('timeclock.snapshot_disk'));
        $pruned = 0;

        AttendancePunch::query()
            ->whereNotNull('snapshot_path')
            ->where('punched_at', '<', now()->subDays((int) config('timeclock.snapshot_retention_days')))
            ->chunkById(200, function ($punches) use ($disk, &$pruned) {
                foreach ($punches as $punch) {
                    $disk->delete($punch->snapshot_path);
                    $punch->update(['snapshot_path' => null]);
                    $pruned++;
                }
            });

        return $pruned;
    }

    private function record(
        Employee $employee,
        string $action,
        float $distance,
        ?UploadedFile $snapshot,
        Request $request,
        ?User $operator,
    ): AttendancePunch {
        $now = now();

        try {
            $this->assertAllowed($employee, $action, $now);
        } catch (ValidationException $refused) {
            // On a kiosk the message has to say whose day it is about.
            if ($operator) {
                throw ValidationException::withMessages([
                    'action' => $employee->full_name.': '.collect($refused->errors())->flatten()->first(),
                ]);
            }

            throw $refused;
        }

        $path = $snapshot ? (new PhotoStore(config('timeclock.snapshot_disk')))->store($snapshot, "time-clock/{$employee->id}") : null;

        try {
            return DB::transaction(function () use ($employee, $action, $now, $distance, $path, $request, $operator) {
                $log = $this->apply($employee, $action, $now, $request->user());

                return AttendancePunch::create([
                    'employee_id' => $employee->id,
                    'attendance_log_id' => $log->id,
                    'action' => $action,
                    'punched_at' => $now,
                    'match_distance' => round($distance, 4),
                    'snapshot_path' => $path,
                    'operator_id' => $operator?->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 255),
                ]);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk(config('timeclock.snapshot_disk'))->delete($path);
            }

            throw $exception;
        }
    }

    /** A day timed in recently enough to still be the one being worked, and not yet timed out. */
    private function openLog(Employee $employee, Carbon $now): ?AttendanceLog
    {
        return AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('time_in')
            ->whereNull('time_out')
            ->where('time_in', '>=', $now->copy()->subHours((int) config('timeclock.max_shift_hours')))
            ->where('time_in', '<=', $now)
            ->latest('time_in')
            ->first();
    }

    /** Refuses a press that is out of order — out before in, a second break, and so on. */
    private function assertAllowed(Employee $employee, string $action, Carbon $now): void
    {
        $state = $this->state($employee, $now);

        if (in_array($action, $state['next'], true)) {
            return;
        }

        $message = match (true) {
            $state['state'] === 'stale' => 'Your time-in at '.$state['log']->time_in->format('g:i A').' has no time-out. File a time correction for today.',
            $action === AttendancePunch::ACTION_TIME_IN && $state['state'] === 'done' => 'You have already timed in and out today.',
            $action === AttendancePunch::ACTION_TIME_IN => 'You are already timed in since '.$state['log']->time_in->format('g:i A').'.',
            $state['state'] === 'out' => 'You have not timed in yet. Press Time in first.',
            $state['state'] === 'done' => 'You have already timed out today.',
            $action === AttendancePunch::ACTION_TIME_OUT && $state['state'] === 'on_break' => 'You are on break. Press End break before timing out.',
            $action === AttendancePunch::ACTION_BREAK_START && $state['log']?->break_end => 'You have already taken your break today.',
            $action === AttendancePunch::ACTION_BREAK_START => 'You are already on break.',
            $action === AttendancePunch::ACTION_BREAK_END => 'You have not started a break.',
            default => 'That punch is not available right now.',
        };

        throw ValidationException::withMessages(['action' => $message]);
    }

    private function apply(Employee $employee, string $action, Carbon $now, ?User $by): AttendanceLog
    {
        if ($action === AttendancePunch::ACTION_TIME_IN) {
            return $this->timekeeping->writeDay(
                $employee, $now, $now, null, null, null, AttendanceLog::SOURCE_FACE_CLOCK, null, $by,
            );
        }

        $log = $this->openLog($employee, $now);

        return $this->timekeeping->writeDay(
            $employee,
            $log->work_date,
            $log->time_in,
            $action === AttendancePunch::ACTION_TIME_OUT ? $now : null,
            $action === AttendancePunch::ACTION_BREAK_START ? $now : $log->break_start,
            $action === AttendancePunch::ACTION_BREAK_END ? $now : $log->break_end,
            AttendanceLog::SOURCE_FACE_CLOCK,
            $log->remarks,
            $by,
        );
    }

    private function auditRejection(Employee $employee, string $action, float $distance, Request $request): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'auditable_type' => Employee::class,
            'auditable_id' => $employee->id,
            'event' => 'clock_face_rejected',
            'old_values' => null,
            'new_values' => ['action' => $action, 'distance' => round($distance, 4)],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
