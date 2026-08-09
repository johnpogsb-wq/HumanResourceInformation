<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\EmployeeLoan;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Module 4 — payroll run orchestration.
 *
 * Gathers each employee's period figures from Timekeeping and Leave, hands them
 * to PayrollCalculator, and stores the resulting payslips. Loans are only
 * amortised when a run is approved, so a draft can be recomputed freely.
 */
class PayrollService
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly EmployeeService $employees,
    ) {}

    /** Payslips the viewer may see — employees see only their own. */
    public function scopedPayslipQuery(User $user): Builder
    {
        return Payslip::query()
            ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix', 'run.period'])
            ->whereIn('employee_id', $this->employees->scopedQuery($user)->select('employees.id'));
    }

    /**
     * Computes a draft run for a period, replacing any existing draft.
     * Recomputing is safe and expected — figures change as HR corrects DTRs.
     */
    public function generate(PayrollPeriod $period, User $processor): PayrollRun
    {
        return DB::transaction(function () use ($period, $processor) {
            // Only ever one draft per period; a fresh compute supersedes it.
            $period->runs()->where('status', PayrollRun::STATUS_DRAFT)->each(
                fn (PayrollRun $existing) => $existing->delete(),
            );

            $run = $period->runs()->create([
                'run_number' => $this->nextRunNumber(),
                'status' => PayrollRun::STATUS_DRAFT,
                'processed_by' => $processor->id,
                'processed_at' => now(),
            ]);

            $employees = Employee::query()
                ->where('status', '!=', 'inactive')
                ->where('basic_salary', '>', 0)
                ->get();

            foreach ($employees as $employee) {
                $this->createPayslip($run, $period, $employee);
            }

            return $this->refreshTotals($run);
        });
    }

    /** Draft → for approval. */
    public function submitForApproval(PayrollRun $run): PayrollRun
    {
        $run->update(['status' => PayrollRun::STATUS_FOR_APPROVAL]);

        return $run->refresh();
    }

    /**
     * Approves the run and applies loan amortisations. This is the point of no
     * return: balances move, so a run can no longer be recomputed.
     */
    public function approve(PayrollRun $run, User $approver, ?string $remarks = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $approver, $remarks) {
            $run->update([
                'status' => PayrollRun::STATUS_APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'remarks' => $remarks,
            ]);

            $this->amortiseLoans($run);

            $run->period->update(['status' => PayrollPeriod::STATUS_APPROVED]);

            return $run->refresh();
        });
    }

    public function markPaid(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run->update(['status' => PayrollRun::STATUS_PAID]);
            $run->period->update(['status' => PayrollPeriod::STATUS_PAID]);

            return $run->refresh();
        });
    }

    public function cancel(PayrollRun $run, ?string $remarks = null): PayrollRun
    {
        $run->update([
            'status' => PayrollRun::STATUS_CANCELLED,
            'remarks' => $remarks,
        ]);

        return $run->refresh();
    }

    /** Company-wide totals for the run header and the runs list. */
    public function refreshTotals(PayrollRun $run): PayrollRun
    {
        $totals = $run->payslips()
            ->selectRaw('count(*) as employee_count')
            ->selectRaw('coalesce(sum(gross_pay), 0) as gross')
            ->selectRaw('coalesce(sum(deductions_total), 0) as deductions')
            ->selectRaw('coalesce(sum(net_pay), 0) as net')
            ->first();

        $run->update([
            'employee_count' => (int) $totals->employee_count,
            'total_gross' => round((float) $totals->gross, 2),
            'total_deductions' => round((float) $totals->deductions, 2),
            'total_net' => round((float) $totals->net, 2),
        ]);

        return $run->refresh();
    }

    /**
     * The inputs for one employee's payslip, drawn from Modules 2 and 3.
     *
     * @return array<string, mixed>
     */
    public function gatherInputs(PayrollPeriod $period, Employee $employee): array
    {
        $from = $period->start_date;
        $to = $period->end_date;

        $attendance = AttendanceLog::where('employee_id', $employee->id)
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('coalesce(sum(hours_worked), 0) as hours')
            ->selectRaw('coalesce(sum(late_minutes), 0) as late')
            ->selectRaw('coalesce(sum(undertime_minutes), 0) as undertime')
            ->selectRaw('coalesce(sum(night_diff_minutes), 0) as night_diff')
            ->selectRaw("coalesce(sum(case when status = 'absent' then 1 else 0 end), 0) as absences")
            ->selectRaw("coalesce(sum(case when status in ('present','late','undertime') then 1 else 0 end), 0) as days_worked")
            ->first();

        return [
            'monthly_salary' => (float) $employee->basic_salary,
            'pay_frequency' => $period->frequency,

            'days_worked' => (float) $attendance->days_worked,
            'hours_worked' => round((float) $attendance->hours, 2),
            // Raw time past the shift is recorded by attendance, but only
            // *approved* overtime is paid.
            'overtime_hours' => $this->approvedOvertimeHours($employee, $from, $to),
            'night_diff_hours' => round(((float) $attendance->night_diff) / 60, 2),
            'late_minutes' => (int) $attendance->late,
            'undertime_minutes' => (int) $attendance->undertime,
            'absent_days' => (float) $attendance->absences,
            'unpaid_leave_days' => $this->unpaidLeaveDays($employee, $from, $to),

            'allowances' => $this->allowances($employee, $period),
            'loans' => $this->loans($employee, $period),
        ];
    }

    /** Approved overtime hours falling inside the period. */
    public function approvedOvertimeHours(Employee $employee, Carbon $from, Carbon $to): float
    {
        return round((float) OvertimeRequest::where('employee_id', $employee->id)
            ->where('status', OvertimeRequest::STATUS_APPROVED)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('hours'), 2);
    }

    /**
     * Days of approved *unpaid* leave inside the period. Paid leave is already
     * covered by the basic salary and must not be deducted.
     */
    public function unpaidLeaveDays(Employee $employee, Carbon $from, Carbon $to): float
    {
        $requests = LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->overlapping($from->toDateString(), $to->toDateString())
            ->get()
            ->filter(fn (LeaveRequest $request) => ! $request->leaveType?->is_paid);

        $days = 0.0;

        foreach ($requests as $request) {
            // A request can straddle the period boundary; count only the part
            // that falls inside it.
            $start = $request->start_date->copy()->max($from);
            $end = $request->end_date->copy()->min($to);

            $spanned = $start->diffInDays($end) + 1;
            $total = $request->start_date->diffInDays($request->end_date) + 1;

            $days += (float) $request->days_requested * ($spanned / max($total, 1));
        }

        return round($days, 2);
    }

    private function createPayslip(PayrollRun $run, PayrollPeriod $period, Employee $employee): void
    {
        $computed = $this->calculator->compute($this->gatherInputs($period, $employee));

        $payslip = $run->payslips()->create([
            ...$computed['payslip'],
            'employee_id' => $employee->id,
            'payslip_number' => sprintf('PS-%s-%04d', $run->run_number, $employee->id),
        ]);

        foreach ($computed['lines'] as $line) {
            $payslip->lines()->create($line);
        }
    }

    /** @return array<int, array{label: string, amount: float, taxable: bool}> */
    private function allowances(Employee $employee, PayrollPeriod $period): array
    {
        return EmployeeAllowance::where('employee_id', $employee->id)
            ->effectiveOn($period->end_date)
            ->get()
            ->map(fn (EmployeeAllowance $allowance) => [
                'label' => $allowance->name,
                'amount' => $allowance->amountForPeriod($period->frequency),
                'taxable' => (bool) $allowance->is_taxable,
            ])
            ->all();
    }

    /** @return array<int, array{label: string, amount: float, loan_id: int}> */
    private function loans(Employee $employee, PayrollPeriod $period): array
    {
        return EmployeeLoan::where('employee_id', $employee->id)
            ->active()
            ->get()
            ->map(fn (EmployeeLoan $loan) => [
                'label' => strtoupper(str_replace('_', ' ', $loan->type)).' Loan',
                'amount' => $loan->amortisationForPeriod($period->frequency),
                'loan_id' => $loan->id,
            ])
            ->filter(fn (array $loan) => $loan['amount'] > 0)
            ->values()
            ->all();
    }

    /**
     * Applies each payslip's loan deduction against the outstanding balance.
     * Runs once, at approval — never while the run is still a draft.
     */
    private function amortiseLoans(PayrollRun $run): void
    {
        $payslips = $run->payslips()->where('loans_deduction', '>', 0)->get();

        foreach ($payslips as $payslip) {
            $remaining = (float) $payslip->loans_deduction;

            $loans = EmployeeLoan::where('employee_id', $payslip->employee_id)
                ->active()
                ->orderBy('start_date')
                ->get();

            foreach ($loans as $loan) {
                if ($remaining <= 0) {
                    break;
                }

                $applied = min($remaining, (float) $loan->outstanding_balance);
                $balance = round((float) $loan->outstanding_balance - $applied, 2);

                $loan->update([
                    'outstanding_balance' => $balance,
                    'status' => $balance <= 0 ? EmployeeLoan::STATUS_PAID : EmployeeLoan::STATUS_ACTIVE,
                ]);

                $remaining = round($remaining - $applied, 2);
            }
        }
    }

    private function nextRunNumber(): string
    {
        $year = now()->year;
        $prefix = "PR-{$year}-";

        $latest = PayrollRun::where('run_number', 'like', $prefix.'%')
            ->orderByDesc('run_number')
            ->value('run_number');

        $sequence = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
