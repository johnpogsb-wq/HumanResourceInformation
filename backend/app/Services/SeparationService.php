<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Payslip;
use App\Models\Separation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Module 4 — the exit half of the employee lifecycle.
 *
 * Gathers what the employee is owed from the modules that already know it —
 * payroll for what was paid, leave for what was not taken, compensation for
 * what is still borrowed — hands it to FinalPayCalculator, and stores the
 * result as a snapshot.
 *
 * Snapshotted, not recomputed on read, for the same reason payslips are: a
 * later change to salary, leave credits, or a loan balance must not silently
 * rewrite a settlement already handed over.
 */
class SeparationService
{
    public function __construct(
        private readonly FinalPayCalculator $calculator,
        private readonly SalaryAdjustmentService $salaries,
        private readonly LeaveService $leave,
        private readonly WorkCalendar $calendar,
        private readonly LeaveAccrualCalculator $accrual,
    ) {}

    /** Opens a separation and computes the settlement from live figures. */
    public function open(Employee $employee, array $data, User $processor): Separation
    {
        return DB::transaction(function () use ($employee, $data, $processor) {
            $lastDay = Carbon::parse($data['last_day']);
            $computed = $this->calculator->compute($this->gatherInputs($employee, $lastDay, $data));

            return Separation::create([
                'employee_id' => $employee->id,
                'last_day' => $lastDay->toDateString(),
                'reason' => $data['reason'],
                'status' => Separation::STATUS_DRAFT,
                'unpaid_salary' => $computed['unpaid_salary'],
                'thirteenth_month' => $computed['thirteenth_month'],
                'leave_conversion' => $computed['leave_conversion'],
                'loan_deduction' => $computed['loan_deduction'],
                'other_deductions' => $computed['other_deductions'],
                'net_final_pay' => $computed['net_final_pay'],
                'breakdown' => $computed['lines'],
                'clearance' => $this->blankChecklist(),
                'remarks' => $data['remarks'] ?? null,
                'processed_by' => $processor->id,
            ]);
        });
    }

    /** Recomputes a draft against current figures. */
    public function recompute(Separation $separation, array $data = []): Separation
    {
        $employee = $separation->employee;
        $computed = $this->calculator->compute(
            $this->gatherInputs($employee, $separation->last_day, $data),
        );

        $separation->update([
            'unpaid_salary' => $computed['unpaid_salary'],
            'thirteenth_month' => $computed['thirteenth_month'],
            'leave_conversion' => $computed['leave_conversion'],
            'loan_deduction' => $computed['loan_deduction'],
            'other_deductions' => $computed['other_deductions'],
            'net_final_pay' => $computed['net_final_pay'],
            'breakdown' => $computed['lines'],
        ]);

        return $separation->refresh();
    }

    /** Marks one clearance item settled, or un-settles it. */
    public function toggleClearance(Separation $separation, string $key, bool $cleared): Separation
    {
        $checklist = collect($separation->clearance ?? [])
            ->map(function (array $item) use ($key, $cleared) {
                if ($item['key'] === $key) {
                    $item['cleared_at'] = $cleared ? now()->toIso8601String() : null;
                }

                return $item;
            })
            ->all();

        $separation->update([
            'clearance' => $checklist,
            // Status follows the checklist rather than being set by hand, so
            // the two cannot disagree.
            'status' => $separation->fresh()->status === Separation::STATUS_RELEASED
                ? Separation::STATUS_RELEASED
                : (collect($checklist)->where('blocking', true)
                    ->every(fn (array $item) => filled($item['cleared_at']))
                        ? Separation::STATUS_CLEARED
                        : Separation::STATUS_DRAFT),
        ]);

        return $separation->refresh();
    }

    /**
     * Releases the settlement. This is the point of no return: the employee
     * is marked separated, their loans are closed against the deduction, and
     * the figures are frozen.
     */
    public function release(Separation $separation): Separation
    {
        return DB::transaction(function () use ($separation) {
            $separation->update([
                'status' => Separation::STATUS_RELEASED,
                'released_at' => now(),
            ]);

            $this->settleLoans($separation);

            $separation->employee->update([
                'employment_status' => $separation->reason === Separation::REASON_TERMINATED
                    ? 'terminated'
                    : 'resigned',
                'status' => 'inactive',
            ]);

            return $separation->refresh();
        });
    }

