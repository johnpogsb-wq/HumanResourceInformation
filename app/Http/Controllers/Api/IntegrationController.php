<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Services\DeploymentReadinessChecker;
use App\Services\EmployeeService;
use App\Services\LeaveService;
use App\Services\TimekeepingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * What Core 2 publishes to the rest of ISMERS.
 *
 * This system is the record of **people, time, leave, and pay**. Every other
 * core needs some of that and none of it should be copied: a headcount kept in
 * two places disagrees within a month, and the disagreement surfaces on a
 * remittance or a dispatch sheet rather than on a screen somebody is watching.
 *
 * So the shape of every endpoint here is the same: an answer this system is
 * uniquely able to give, computed by the service that already gives it to our
 * own screens. Nothing is re-derived for the API — `DeploymentReadinessChecker`,
 * `LicenseVerifier`, and `PayrollRun::scopeReportable()` are the same objects
 * behind `/hr/deployment`, the employee screen, and Compliance. If a consumer
 * and one of our screens ever disagreed about the same driver, one of them
 * would be running its own copy of the rules, and that is the thing this
 * controller exists to prevent.
 *
 * **Everything here is read-only.** The one inbound door is
 * `Api\EndorsementController` (Core 1 proposes a hire) and
 * `Api\LoanController` (Core 3 posts a loan for payroll to deduct). Nothing
 * else may write into this system over the wire.
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly DeploymentReadinessChecker $readiness,
        private readonly TimekeepingService $timekeeping,
        private readonly LeaveService $leave,
    ) {}

    /**
     * GET /api/v1/drivers — for **Fleet & Transportation Management**.
     *
     * Who may lawfully be put behind the wheel, and of what. A dispatcher
     * assigning a run needs the DL codes (the legal ceiling on vehicle class),
     * the conditions (4 is daylight only — that driver cannot take a night
     * run), and whether the licence has lapsed at all.
     *
     * None of that is derivable from an employee record: it is the LTO card,
     * read through `LicenseVerifier`, which is the same object the employee
     * screen and Record Checks use. Filter by `client_id` so a site
     * dispatcher sees only their own, and by `available=1` for the ones who
     * may drive today.
     */
    public function drivers(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Employee::class);

        $drivers = $this->employees->scopedQuery($request->user())
            ->with(['client:id,name', 'position:id,title'])
            ->whereNotNull('drivers_license_number')
            ->when(
                $request->query('client_id'),
                fn ($query, $value) => $query->where('client_id', $value),
            )
            ->when(
                filter_var($request->query('available'), FILTER_VALIDATE_BOOLEAN),
                fn ($query) => $query
                    ->where('status', 'active')
                    ->where(fn ($inner) => $inner
                        ->whereNull('license_expiry')
                        ->orWhereDate('license_expiry', '>=', now()->toDateString()),
                    ),
            )
            ->orderBy('last_name')
            ->get();

        return DriverResource::collection($drivers);
    }

    /**
     * GET /api/v1/deployment-readiness — for **Core 1** and **Fleet**.
     *
     * Can this person be sent to a client tomorrow? No single module answers
     * it: it needs credentials, 201-file completeness, and employment standing
     * at once, which is exactly what `DeploymentReadinessChecker` composes for
     * `/hr/deployment`.
     *
     * `blocked` is not a louder warning. A driver whose licence has lapsed may
     * not lawfully drive, so it is the one status here that means "this would
     * be wrong" rather than "somebody should look".
     */
    public function deploymentReadiness(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $rows = $this->readiness->scan($this->employees->scopedQuery($request->user()))
            ->when(
                $request->query('status'),
                fn ($items, $value) => $items->where('status', $value),
            )
            ->when(
                $request->query('client_id'),
                fn ($items, $value) => $items->where('client_id', (int) $value),
            )
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $rows->count(),
                'ready' => $rows->where('status', DeploymentReadinessChecker::STATUS_READY)->count(),
                'warning' => $rows->where('status', DeploymentReadinessChecker::STATUS_WARNING)->count(),
                'blocked' => $rows->where('status', DeploymentReadinessChecker::STATUS_BLOCKED)->count(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/v1/payroll/runs — for **Financial Management (Transaction Core)**.
     *
     * The disbursement register: what is owed, to whom, and how it breaks
     * down. Only **approved and paid** runs are listed, read from
     * `PayrollRun::scopeReportable()` rather than from a condition written
     * again here — a draft is still being corrected, and Finance disbursing
     * against one would be paying a figure this system has not agreed to yet.
     *
     * That is the same rule 13th-month pay, Compliance, and final pay all
     * read. There is exactly one definition of "already earned" in this
     * system, and it is on the model.
     */
    public function payrollRuns(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PayrollRun::class);

        $runs = PayrollRun::reportable()
            ->with('period:id,name,start_date,end_date,pay_date')
            ->when(
                $request->query('from'),
                fn ($query, $value) => $query->whereHas(
                    'period',
                    fn ($inner) => $inner->whereDate('end_date', '>=', $value),
                ),
            )
            ->when(
                $request->query('to'),
                fn ($query, $value) => $query->whereHas(
                    'period',
                    fn ($inner) => $inner->whereDate('start_date', '<=', $value),
                ),
            )
            ->latest('id')
            ->get()
            ->map(fn (PayrollRun $run) => [
                'id' => $run->id,
                'run_number' => $run->run_number,
                'status' => $run->status,
                'period' => [
                    'name' => $run->period?->name,
                    'start_date' => $run->period?->start_date?->toDateString(),
                    'end_date' => $run->period?->end_date?->toDateString(),
                    'pay_date' => $run->period?->pay_date?->toDateString(),
                ],
                'employee_count' => $run->employee_count,
                'total_gross' => (float) $run->total_gross,
                'total_deductions' => (float) $run->total_deductions,
                'total_net' => (float) $run->total_net,
                'approved_at' => $run->approved_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $runs]);
    }

    /**
     * GET /api/v1/payroll/runs/{run}/register — for **Financial Management**.
     *
     * The per-employee lines behind one run's totals, which is what a
     * disbursement file is built from. Bank details ride only for a caller
     * whose token may see them — the same `viewSensitive` line the employee
     * screen draws, applied to a machine rather than a person.
     */
    public function payrollRegister(Request $request, PayrollRun $run): JsonResponse
    {
        Gate::authorize('view', $run);

        abort_unless(
            in_array($run->status, PayrollRun::REPORTABLE, true),
            409,
            'This run is still a draft. Only approved or paid runs are disbursable.',
        );

        $lines = $run->payslips()
            ->with('employee:id,employee_number,first_name,middle_name,last_name,suffix,bank_name,bank_account_number')
            ->get()
            ->map(function ($payslip) use ($request) {
                $employee = $payslip->employee;
                $maySeeBank = $employee && Gate::forUser($request->user())->allows('viewSensitive', $employee);

                return array_filter([
                    'payslip_number' => $payslip->payslip_number,
                    'employee_id' => $payslip->employee_id,
                    'employee_number' => $employee?->employee_number,
                    'full_name' => $employee?->full_name,
                    'gross_pay' => (float) $payslip->gross_pay,
                    'deductions_total' => (float) $payslip->deductions_total,
                    'net_pay' => (float) $payslip->net_pay,

                    // Absent, not null, for a caller who may not see them.
                    'bank_name' => $maySeeBank ? $employee?->bank_name : null,
                    'bank_account_number' => $maySeeBank ? $employee?->bank_account_number : null,
                ], fn ($value) => $value !== null);
            });

        return response()->json([
            'data' => $lines,
            'meta' => [
                'run_number' => $run->run_number,
                'status' => $run->status,
                'employee_count' => $run->employee_count,
                'total_net' => (float) $run->total_net,
                // A control total the receiving system can check its own sum
                // against, the same one every Compliance export carries.
                'control_total' => round((float) $lines->sum('net_pay'), 2),
            ],
        ]);
    }

    /**
     * GET /api/v1/payroll/contributions — for **Core 3 (Government Contribution
     * & Compliance)**.
     *
     * What was withheld and what the employer owes, per employee, for a
     * period. Core 3 files the remittances; this system computed them, and
     * these figures are **read back from stored payslips rather than
     * recomputed** — otherwise a new SSS circular in `config/payroll.php`
     * would silently rewrite what was already remitted.
     *
     * An employee missing the relevant government number is included with a
     * null, deliberately: the filing cannot cover them until it is on their
     * 201 file, and dropping them would hide that from the system whose job
     * it is to notice.
     */
    public function contributions(Request $request, PayrollRun $run): JsonResponse
    {
        Gate::authorize('view', $run);

        abort_unless(
            in_array($run->status, PayrollRun::REPORTABLE, true),
            409,
            'This run is still a draft. Only approved or paid runs are reportable.',
        );

        $lines = $run->payslips()
            ->with('employee:id,employee_number,first_name,middle_name,last_name,suffix,sss_number,philhealth_number,pagibig_number,tin')
            ->get()
            ->map(fn ($payslip) => [
                'employee_id' => $payslip->employee_id,
                'employee_number' => $payslip->employee?->employee_number,
                'full_name' => $payslip->employee?->full_name,
                'numbers' => [
                    'sss' => $payslip->employee?->sss_number,
                    'philhealth' => $payslip->employee?->philhealth_number,
                    'pagibig' => $payslip->employee?->pagibig_number,
                    'tin' => $payslip->employee?->tin,
                ],
                'employee_share' => [
                    'sss' => (float) $payslip->sss_employee,
                    'philhealth' => (float) $payslip->philhealth_employee,
                    'pagibig' => (float) $payslip->pagibig_employee,
                    'withholding_tax' => (float) $payslip->withholding_tax,
                ],
                'employer_share' => [
                    'sss' => (float) $payslip->sss_employer,
                    'philhealth' => (float) $payslip->philhealth_employer,
                    'pagibig' => (float) $payslip->pagibig_employer,
                ],
            ]);

        return response()->json([
            'data' => $lines,
            'meta' => [
                'run_number' => $run->run_number,
                'period' => $run->period?->name,
                'employee_count' => $lines->count(),
                // Flagged rather than filtered out: the filing cannot include
                // them until the number is on the 201 file.
                'missing_numbers' => $lines
                    ->filter(fn (array $line) => collect($line['numbers'])->contains(null))
                    ->pluck('employee_number')
                    ->values(),
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/workforce — for **Core 4 (Reports & Dashboards)**
     * and **Business Intelligence**.
     *
     * Aggregates only: no names, no salaries, no government numbers. A
     * dashboard needs shapes, not people, and an endpoint that hands over the
     * directory to draw a bar chart is an endpoint that will one day be the
     * way the directory left.
     */
    public function workforce(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $scoped = $this->employees->scopedQuery($request->user());

        $from = Carbon::parse($request->query('from', now()->startOfMonth()->toDateString()));
        $to = Carbon::parse($request->query('to', now()->endOfMonth()->toDateString()));

        $attendance = $this->timekeeping->summary(
            AttendanceLog::whereIn('employee_id', (clone $scoped)->select('employees.id'))
                ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()]),
        );

        return response()->json([
            'data' => [
                'headcount' => $this->employees->statistics(clone $scoped),

                'by_category' => (clone $scoped)
                    ->selectRaw('employment_category, count(*) as total')
                    ->groupBy('employment_category')
                    ->pluck('total', 'employment_category'),

                'by_client' => (clone $scoped)
                    ->join('clients', 'clients.id', '=', 'employees.client_id')
                    ->selectRaw('clients.name, count(*) as total')
                    ->groupBy('clients.name')
                    ->pluck('total', 'name'),

                'attendance' => $attendance,

                'leave' => $this->leave->summary(
                    LeaveRequest::whereIn(
                        'employee_id',
                        (clone $scoped)->select('employees.id'),
                    )->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from),
                ),
            ],
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'generated_at' => now()->toIso8601String(),
                // What this figure is scoped to, so a consumer knows whether
                // it is looking at the whole workforce or one supervisor's.
                'scope' => $request->user()->isHrAdmin() ? 'organisation' : 'scoped_to_caller',
            ],
        ]);
    }
}
