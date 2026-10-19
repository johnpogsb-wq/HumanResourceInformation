<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Separation;
use App\Services\SeparationService;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Support\Carbon;
use Tests\Feature\Timekeeping\TimekeepingTestCase;

/**
 * Back pay's unpaid salary, counted off the Daily Time Records rather than
 * typed. "Now" is pinned to 16 September 2026 by the base class.
 *
 * The worker is on 26,100 a month, so a day is 26,100 × 12 ÷ 261 = 1,200.
 */
class BackPayFromDtrTest extends TimekeepingTestCase
{
    public function test_unpaid_days_are_worked_days_paid_leave_and_regular_holidays_since_the_last_payroll(): void
    {
        $employee = $this->worker();
        $this->paidThrough($employee, '2026-08-16', '2026-08-31');

        $this->record($employee, '2026-09-01', '08:00', '17:00');    // Tue — worked
        $this->record($employee, '2026-09-02', '08:10', '17:00');    // Wed — worked (within grace)
        $this->record($employee, '2026-09-03', null, null);          // Thu — absent, not paid
        $this->paidLeave($employee, '2026-09-04');                    // Fri — paid leave
        // Sat 5 / Sun 6 are rest days and pay nothing extra.
        Holiday::create(['date' => '2026-09-07', 'name' => 'Test Regular Holiday', 'type' => Holiday::TYPE_REGULAR]);
        Holiday::create(['date' => '2026-09-09', 'name' => 'Test Special Day', 'type' => Holiday::TYPE_SPECIAL]);
        $this->record($employee, '2026-09-08', '08:00', '17:00');    // Tue — worked

        $separation = $this->open($employee, '2026-09-09');

        // 3 worked + 1 paid leave + 1 regular holiday = 5 days × 1,200.
        // The special day on the 9th is no work, no pay.
        $this->assertSame('6000.00', $separation->unpaid_salary);
        $this->assertStringContainsString('3 day(s) worked, 1 paid leave, 1 regular holiday(s)', $this->note($separation));
        $this->assertStringContainsString('Sep 1 – Sep 9, 2026, from the Daily Time Records', $this->note($separation));
    }

    public function test_a_typed_figure_still_wins_and_says_so(): void
    {
        $employee = $this->worker();
        $this->record($employee, '2026-09-01', '08:00', '17:00');

        $separation = $this->open($employee, '2026-09-09', ['days_unpaid' => 2]);

        $this->assertSame('2400.00', $separation->unpaid_salary);
        $this->assertStringContainsString('entered by HR', $this->note($separation));
    }

    public function test_somebody_never_paid_is_counted_from_their_hire_date(): void
    {
        $employee = $this->worker(['date_hired' => '2026-09-07']);
        $this->record($employee, '2026-09-04', '08:00', '17:00');    // before hire — not counted
        $this->record($employee, '2026-09-07', '08:00', '17:00');
        $this->record($employee, '2026-09-08', '08:00', '17:00');

        $this->assertSame('2400.00', $this->open($employee, '2026-09-08')->unpaid_salary);
    }

    public function test_nothing_is_owed_when_the_last_day_was_already_paid(): void
    {
        $employee = $this->worker();
        $this->paidThrough($employee, '2026-09-01', '2026-09-15');
        $this->record($employee, '2026-09-10', '08:00', '17:00');

        $separation = $this->open($employee, '2026-09-12');

        $this->assertSame('0.00', $separation->unpaid_salary);
        $this->assertStringContainsString('already paid', $this->note($separation));
    }

    public function test_a_draft_payroll_does_not_count_as_paid(): void
    {
        $employee = $this->worker();
        $this->paidThrough($employee, '2026-09-01', '2026-09-15', PayrollRun::STATUS_DRAFT);
        $this->record($employee, '2026-09-10', '08:00', '17:00');

        // The draft is still being corrected, so the 10th is still owed.
        $this->assertSame('1200.00', $this->open($employee, '2026-09-12')->unpaid_salary);
    }

    public function test_the_screen_counts_from_the_dtr_when_the_field_is_left_blank(): void
    {
        $employee = $this->worker();
        $this->record($employee, '2026-09-01', '08:00', '17:00');

        $this->actingAs($this->hr())->post('/hr/payroll/separations', [
            'employee_id' => $employee->id,
            'last_day' => '2026-09-01',
            'reason' => Separation::REASON_END_OF_CONTRACT,
            'days_unpaid' => '',
        ])->assertRedirect();

        $this->assertSame('1200.00', Separation::firstOrFail()->unpaid_salary);
    }

    public function test_vacation_leave_is_seeded_convertible_so_it_reaches_the_back_pay(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        $this->assertTrue((bool) LeaveType::where('code', 'VL')->value('is_convertible_to_cash'));
        $this->assertFalse((bool) LeaveType::where('code', 'SL')->value('is_convertible_to_cash'));
    }

    // --- Helpers -----------------------------------------------------------

    private function open(Employee $employee, string $lastDay, array $data = []): Separation
    {
        return app(SeparationService::class)->open($employee, [
            'last_day' => $lastDay,
            'reason' => Separation::REASON_RESIGNED,
            ...$data,
        ], $this->hr());
    }

    private function note(Separation $separation): string
    {
        return collect($separation->breakdown)->firstWhere('label', 'Unpaid salary')['note'];
    }

    private function paidThrough(Employee $employee, string $start, string $end, string $status = PayrollRun::STATUS_APPROVED): void
    {
        $run = PayrollRun::create([
            'payroll_period_id' => $this->period($start, $end)->id,
            'run_number' => 'PR-2026-'.str_pad((string) (PayrollRun::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => $status,
        ]);

        Payslip::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'payslip_number' => 'PS-'.$run->run_number.'-'.$employee->id,
            'basic_pay' => 13050,
        ]);
    }

    private function paidLeave(Employee $employee, string $date): void
    {
        LeaveRequest::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::factory()->create(['is_paid' => true])->id,
            'start_date' => Carbon::parse($date),
            'end_date' => Carbon::parse($date),
            'days_requested' => 1,
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);
    }
}