    /**
     * What the other modules know about this employee.
     *
     * @return array<string, float|string>
     */
    public function gatherInputs(Employee $employee, Carbon $lastDay, array $data = []): array
    {
        return [
            // The rate they were on at separation, which is not necessarily
            // the rate on file today — a scheduled raise they never worked to
            // see must not inflate the settlement.
            'monthly_salary' => $this->salaries->rateAsOf($employee, $lastDay),
            ...$this->unpaidDays($employee, $lastDay, $data['days_unpaid'] ?? null),
            'basic_earned_this_year' => $this->basicEarnedThisYear($employee, $lastDay),
            'convertible_leave_days' => $this->convertibleLeaveDays($employee, $lastDay),
            'loan_balance' => $this->loanBalance($employee),
            'other_deductions' => (float) ($data['other_deductions'] ?? 0),
        ];
    }

    /**
     * Days owed since the last payroll that paid this employee — read off the
     * Daily Time Records unless HR types a figure.
     *
     * It was always typed by hand, because Module 2 did not exist when this
     * was written; a hand count of "days since the last cutoff" is exactly the
     * number somebody gets wrong on the way out of the door. What counts is
     * what a monthly-paid employee's salary covers: days worked, approved
     * paid leave, and regular holidays on a working day. Absences and special
     * non-working days are not paid — no work, no pay.
     *
     * A typed figure still wins, because the DTR can be incomplete for the
     * last week of somebody who has already stopped coming in, and the
     * breakdown says which of the two the number came from.
     *
     * @return array{days_unpaid: float, days_unpaid_note: string}
     */
    public function unpaidDays(Employee $employee, Carbon $lastDay, mixed $typed = null): array
    {
        if ($typed !== null && $typed !== '') {
            return ['days_unpaid' => (float) $typed, 'days_unpaid_note' => 'entered by HR'];
        }

        $lastPaid = Payslip::where('employee_id', $employee->id)
            ->whereHas('run', fn ($query) => $query->reportable())
            ->with('run.period:id,end_date')
            ->get()
            ->map(fn (Payslip $slip) => $slip->run?->period?->end_date)
            ->filter()
            ->max();

        // Never paid here: count from the first day this system holds a time
        // record for them, not from the hire date. Somebody hired in 2023 and
        // brought onto this system in August has three years this system knows
        // nothing about — reaching back over them paid every regular holiday
        // since January on the first real database this was run against.
        $firstRecord = $lastPaid ? null : AttendanceLog::where('employee_id', $employee->id)->min('work_date');

        if (! $lastPaid && $firstRecord === null) {
            return [
                'days_unpaid' => 0.0,
                'days_unpaid_note' => 'no payroll or time record to count from — enter the days by hand',
            ];
        }

        $from = $lastPaid
            ? $lastPaid->copy()->addDay()->startOfDay()
            : Carbon::parse($firstRecord)->startOfDay()->max($employee->date_hired?->copy()->startOfDay() ?? Carbon::parse($firstRecord));
        $to = $lastDay->copy()->startOfDay();

        if ($from->gt($to)) {
            return [
                'days_unpaid' => 0.0,
                'days_unpaid_note' => 'every day up to the last day was already paid in a payroll run',
            ];
        }

        $worked = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->between($from->toDateString(), $to->toDateString())
            ->whereIn('status', AttendanceLog::WORKED_STATUSES)
            ->get()
            ->mapWithKeys(fn (AttendanceLog $log) => [$log->work_date->toDateString() => true]);

        // Paid leave on a working day the employee did not also work. The
        // expansion covers every calendar day, so rest days are filtered here.
        $leave = $this->leave->approvedLeaveDates([$employee->id], $from, $to)
            ->filter(fn (array $day, string $key) => $day['is_paid']
                && ! $worked->has(substr($key, -10))
                && $this->calendar->isWorkingDay($employee, Carbon::parse(substr($key, -10))))
            ->sum(fn (array $day) => $day['is_half_day'] ? 0.5 : 1.0);

        $holidays = Holiday::query()
            ->where('type', Holiday::TYPE_REGULAR)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get()
            ->unique(fn (Holiday $holiday) => $holiday->date->toDateString())
            ->filter(fn (Holiday $holiday) => ! $worked->has($holiday->date->toDateString())
                && ! $this->calendar->isRestDay($employee, $holiday->date))
            ->count();

        $parts = array_filter([
            $worked->count().' day(s) worked',
            $leave > 0 ? "{$leave} paid leave" : null,
            $holidays > 0 ? "{$holidays} regular holiday(s)" : null,
        ]);

        return [
            'days_unpaid' => (float) ($worked->count() + $leave + $holidays),
            'days_unpaid_note' => implode(', ', $parts).', '.$from->format($from->year === $to->year ? 'M j' : 'M j, Y').' – '.$to->format('M j, Y').', from the Daily Time Records',
        ];
    }

