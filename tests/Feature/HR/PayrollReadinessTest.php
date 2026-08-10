<?php

namespace Tests\Feature\HR;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\PayrollReadinessChecker;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Module 2 → Module 4: does payroll notice when the DTR it is paying from is
 * incomplete?
 */
class PayrollReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const SALARY = 26100;

    public function test_a_complete_period_reports_ready(): void
    {
        $period = $this->period();
        $employee = $this->employee();
        $this->workedFullDay($employee, '2026-08-03');

        $readiness = $this->check($period);

        $this->assertTrue($readiness['ready']);
        $this->assertSame([], $readiness['checks']);
    }

    public function test_a_missing_time_out_blocks_the_period(): void
    {
        $period = $this->period();
        $employee = $this->employee();

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-08-03',
            'time_in' => '2026-08-03 08:00:00',
            'time_out' => null,
        ]);

        $readiness = $this->check($period);

        $this->assertFalse($readiness['ready']);
        $this->assertSame(1, $readiness['blockers']);
        $this->assertSame('missing_time_outs', $readiness['checks'][0]['key']);
        $this->assertSame(
            PayrollReadinessChecker::SEVERITY_BLOCKER,
            $readiness['checks'][0]['severity'],
        );
    }

    public function test_pending_overtime_warns_because_it_will_pay_nothing(): void
    {
        $period = $this->period();
        $employee = $this->employee();
        $this->workedFullDay($employee, '2026-08-03');

        $this->overtime($employee, OvertimeRequest::STATUS_PENDING);

        $readiness = $this->check($period);

        $this->assertFalse($readiness['ready']);
        $this->assertSame(1, $readiness['warnings']);
        $this->assertSame('pending_overtime', $readiness['checks'][0]['key']);
        $this->assertStringContainsString('3', $readiness['checks'][0]['detail']);
    }

    public function test_approved_overtime_does_not_warn(): void
    {
        $period = $this->period();
        $employee = $this->employee();
        $this->workedFullDay($employee, '2026-08-03');

        $this->overtime($employee, OvertimeRequest::STATUS_APPROVED);

        $this->assertTrue($this->check($period)['ready']);
    }

    public function test_an_employee_with_no_dtr_is_reported(): void
    {
        $period = $this->period();
        $this->employee(); // salaried, but never clocked in

        $readiness = $this->check($period);

        $this->assertFalse($readiness['ready']);
        $this->assertSame('no_attendance', $readiness['checks'][0]['key']);
        $this->assertSame(
            PayrollReadinessChecker::SEVERITY_WARNING,
            $readiness['checks'][0]['severity'],
        );
    }

    public function test_attendance_outside_the_period_does_not_count_as_present(): void
    {
        $period = $this->period();
        $employee = $this->employee();

        // A day before the cut-off opens.
        $this->workedFullDay($employee, '2026-07-20');

        $readiness = $this->check($period);

        $this->assertSame('no_attendance', $readiness['checks'][0]['key']);
    }

    public function test_a_draft_run_shows_the_readiness_panel(): void
    {
        $period = $this->period();
        $employee = $this->employee();

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-08-03',
            'time_in' => '2026-08-03 08:00:00',
            'time_out' => null,
        ]);

        $run = app(PayrollService::class)->generate($period, $this->hr());

        $this->actingAs($this->hr())
            ->get("/hr/payroll/runs/{$run->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('readiness.ready', false)
                ->where('readiness.blockers', 1)
                ->has('readiness.checks', 1),
            );
    }

    public function test_an_approved_run_hides_the_panel_because_the_figures_are_history(): void
    {
        $period = $this->period();
        $employee = $this->employee();

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-08-03',
            'time_in' => '2026-08-03 08:00:00',
            'time_out' => null,
        ]);

        $service = app(PayrollService::class);
        $run = $service->generate($period, $this->hr());
        $service->submitForApproval($run);
        $service->approve($run, User::factory()->admin()->create());

        $this->actingAs($this->hr())
            ->get("/hr/payroll/runs/{$run->id}")
            ->assertInertia(fn (Assert $page) => $page->where('readiness', null));
    }

    public function test_readiness_never_blocks_the_run_from_computing(): void
    {
        $period = $this->period();
        $employee = $this->employee();

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-08-03',
            'time_in' => '2026-08-03 08:00:00',
            'time_out' => null,
        ]);

        $this->actingAs($this->hr())
            ->post("/hr/payroll/periods/{$period->id}/generate")
            ->assertRedirect();

        $this->assertDatabaseCount('payroll_runs', 1);
        $this->assertDatabaseHas('payslips', ['employee_id' => $employee->id]);
    }

    /** @return array<string, mixed> */
    private function check(PayrollPeriod $period): array
    {
        return app(PayrollReadinessChecker::class)->check($period);
    }

    private function overtime(Employee $employee, string $status): OvertimeRequest
    {
        return OvertimeRequest::create([
            'employee_id' => $employee->id,
            'date' => '2026-08-03',
            'start_time' => '2026-08-03 17:00:00',
            'end_time' => '2026-08-03 20:00:00',
            'hours' => 3,
            'reason' => 'Fleet dispatch backlog',
            'status' => $status,
        ]);
    }

    private function workedFullDay(Employee $employee, string $date): void
    {
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => $date,
            'time_in' => "{$date} 08:00:00",
            'time_out' => "{$date} 17:00:00",
        ]);
    }

    private function employee(): Employee
    {
        return Employee::factory()->create(['basic_salary' => self::SALARY]);
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }

    private function period(): PayrollPeriod
    {
        return PayrollPeriod::create([
            'name' => 'Aug 1 – 15, 2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-15',
            'pay_date' => '2026-08-20',
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);
    }
}
