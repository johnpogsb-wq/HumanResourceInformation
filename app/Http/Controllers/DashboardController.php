<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
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
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly LeaveService $leave,
        private readonly PerformanceScorer $scorer,
    ) {}

    public function __invoke(Request $request): Response
    {
        $scoped = $this->employees->scopedQuery($request->user());
        $today = Carbon::today();

        return Inertia::render('Dashboard', [
            'statistics' => $this->statistics($scoped),
            'headcountByDepartment' => $this->headcountByDepartment(),
            'statusMix' => $this->statusMix(),
            'attendanceToday' => $this->attendanceToday($today),
            'leaveToday' => $this->leaveToday($today),
            'approvals' => $this->approvals($request),
            'payroll' => $this->latestPayroll(),
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

        return [
            ...$base,
            'average_rating' => $average,
            'performance_band' => $this->scorer->band($average)['label'] ?? null,
        ];
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
