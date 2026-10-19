<?php

namespace App\Http\Controllers\Timekeeping;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Services\EmployeeService;
use App\Services\TimekeepingService;
use App\Services\WorkCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daily Time Records, read per employee.
 *
 * The screen opens on the roster — each employee in scope with their totals
 * for the range — and an employee opens their DTR: every date in the range,
 * recorded or not, with the punches and what they came to. Somebody whose
 * scope is themselves goes straight to their own.
 *
 * A status filter with nobody chosen still lists the matching days across
 * everyone, because that is what the tiles here and on the dashboard count
 * ("No time-out", "Late days") and a figure has to open the rows it counted.
 *
 * Everybody can open it: HR sees everyone, a supervisor the team, an employee
 * their own days. Only HR writes; everyone else files a correction.
 */
class TimeRecordController extends Controller
{
    /** A DTR longer than this is a report, not a sheet somebody signs. */
    private const SHEET_MAX_DAYS = 93;

    public function __construct(
        private readonly TimekeepingService $timekeeping,
        private readonly EmployeeService $employees,
        private readonly WorkCalendar $calendar,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AttendanceLog::class);

        $user = $request->user();
        $from = $this->date($request->input('from')) ?? now()->startOfMonth();
        $to = $this->date($request->input('to')) ?? now()->endOfMonth();

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        $filters = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'status' => in_array($request->input('status'), AttendanceLog::STATUSES, true) ? $request->input('status') : null,
            'employee' => $request->integer('employee') ?: null,
        ];

        $base = $this->timekeeping->scopedLogs($user)
            ->between($filters['from'], $filters['to'])
            ->when($filters['employee'], fn ($query, $id) => $query->where('employee_id', $id));

        $summary = (clone $base)
            ->selectRaw('status, count(*) as days, sum(late_minutes) as late, sum(minutes_worked) as minutes')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $locked = $this->timekeeping->lockedRanges();
        $options = $this->timekeeping->employeeOptions($user);

        // Somebody who can only see themselves has nobody to choose between.
        if (! $filters['employee'] && ! $filters['status']
            && count($options) === 1 && $options[0]['value'] === $user->employee?->id) {
            $filters['employee'] = $options[0]['value'];
        }

        $view = match (true) {
            (bool) $filters['employee'] => 'employee',
            (bool) $filters['status'] || $request->input('view') === 'days' => 'days',
            default => 'roster',
        };

        $worked = collect(AttendanceLog::WORKED_STATUSES)->sum(fn ($status) => (int) ($summary[$status]->days ?? 0));

        return Inertia::render('HR/Timekeeping/Records', [
            'view' => $view,
            'logs' => $view === 'days' ? $this->days($base, $filters, $locked) : null,
            'roster' => $view === 'roster' ? $this->roster($request, $filters) : null,
            'sheet' => $view === 'employee' ? $this->sheet($request, $filters, $locked) : null,
            'search' => (string) $request->input('search', ''),
            'filters' => $filters,
            'summary' => [
                'worked' => $worked,
                'late' => (int) ($summary[AttendanceLog::STATUS_LATE]->days ?? 0),
                'absent' => (int) ($summary[AttendanceLog::STATUS_ABSENT]->days ?? 0),
                'incomplete' => (int) ($summary[AttendanceLog::STATUS_INCOMPLETE]->days ?? 0),
                'hours' => round($summary->sum('minutes') / 60, 1),
            ],
            'statuses' => collect(AttendanceLog::STATUSES)->map(fn ($status) => [
                'value' => $status,
                'label' => ucwords(str_replace('_', ' ', $status)),
            ])->values(),
            'employees' => $options,
            'can' => [
                'manage' => $user->can('manage', AttendanceLog::class),
                'fileCorrection' => $user->employee !== null,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $data = $this->validated($request, true);
        $employee = Employee::findOrFail($data['employee_id']);

        $log = $this->timekeeping->record(
            $employee,
            Carbon::parse($data['work_date']),
            $data['time_in'] ?? null,
            $data['time_out'] ?? null,
            AttendanceLog::SOURCE_MANUAL,
            $data['remarks'] ?? null,
            $request->user(),
        );

        return back()->with('success', "Saved {$employee->full_name}'s record for {$log->work_date->format('M j, Y')} ({$this->label($log->status)}).");
    }

    public function update(Request $request, AttendanceLog $record): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $data = $this->validated($request, false);

        $log = $this->timekeeping->record(
            $record->employee,
            $record->work_date,
            $data['time_in'] ?? null,
            $data['time_out'] ?? null,
            AttendanceLog::SOURCE_MANUAL,
            $data['remarks'] ?? null,
            $request->user(),
        );

        return back()->with('success', "Updated the record for {$log->work_date->format('M j, Y')} ({$this->label($log->status)}).");
    }

    public function destroy(AttendanceLog $record): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $this->timekeeping->delete($record);

        return back()->with('success', 'Time record deleted.');
    }

    public function import(Request $request): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $result = $this->timekeeping->import($request->file('file'), $request->user());
        $skipped = count($result['skipped']);

        $flash = back()->with('success', "Imported {$result['imported']} day(s)".($skipped ? ", skipped {$skipped}." : '.'));

        // The rows that did not land are the ones somebody has to fix, so name them.
        return $skipped ? $flash->with('error', implode(' ', array_slice($result['skipped'], 0, 5)).($skipped > 5 ? ' …and '.($skipped - 5).' more.' : '')) : $flash;
    }

    /** Every matching day across everyone — what a status tile counted. */
    private function days($base, array $filters, $locked)
    {
        return (clone $base)
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('work_date')
            ->orderBy('employee_id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AttendanceLog $log) => $this->row($log, $locked));
    }

    /** Each employee in scope with their totals for the range. */
    private function roster(Request $request, array $filters)
    {
        $search = trim((string) $request->input('search'));

        // Active staff, plus anybody who has left but still has days in the
        // range — their days are still somebody's to pay or to close.
        $page = $this->employees->scopedQuery($request->user())
            ->where(fn ($query) => $query
                ->where('status', '!=', 'inactive')
                ->orWhereHas('attendanceLogs', fn ($logs) => $logs->between($filters['from'], $filters['to'])))
            ->when($search !== '', fn ($query) => $query->search($search))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20)
            ->withQueryString();

        $totals = AttendanceLog::query()
            ->whereIn('employee_id', $page->getCollection()->pluck('id'))
            ->between($filters['from'], $filters['to'])
            ->selectRaw('employee_id, status, count(*) as days, sum(minutes_worked) as minutes, sum(late_minutes) as late, sum(undertime_minutes) as undertime, sum(overtime_minutes) as overtime')
            ->groupBy('employee_id', 'status')
            ->get()
            ->groupBy('employee_id');

        return $page->through(function (Employee $employee) use ($totals) {
            $rows = $totals->get($employee->id, collect());
            $days = fn (string $status) => (int) ($rows->firstWhere('status', $status)->days ?? 0);

            return [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'number' => $employee->employee_number,
                'position' => $employee->position?->title,
                'assignment' => $employee->client?->name ?? $employee->department?->name,
                'recorded' => (int) $rows->sum('days'),
                'worked' => collect(AttendanceLog::WORKED_STATUSES)->sum($days),
                'late' => $days(AttendanceLog::STATUS_LATE),
                'absent' => $days(AttendanceLog::STATUS_ABSENT),
                'incomplete' => $days(AttendanceLog::STATUS_INCOMPLETE),
                'hours' => round($rows->sum('minutes') / 60, 2),
                'late_minutes' => (int) $rows->sum('late'),
                'overtime_minutes' => (int) $rows->sum('overtime'),
            ];
        });
    }

    /**
     * One employee's DTR: every date in the range, so a day with no record
     * shows as a gap rather than silently missing. A blank working day is "no
     * record", never "absent" — absence is a status somebody recorded.
     *
     * @return array<string, mixed>|null
     */
    private function sheet(Request $request, array $filters, $locked): ?array
    {
        $employee = $this->employees->scopedQuery($request->user())->find($filters['employee']);

        if (! $employee) {
            return null;
        }

        $from = Carbon::parse($filters['from']);
        $to = Carbon::parse($filters['to']);
        $truncated = $from->diffInDays($to) + 1 > self::SHEET_MAX_DAYS;

        if ($truncated) {
            $from = $to->copy()->subDays(self::SHEET_MAX_DAYS - 1);
        }

        $logs = AttendanceLog::query()
            ->with('shift:id,code,name')
            ->withCount('punches')
            ->where('employee_id', $employee->id)
            ->between($from->toDateString(), $to->toDateString())
            ->get()
            ->keyBy(fn (AttendanceLog $log) => $log->work_date->toDateString());

        $days = [];

        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            $log = $logs->get($date->toDateString());

            if ($filters['status'] && $log?->status !== $filters['status']) {
                continue;
            }

            $days[] = [
                'date' => $date->toDateString(),
                'day' => $date->format('D'),
                'holiday' => $this->calendar->holidayOn($date)?->name,
                'rest_day' => $this->calendar->isRestDay($employee, $date),
                'future' => $date->isAfter(today()),
                'locked' => $this->timekeeping->isLockedIn($locked, $date),
                'log' => $log ? [
                    ...$this->row($log, $locked),
                    'break_start' => $log->break_start?->format('H:i'),
                    'break_end' => $log->break_end?->format('H:i'),
                    'punches' => $log->punches_count,
                ] : null,
            ];
        }

        $shift = $this->calendar->scheduleFor($employee, $to->isAfter(today()) ? today() : $to)?->shift;

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'number' => $employee->employee_number,
                'position' => $employee->position?->title,
                'assignment' => $employee->client?->name ?? $employee->department?->name,
                'shift' => $shift
                    ? $shift->name.' · '.substr((string) $shift->start_time, 0, 5).'–'.substr((string) $shift->end_time, 0, 5)
                    : null,
            ],
            'from' => $from->toDateString(),
            'truncated' => $truncated,
            'days' => $days,
            'totals' => [
                'worked' => $logs->whereIn('status', AttendanceLog::WORKED_STATUSES)->count(),
                'absent' => $logs->where('status', AttendanceLog::STATUS_ABSENT)->count(),
                'hours' => round($logs->sum('minutes_worked') / 60, 2),
                'late_minutes' => (int) $logs->sum('late_minutes'),
                'undertime_minutes' => (int) $logs->sum('undertime_minutes'),
                'overtime_minutes' => (int) $logs->sum('overtime_minutes'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function row(AttendanceLog $log, $locked): array
    {
        return [
            'id' => $log->id,
            'employee' => [
                'id' => $log->employee?->id,
                'name' => $log->employee?->full_name,
                'number' => $log->employee?->employee_number,
            ],
            'work_date' => $log->work_date->toDateString(),
            'day' => $log->work_date->format('D'),
            'shift' => $log->shift?->code,
            'time_in' => $log->time_in?->format('H:i'),
            'time_out' => $log->time_out?->format('H:i'),
            'next_day_out' => $log->time_out && $log->time_out->toDateString() !== $log->work_date->toDateString(),
            'status' => $log->status,
            'hours' => round($log->minutes_worked / 60, 2),
            'late_minutes' => $log->late_minutes,
            'undertime_minutes' => $log->undertime_minutes,
            'overtime_minutes' => $log->overtime_minutes,
            'night_diff_minutes' => $log->night_diff_minutes,
            'source' => $log->source,
            'remarks' => $log->remarks,
            'locked' => $this->timekeeping->isLockedIn($locked, $log->work_date),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'employee_id' => [Rule::requiredIf($creating), 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'work_date' => [Rule::requiredIf($creating), 'date', 'before_or_equal:today'],
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function label(string $status): string
    {
        return str_replace('_', ' ', $status);
    }
}
