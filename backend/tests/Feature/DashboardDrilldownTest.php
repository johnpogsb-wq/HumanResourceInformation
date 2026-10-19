<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every figure on the dashboard opens the rows it counted.
 *
 * The rule is the system's own — a summary tile that counts something must be
 * able to show it — and it has been got wrong twice before by reading the code
 * instead of clicking the tile: "Days Present" counted three statuses and
 * linked to one, "Awaiting Action" counted two. Both were found by opening the
 * list and counting, which is what this class does instead of a person.
 *
 * So each test asserts the *same number twice*: once as the tile renders it,
 * once as the total of the list its link opens. A test that only asserted the
 * tile would pass while the link was wrong, which is the failure being guarded
 * against.
 */
class DashboardDrilldownTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /*
     * -----------------------------------------------------------------
     * Leave Requests — counted over what was filed this month
     * -----------------------------------------------------------------
     */

    public function test_each_leave_tile_opens_exactly_what_it_counted(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $type = LeaveType::create([
            'code' => 'VL', 'name' => 'Vacation Leave', 'days_per_year' => 15,
            'is_paid' => true, 'is_active' => true,
        ]);

        foreach ([
            LeaveRequest::STATUS_PENDING => 3,
            LeaveRequest::STATUS_APPROVED => 2,
            LeaveRequest::STATUS_REJECTED => 1,
        ] as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->leaveRequest($employee, $type, $status);
            }
        }

        /*
         * Filed before this month, so every tile's link must exclude it. This
         * row is the whole point of `filed_from` riding along in the URL: with
         * the window dropped, "Pending 3" would open a list of 4.
         */
        $old = $this->leaveRequest($employee, $type, LeaveRequest::STATUS_PENDING);
        $old->forceFill(['created_at' => now()->subMonths(2)])->save();

        $summary = $this->dashboard()['leaveSummary'];

        $this->assertSame(3, $summary['pending']);
        $this->assertSame(2, $summary['approved']);
        $this->assertSame(1, $summary['rejected']);

        foreach (['pending' => 3, 'approved' => 2, 'rejected' => 1] as $status => $expected) {
            $this->assertSame(
                $expected,
                $this->total(
                    "/hr/leave?status={$status}&filed_from={$summary['filed_from']}",
                    'requestsTab.requests',
                ),
                "the leave \"{$status}\" tile does not open the rows it counted",
            );
        }
    }

    /*
     * -----------------------------------------------------------------
     * Payroll — counted as periods carrying a run at that stage
     * -----------------------------------------------------------------
     */

    public function test_each_payroll_tile_opens_exactly_what_it_counted(): void
    {
        $this->payrollRun(PayrollRun::STATUS_DRAFT);
        $this->payrollRun(PayrollRun::STATUS_FOR_APPROVAL);
        $this->payrollRun(PayrollRun::STATUS_APPROVED);
        $this->payrollRun(PayrollRun::STATUS_PAID);

        $summary = $this->dashboard()['payrollSummary'];

        // "Released" is approved *or* paid — PayrollRun::REPORTABLE, not a
        // status of its own.
        $this->assertSame(1, $summary['draft']);
        $this->assertSame(1, $summary['for_approval']);
        $this->assertSame(2, $summary['released']);

        foreach (['draft' => 1, 'for_approval' => 1, 'released' => 2] as $stage => $expected) {
            $this->assertSame(
                $expected,
                $this->total("/hr/payroll?run_status={$stage}", 'periods'),
                "the payroll \"{$stage}\" tile does not open the rows it counted",
            );
        }
    }

    /*
     * -----------------------------------------------------------------
     * The charts
     * -----------------------------------------------------------------
     */

    public function test_a_donut_slice_opens_the_records_it_counted(): void
    {
        Employee::factory()->count(2)->create(['employment_status' => 'regular']);
        Employee::factory()->count(3)->create(['employment_status' => 'contractual']);
        Employee::factory()->count(4)->create(['employment_status' => 'project-based']);

        $slices = collect($this->dashboard()['statusMix'])->keyBy('label');

        // Two of the four slices group a pair of statuses, which is the case
        // a single-value filter could not have expressed.
        $this->assertSame(7, $slices['Contractual']['count']);
        $this->assertSame('contractual,project-based', $slices['Contractual']['filter']);

        foreach ($slices as $label => $slice) {
            $this->assertSame(
                $slice['count'],
                $this->total("/hr/employees?employment_status={$slice['filter']}", 'employees'),
                "the \"{$label}\" slice does not open the records it counted",
            );
        }
    }

    public function test_a_department_bar_opens_the_people_it_measured(): void
    {
        $department = Department::create([
            'code' => 'OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        Employee::factory()->count(3)->create([
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        // The bar measures *active* records, so the link says so too.
        Employee::factory()->create([
            'department_id' => $department->id,
            'status' => 'inactive',
        ]);

        $bar = collect($this->dashboard()['headcountByDepartment'])
            ->firstWhere('name', 'Operations');

        $this->assertSame(3, $bar['count']);
        $this->assertSame(
            3,
            $this->total(
                "/hr/employees?department_id={$bar['id']}&status=active",
                'employees',
            ),
        );
    }

    /*
     * -----------------------------------------------------------------
     * The preview rows
     * -----------------------------------------------------------------
     */

    public function test_every_preview_row_names_a_record_that_can_be_opened(): void
    {
        $employee = Employee::factory()->create([
            'status' => 'active',
            'date_hired' => now()->subDays(2),
        ]);
        $type = LeaveType::create([
            'code' => 'SL', 'name' => 'Sick Leave', 'days_per_year' => 15,
            'is_paid' => true, 'is_active' => true,
        ]);
        $this->leaveRequest($employee, $type, LeaveRequest::STATUS_PENDING);
        $run = $this->payrollRun(PayrollRun::STATUS_PAID);

        $props = $this->dashboard();

        // The leave preview opens that person's leave, because there is no
        // screen for a single request.
        $this->assertSame(
            $employee->id,
            $props['leaveSummary']['latest']['employee_id'],
        );
        $this->actingAs($this->admin)
            ->get("/hr/leave?employee_id={$employee->id}")
            ->assertOk();

        $this->actingAs($this->admin)
            ->get("/hr/payroll/runs/{$props['payrollSummary']['latest']['id']}")
            ->assertOk();
        $this->assertSame($run->id, $props['payrollSummary']['latest']['id']);

    }

    /**
     * The peso figure is written as this system writes it everywhere else. It
     * used to read "Net 762,899.62" — a bare number on a screen that also
     * shows headcounts, day counts, and percentages.
     */
    public function test_the_payroll_preview_states_its_unit(): void
    {
        $this->payrollRun(PayrollRun::STATUS_PAID, 762899.62);

        $this->assertSame(
            '₱762,899.62',
            str_replace('Net ', '', $this->dashboard()['payrollSummary']['latest']['subtitle']),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /*
     * -----------------------------------------------------------------
     * Helpers
     * -----------------------------------------------------------------
     */

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        return $this->props('/dashboard');
    }

    /** @return array<string, mixed> */
    private function props(string $uri): array
    {
        $response = $this->actingAs($this->admin)->get($uri);
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    /**
     * The number of rows a list screen reports for a set of filters.
     *
     * `$key` is a dotted path, because a merged module screen nests each tab's
     * props under its own key — the Leave list lives at
     * `requestsTab.requests` rather than at `requests`. A flat key worked
     * while every screen was its own page.
     */
    private function total(string $uri, string $key): int
    {
        return (int) (data_get($this->props($uri), "{$key}.meta.total") ?? -1);
    }

    private function leaveRequest(Employee $employee, LeaveType $type, string $status): LeaveRequest
    {
        return LeaveRequest::create([
            'reference_number' => 'LR-'.str_pad((string) (LeaveRequest::count() + 1), 5, '0', STR_PAD_LEFT),
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(7)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'days_requested' => 1,
            'reason' => 'Test',
            'status' => $status,
        ]);
    }

    private function payrollRun(string $status, float $net = 0): PayrollRun
    {
        // One period per run: the pair is unique, and the dashboard counts
        // periods rather than runs.
        $offset = PayrollPeriod::count();

        $period = PayrollPeriod::create([
            'name' => 'Period '.($offset + 1),
            'start_date' => now()->subMonths($offset + 1)->startOfMonth(),
            'end_date' => now()->subMonths($offset + 1)->endOfMonth(),
            'pay_date' => now()->subMonths($offset)->startOfMonth(),
            'frequency' => 'monthly',
            'status' => 'open',
        ]);

        return PayrollRun::create([
            'payroll_period_id' => $period->id,
            'run_number' => 'PR-'.now()->year.'-'.str_pad((string) ($offset + 1), 4, '0', STR_PAD_LEFT),
            'status' => $status,
            'total_net' => $net,
        ]);
    }
}
