<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Module 2 — Timekeeping & Attendance.
 *
 * Owns persistence and scoping; the arithmetic lives in AttendanceCalculator.
 */
class TimekeepingService
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly EmployeeService $employees,
    ) {}

    /** DTR rows the viewer is allowed to see, mirroring the employee directory. */
    public function scopedQuery(User $user): Builder
    {
        return AttendanceLog::query()
            ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix,department_id', 'shift:id,name,start_time,end_time'])
            ->whereIn('employee_id', $this->employees->scopedQuery($user)->select('employees.id'));
    }

    /**
     * Creates or updates the single DTR row for an employee/date, recomputing
     * every derived figure from the punches.
     */
    public function record(Employee $employee, array $data): AttendanceLog
    {
        $date = Carbon::parse($data['log_date'])->startOfDay();

        $shift = isset($data['shift_id'])
            ? Shift::find($data['shift_id'])
            : $this->resolveShift($employee, $date);

        $computed = $this->calculator->compute(
            date: $date,
            shift: $shift,
            timeIn: $this->punch($date, $data['time_in'] ?? null),
            timeOut: $this->punch($date, $data['time_out'] ?? null, $shift),
            breakOut: $this->punch($date, $data['break_out'] ?? null),
            breakIn: $this->punch($date, $data['break_in'] ?? null),
            isRestDay: $this->isRestDay($employee, $date),
            isHoliday: $this->isHoliday($date),
        );

        // An explicitly chosen status (on_leave, absent) wins over the derived one.
        if (! empty($data['status'])) {
            $computed['status'] = $data['status'];
        }

        $attributes = [
            ...$computed,
            'shift_id' => $shift?->id,
            'time_in' => $this->punch($date, $data['time_in'] ?? null),
            'time_out' => $this->punch($date, $data['time_out'] ?? null, $shift),
            'break_out' => $this->punch($date, $data['break_out'] ?? null),
            'break_in' => $this->punch($date, $data['break_in'] ?? null),
            'source' => $data['source'] ?? 'manual',
            'remarks' => $data['remarks'] ?? null,
            'biometric_device_id' => $data['biometric_device_id'] ?? null,
        ];

        // updateOrCreate matches on exact column equality, which misses rows
        // whose date is stored with a time component. whereDate compares the
        // date part on every driver.
        $existing = AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('log_date', $date)
            ->first();

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return AttendanceLog::create([
            ...$attributes,
            'employee_id' => $employee->id,
            'log_date' => $date,
        ]);
    }

    /** The shift in force for this employee on this date, if any. */
    public function resolveShift(Employee $employee, Carbon $date): ?Shift
    {
        $schedule = EmployeeSchedule::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->get()
            ->first(fn (EmployeeSchedule $candidate) => $candidate->coversDate($date));

        return $schedule?->shift;
    }

    /** True when the employee has a schedule, but none covering this weekday. */
    public function isRestDay(Employee $employee, Carbon $date): bool
    {
        $hasSchedule = EmployeeSchedule::where('employee_id', $employee->id)
            ->effectiveOn($date)
            ->exists();

        return $hasSchedule && $this->resolveShift($employee, $date) === null;
    }

    public function isHoliday(Carbon $date): bool
    {
        return Holiday::whereDate('date', $date)->exists();
    }

    /** Headline figures for the filtered DTR range. */
    public function summary(Builder $query): array
    {
        $rows = (clone $query)->reorder()->get([
            'status', 'late_minutes', 'undertime_minutes', 'overtime_minutes', 'hours_worked',
        ]);

        return [
            'records' => $rows->count(),
            'present' => $rows->whereIn('status', [
                AttendanceLog::STATUS_PRESENT,
                AttendanceLog::STATUS_LATE,
                AttendanceLog::STATUS_UNDERTIME,
            ])->count(),
            'absent' => $rows->where('status', AttendanceLog::STATUS_ABSENT)->count(),
            'late' => $rows->where('late_minutes', '>', 0)->count(),
            'total_hours' => round((float) $rows->sum('hours_worked'), 2),
            'overtime_hours' => round($rows->sum('overtime_minutes') / 60, 2),
            'late_minutes' => (int) $rows->sum('late_minutes'),
            'undertime_minutes' => (int) $rows->sum('undertime_minutes'),
        ];
    }

    /**
     * Combines a date with a "HH:MM" time. A time-out that lands before the
     * time-in belongs to the following day (night shifts).
     */
    private function punch(Carbon $date, ?string $time, ?Shift $shift = null): ?Carbon
    {
        if (blank($time)) {
            return null;
        }

        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');
        $punch = $date->copy()->setTime((int) $hour, (int) $minute, 0);

        if ($shift?->crossesMidnight() && (int) $hour < (int) explode(':', $shift->start_time)[0]) {
            $punch->addDay();
        }

        return $punch;
    }
}
