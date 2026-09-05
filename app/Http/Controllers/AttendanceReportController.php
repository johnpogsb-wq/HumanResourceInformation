<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Services\DataAccessLogger;
use App\Services\TimekeepingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 2 — attendance summary reports (daily / weekly / monthly).
 */
class AttendanceReportController extends Controller
{
    private const PERIODS = ['daily', 'weekly', 'monthly', 'custom'];

    public function __construct(private readonly TimekeepingService $timekeeping) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AttendanceLog::class);

        $filters = $this->filters($request);
        $query = $this->timekeeping->scopedQuery($request->user())->filter($filters);

        return Inertia::render('HR/Timekeeping/Reports', [
            'rows' => $this->timekeeping->employeeSummaries($query),
            'summary' => $this->timekeeping->summary($query),
            'filters' => $filters,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'periods' => self::PERIODS,
        ]);
    }

    /** Same report as the screen, streamed as CSV for payroll hand-off. */
    public function export(Request $request, DataAccessLogger $access): StreamedResponse
    {
        Gate::authorize('viewAny', AttendanceLog::class);

        $filters = $this->filters($request);
        $query = $this->timekeeping->scopedQuery($request->user())->filter($filters);
        $rows = $this->timekeeping->employeeSummaries($query);

        // The range is what makes this row answer anything: "someone exported
        // attendance" is noise, "someone exported the whole of August" is not.
        $access->exported('attendance-report', AttendanceLog::class, [
            'from' => $filters['from'],
            'to' => $filters['to'],
            'employees' => $rows->count(),
        ]);

        $filename = "attendance-{$filters['from']}-to-{$filters['to']}.csv";

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Employee Number', 'Employee', 'Department', 'Days Present', 'Days Absent',
                'Late Count', 'Late Minutes', 'Undertime Minutes', 'Overtime Hours',
                'Night Diff Hours', 'Total Hours',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['employee_number'],
                    $row['full_name'],
                    $row['department'] ?? '',
                    $row['days_present'],
                    $row['days_absent'],
                    $row['late_count'],
                    $row['late_minutes'],
                    $row['undertime_minutes'],
                    $row['overtime_hours'],
                    $row['night_diff_hours'],
                    $row['total_hours'],
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Resolves the requested period into a concrete date range so the screen and
     * the export can never disagree about what was counted.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $period = in_array($request->query('period'), self::PERIODS, true)
            ? $request->query('period')
            : 'monthly';

        $anchor = $request->query('anchor')
            ? Carbon::parse($request->query('anchor'))
            : Carbon::today();

        [$from, $to] = match ($period) {
            'daily' => [$anchor->copy(), $anchor->copy()],
            'weekly' => [$anchor->copy()->startOfWeek(), $anchor->copy()->endOfWeek()],
            'monthly' => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
            'custom' => [
                Carbon::parse($request->query('from', $anchor->copy()->startOfMonth())),
                Carbon::parse($request->query('to', $anchor->copy()->endOfMonth())),
            ],
        };

        return [
            'period' => $period,
            'anchor' => $anchor->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'department_id' => $request->query('department_id'),
        ];
    }
}
