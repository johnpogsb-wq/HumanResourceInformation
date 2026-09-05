<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollRun;
use App\Models\PerformanceReview;
use App\Services\EmployeeService;
use App\Services\LeaveService;
use App\Services\PerformanceScorer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * What the payroll tile shows a role that may not read company figures.
     *
     * A zeroed shape rather than a null, because the tile is still drawn — an
     * employee sees "Latest Payroll —", the same as before a run exists, and
     * learns nothing about what the company paid.
     */
    private const NO_PAYROLL = [
        'total_net' => 0,
        'period' => null,
        'status' => null,
    ];

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly LeaveService $leave,
        private readonly PerformanceScorer $scorer,
    ) {}

    public function __invoke(Request $request): Response
    {
        $scoped = $this->employees->scopedQuery($request->user());
        $today = Carbon::today();

        /*
         * Company-wide figures — total payroll, everyone's leave, the status
         * mix — are HR's view of the organisation, not an employee's view of
         * themselves. `EmployeePolicy::viewSensitive` already draws this line
         * for salary on a record; the dashboard has to draw the same one, or
         * a rank-and-file login reads the month's total net off the landing
         * page.
         */
        $canViewCompanyFigures = $request->user()->isHrAdmin();

        return Inertia::render('Dashboard', [
            'can' => ['viewCompanyFigures' => $canViewCompanyFigures],
            'statistics' => $this->statistics($scoped),
            'headcountByDepartment' => $this->headcountByDepartment(),
            'headcountTrend' => $this->headcountTrend($today),
            'statusMix' => $this->statusMix(),
            'attendanceToday' => $this->attendanceToday($today),
            'leaveToday' => $this->leaveToday($today),
            'approvals' => $this->approvals($request),
            'payroll' => $canViewCompanyFigures ? $this->latestPayroll() : self::NO_PAYROLL,
            'leaveSummary' => $canViewCompanyFigures ? $this->leaveSummary($today) : null,
            'payrollSummary' => $canViewCompanyFigures ? $this->payrollSummary() : null,
            'onboardingSummary' => $this->onboardingSummary($scoped, $today),
            'recentHires' => (clone $scoped)
                ->whereNotNull('date_hired')
                ->orderByDesc('date_hired')
                ->limit(6)
                ->get()
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                    'position' => $employee->position?->title,
                    'date_hired' => $employee->date_hired?->toDateString(),
                ]),
        ]);
    }

    /**
     * Active headcount at the close of each of the last twelve months.
     *
     * Cumulative rather than hires-per-month: with a workforce this size most
     * individual months would be a zero, and a chart that is mostly zero shows
     * nothing. Read from `date_hired` against the whole table, so it does not
     * depend on attendance having been recorded.
     *
     * @return array<int, array{label: string, value: int}>
     */
    private function headcountTrend(Carbon $today): array
    {
        // One query, then counted in PHP — twelve separate COUNTs would be
        // twelve round trips for a figure this small.
        $hires = Employee::whereNotNull('date_hired')
            ->pluck('date_hired')
            ->map(fn ($date) => Carbon::parse($date));

        $separations = Employee::whereNotNull('date_separated')
            ->pluck('date_separated')
            ->map(fn ($date) => Carbon::parse($date));

        $months = [];

        for ($offset = 11; $offset >= 0; $offset--) {
            $endOfMonth = $today->copy()->subMonths($offset)->endOfMonth();

            $months[] = [
                'label' => $endOfMonth->format('M'),
                'value' => $hires->filter(fn (Carbon $date) => $date->lte($endOfMonth))->count()
                    - $separations->filter(fn (Carbon $date) => $date->lte($endOfMonth))->count(),
            ];
        }

        return $months;
    }

    /**
     * Leave activity this month, plus the request most recently filed.
     *
     * @return array<string, mixed>
     */
    private function leaveSummary(Carbon $today): array
    {
        $monthStart = $today->copy()->startOfMonth();

        $counts = LeaveRequest::where('created_at', '>=', $monthStart)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $latest = LeaveRequest::with(['employee:id,first_name,middle_name,last_name,suffix', 'leaveType:id,name'])
            ->latest('id')
            ->first();

        return [
            'pending' => (int) ($counts[LeaveRequest::STATUS_PENDING] ?? 0),
            'approved' => (int) ($counts[LeaveRequest::STATUS_APPROVED] ?? 0),
            'rejected' => (int) ($counts[LeaveRequest::STATUS_REJECTED] ?? 0),
            'latest' => $latest === null ? null : [
                'id' => $latest->id,
                'title' => $latest->employee?->full_name ?? 'Unknown employee',
                'subtitle' => trim(sprintf(
                    '%s · %s',
                    $latest->leaveType?->name ?? 'Leave',
                    $latest->start_date?->format('M j, Y') ?? '',
                ), ' ·'),
                'status' => $latest->status,
            ],
        ];
    }

    /**
     * Where payroll stands, and the run most recently touched.
     *
     * @return array<string, mixed>
     */
    private function payrollSummary(): array
    {
        $counts = PayrollRun::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $latest = PayrollRun::with('period')->latest('id')->first();

        return [
            'draft' => (int) ($counts[PayrollRun::STATUS_DRAFT] ?? 0),
            'for_approval' => (int) ($counts[PayrollRun::STATUS_FOR_APPROVAL] ?? 0),
            // Only finalised runs are money that has actually moved — the same
            // rule PayrollRun::REPORTABLE holds for every downstream screen.
            'released' => PayrollRun::reportable()->count(),
            'latest' => $latest === null ? null : [
                'id' => $latest->id,
                'title' => $latest->period?->name ?? 'Payroll run',
                'subtitle' => 'Net '.number_format((float) $latest->total_net, 2),
                'status' => $latest->status,
            ],
        ];
    }

    /**
     * The 201-file health figures that are cheap to count directly.
     *
     * Deliberately *not* OnboardingChecker or CredentialExpiryScanner: those
     * walk every employee's documents to build a findings list, which is the
     * right shape for their own screens and the wrong one for a dashboard tile
     * that only needs three numbers.
     *
     * @return array<string, mixed>
     */
    private function onboardingSummary($scoped, Carbon $today): array
    {
        $newHires = (clone $scoped)
            ->where('date_hired', '>=', $today->copy()->subDays(30))
            ->count();

        $expiring = EmployeeDocument::whereNotNull('expires_at')
            ->whereBetween('expires_at', [$today, $today->copy()->addDays(60)])
            ->count();

        $withoutDocuments = (clone $scoped)
            ->where('status', 'active')
            ->whereDoesntHave('documents')
            ->count();

        $latest = (clone $scoped)
            ->whereNotNull('date_hired')
            ->orderByDesc('date_hired')
            ->first();

        return [
            'new_hires' => $newHires,
            'expiring' => $expiring,
            'without_documents' => $withoutDocuments,
            'latest' => $latest === null ? null : [
                'id' => $latest->id,
                'title' => $latest->full_name,
                'subtitle' => $latest->position?->title ?? 'No position assigned',
                'status' => $latest->employment_status,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function statistics($scoped): array
    {
        $base = $this->employees->statistics($scoped);

        // The company-wide average from the most recent scored cycle.
        $average = PerformanceReview::whereIn('status', [
            PerformanceReview::STATUS_SUBMITTED,
            PerformanceReview::STATUS_ACKNOWLEDGED,
        ])->whereNotNull('overall_rating')->avg('overall_rating');

        $average = $average !== null ? round((float) $average, 2) : null;

        $band = $this->scorer->band($average);

        return [
            ...$base,
            'average_rating' => $average,
            'performance_band' => $band['label'] ?? null,
            // The band already knows where the score sits on the ramp; the
            // dashboard reads it rather than deriving its own cut-offs.
            'performance_band_variant' => $band['variant'] ?? null,
            'headcount_change' => $this->headcountChange($scoped),
        ];
    }

    /**
     * Net joiners over the last 30 days — the delta shown under the headcount
     * tile.
     *
     * Returned as a signed integer with no percentage: against a workforce of
     * a few dozen, one hire is a swing of several percent, and a figure that
     * jumps like that reads as volatility rather than as information.
     */
    private function headcountChange($scoped): int
    {
        $since = Carbon::today()->subDays(30);

        $joined = (clone $scoped)->where('date_hired', '>=', $since)->count();
        $left = (clone $scoped)->where('date_separated', '>=', $since)->count();

        return $joined - $left;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function headcountByDepartment()
    {
        return Department::query()
            ->withCount(['employees' => fn ($query) => $query->where('status', 'active')])
            ->orderByDesc('employees_count')
            ->get(['id', 'name'])
            ->map(fn (Department $department) => [
                'name' => $department->name,
                'count' => $department->employees_count,
            ]);
    }

    /**
     * Employment status mix for the donut. Capped at four slices because the
     * chart palette is only validated for four.
     *
     * @return array<int, array{label: string, count: int}>
     */
    private function statusMix(): array
    {
        $counts = Employee::selectRaw('employment_status, count(*) as total')
            ->groupBy('employment_status')
            ->pluck('total', 'employment_status');

        $regular = (int) ($counts['regular'] ?? 0);
        $probationary = (int) ($counts['probationary'] ?? 0);
        $contractual = (int) ($counts['contractual'] ?? 0) + (int) ($counts['project-based'] ?? 0);
        $separated = (int) ($counts['resigned'] ?? 0) + (int) ($counts['terminated'] ?? 0);

        return [
            ['label' => 'Regular', 'count' => $regular],
            ['label' => 'Probationary', 'count' => $probationary],
            ['label' => 'Contractual', 'count' => $contractual],
            ['label' => 'Separated', 'count' => $separated],
        ];
    }

    /** @return array<string, int> */
    private function attendanceToday(Carbon $today): array
    {
        $logs = AttendanceLog::whereDate('log_date', $today)
            ->selectRaw("sum(case when status in ('present','late','undertime') then 1 else 0 end) as present")
            ->selectRaw('sum(case when late_minutes > 0 then 1 else 0 end) as late')
            ->selectRaw("sum(case when status = 'absent' then 1 else 0 end) as absent")
            ->first();

        $present = (int) $logs->present;
        $absent = (int) $logs->absent;

        // "Expected" is whoever has a record for today; without one there is
        // nothing to measure a rate against.
        $expected = $present + $absent;

        return [
            'present' => $present,
            'late' => (int) $logs->late,
            'absent' => $absent,
            'expected' => $expected,
            'rate' => $expected > 0 ? (int) round($present / $expected * 100) : 0,
        ];
    }

    /** @return array<string, mixed> */
    private function leaveToday(Carbon $today): array
    {
        $away = LeaveRequest::with('leaveType:id,code')
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->overlapping($today->toDateString(), $today->toDateString())
            ->get();

        $byType = $away->groupBy(fn (LeaveRequest $request) => $request->leaveType?->code ?? '—')
            ->map->count()
            ->map(fn ($count, $code) => "{$count} {$code}")
            ->values()
            ->implode(' · ');

        return ['count' => $away->count(), 'summary' => $byType];
    }

    /** What the signed-in user still has to act on. @return array<string, int> */
    private function approvals(Request $request): array
    {
        return [
            'leave' => $this->leave->pendingApprovalsFor($request->user()),
            'overtime' => $request->user()->isHrAdmin()
                ? OvertimeRequest::where('status', OvertimeRequest::STATUS_PENDING)->count()
                : 0,
            'reviews' => PerformanceReview::where('reviewer_id', $request->user()->id)
                ->where('status', PerformanceReview::STATUS_DRAFT)
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function latestPayroll(): array
    {
        $run = PayrollRun::with('period')->latest('id')->first();

        return [
            'total_net' => $run ? (float) $run->total_net : 0,
            'period' => $run?->period?->name,
            'status' => $run?->status,
        ];
    }
}