    /**
     * Basic salary actually earned this year — the same definition the
     * 13th-month screen uses, so the two figures agree.
     *
     * `reportable()` is what makes that true: without it a settlement would
     * pay 13th month on payslips from a draft run that is still being
     * corrected, and quote a figure the 13th-month screen does not show.
     */
    private function basicEarnedThisYear(Employee $employee, Carbon $lastDay): float
    {
        $earned = Payslip::where('employee_id', $employee->id)
            ->whereHas('run', fn ($query) => $query
                ->reportable()
                ->whereHas('period', fn ($inner) => $inner->whereYear('end_date', $lastDay->year)),
            )
            ->get()
            ->sum(fn (Payslip $slip) => (float) $slip->basic_pay
                - (float) $slip->late_deduction
                - (float) $slip->undertime_deduction
                - (float) $slip->absence_deduction
                - (float) $slip->unpaid_leave_deduction,
            );

        return round(max((float) $earned, 0), 2);
    }

    /**
     * Unused credits on leave types the company converts to cash, **earned up
     * to the last day** — not read off the balance row.
     *
     * The row is a cache that `LeaveAccrualService::accrue()` recomputes, and
     * it can be stale in the expensive direction: a row created with the full
     * year's entitlement and never re-accrued said 15 days of VL in September
     * for somebody who had earned 10 by then, and the back pay would have paid
     * all 15. So the accrual rule is applied here as of the last day, with the
     * same floor `accrue()` holds — never below what was already used.
     */
    private function convertibleLeaveDays(Employee $employee, Carbon $lastDay): float
    {
        $balances = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', $lastDay->year)
            ->get()
            ->keyBy('leave_type_id');

        $days = LeaveType::where('is_convertible_to_cash', true)
            ->where('is_active', true)
            ->get()
            ->sum(function (LeaveType $type) use ($employee, $lastDay, $balances) {
                $balance = $balances->get($type->id);
                $used = (float) ($balance?->credits_used ?? 0);
                $earned = max(
                    $this->accrual->earned((float) $type->default_credits, $employee->date_hired, $lastDay->year, $lastDay),
                    $used,
                );

                return $earned + (float) ($balance?->credits_carried_over ?? 0) - $used;
            });

        return round(max((float) $days, 0), 2);
    }

    private function loanBalance(Employee $employee): float
    {
        return round(
            (float) EmployeeLoan::where('employee_id', $employee->id)->active()->sum('outstanding_balance'),
            2,
        );
    }

    /** Applies the settlement's loan deduction against the balances. */
    private function settleLoans(Separation $separation): void
    {
        $remaining = (float) $separation->loan_deduction;

        if ($remaining <= 0) {
            return;
        }

        $loans = EmployeeLoan::where('employee_id', $separation->employee_id)
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

    /** @return array<int, array<string, mixed>> */
    private function blankChecklist(): array
    {
        return collect(config('separation.checklist', []))
            ->map(fn (array $item, string $key) => [
                'key' => $key,
                'label' => $item['label'],
                'blocking' => (bool) $item['blocking'],
                'cleared_at' => null,
            ])
            ->values()
            ->all();
    }
}
