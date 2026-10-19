<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Holiday;
use Illuminate\Support\Carbon;

/**
 * One employee's month, a day at a time — the attendance calendar.
 *
 * Built once here and read by two places: the calendar screen and the email
 * that sends the same month to the employee's inbox. They must not be two
 * copies, because the one thing worse than no emailed DTR is an emailed DTR
 * that disagrees with the screen the employee is told to check it against.
 *
 * **Nothing is judged here.** Whether a punch was late or a time-out early is
 * the `AttendanceCalculator`'s figure already stored on the row
 * (`late_minutes`, `undertime_minutes`); this class only reads it out. And a
 * past working day with no row is "no record", not "absent" — absence is a
 * status somebody recorded, and inventing one here would put an absence in an
 * employee's inbox that no DTR, and no payslip, contains.
 */
class AttendanceCalendar
{
    public function __construct(private readonly WorkCalendar $calendar) {}

    /**
     * @return array{
     *     month: string, label: string, previous: string, next: string,
     *     leading_blanks: int, days: array<int, array<string, mixed>>,
     *     summary: array<string, int|float>
     * }
     */
    public function month(Employee $employee, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth()->startOfDay();

        $logs = AttendanceLog::query()
            ->with('shift:id,code,name,start_time,end_time')
            ->where('employee_id', $employee->id)
            ->between($start->toDateString(), $end->toDateString())
            ->get()
            ->keyBy(fn (AttendanceLog $log) => $log->work_date->toDateString());

        // Regular sorts before special, the same order WorkCalendar reads them in.
        $holidays = Holiday::query()
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->orderBy('type')
            ->get()
            ->unique(fn (Holiday $holiday) => $holiday->date->toDateString())
            ->keyBy(fn (Holiday $holiday) => $holiday->date->toDateString());

        $today = now()->toDateString();
        $days = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->toDateString();
            $days[] = $this->day($employee, $date, $logs->get($key), $holidays->get($key), $key > $today);
        }

        return [
            'month' => $start->format('Y-m'),
            'label' => $start->format('F Y'),
            'previous' => $start->copy()->subMonth()->format('Y-m'),
            'next' => $start->copy()->addMonth()->format('Y-m'),
            // Monday-first, like the Leave calendar and the biometric app HR is used to.
            'leading_blanks' => $start->isoWeekday() - 1,
            'days' => $days,
            'summary' => $this->summary($logs->values()->all()),
        ];
    }

    /** @return array<string, mixed> */
    private function day(Employee $employee, Carbon $date, ?AttendanceLog $log, ?Holiday $holiday, bool $future): array
    {
        // The shift the day was computed against when there is a row; the
        // schedule in force otherwise, so an empty day still says what was due.
        $shift = $log?->shift ?? $this->calendar->scheduleFor($employee, $date)?->shift;
        $restDay = $log ? $log->status === AttendanceLog::STATUS_REST_DAY : $this->calendar->isRestDay($employee, $date);

        return [
            'date' => $date->toDateString(),
            'day' => $date->day,
            'weekday' => $date->format('D'),
            'future' => $future,
            'status' => $log?->status,
            'holiday' => $holiday?->name,
            'rest_day' => $restDay,
            'shift' => $shift ? [
                'code' => $shift->code,
                'name' => $shift->name,
                'start' => substr((string) $shift->start_time, 0, 5),
                'end' => substr((string) $shift->end_time, 0, 5),
            ] : null,
            'time_in' => $log?->time_in?->format('H:i'),
            'time_out' => $log?->time_out?->format('H:i'),
            'next_day_out' => $log?->time_out !== null
                && $log->time_out->toDateString() !== $log->work_date->toDateString(),
            'hours' => $log ? round($log->minutes_worked / 60, 1) : 0,
            'late_minutes' => (int) ($log?->late_minutes ?? 0),
            'undertime_minutes' => (int) ($log?->undertime_minutes ?? 0),
            'overtime_minutes' => (int) ($log?->overtime_minutes ?? 0),
            'night_diff_minutes' => (int) ($log?->night_diff_minutes ?? 0),
            'source' => $log?->source,
            'remarks' => $log?->remarks,
        ];
    }

    /**
     * Counted the way Daily Time Records counts its own tiles, so a tile on
     * the calendar that links there opens the same number of rows.
     *
     * @param  array<int, AttendanceLog>  $logs
     * @return array<string, int|float>
     */
    private function summary(array $logs): array
    {
        $logs = collect($logs);

        return [
            'worked' => $logs->whereIn('status', AttendanceLog::WORKED_STATUSES)->count(),
            'late' => $logs->where('status', AttendanceLog::STATUS_LATE)->count(),
            'absent' => $logs->where('status', AttendanceLog::STATUS_ABSENT)->count(),
            'incomplete' => $logs->where('status', AttendanceLog::STATUS_INCOMPLETE)->count(),
            'on_leave' => $logs->where('status', AttendanceLog::STATUS_ON_LEAVE)->count(),
            'hours' => round($logs->sum('minutes_worked') / 60, 1),
            'late_minutes' => (int) $logs->sum('late_minutes'),
        ];
    }
}
