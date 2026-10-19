<?php

namespace Tests\Feature\HR;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The 201 file laid out for paper, under the company's letterhead. */
class EmployeePrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_prints_a_masked_sheet_under_the_company_name(): void
    {
        Setting::setMany(['company.name' => 'PrimePower Manpower']);
        $employee = Employee::factory()->create(['sss_number' => '34-1234567-8']);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get("/hr/employees/{$employee->id}/print")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Employees/Print')
                ->where('company.name', 'PrimePower Manpower')
                ->where('full', false)
                ->where('can.showFull', true)
                ->where('employee.data.full_name', $employee->full_name)
                ->where('employee.data.sss_number', fn (string $value) => ! str_contains($value, '1234567')));

        $audit = AuditLog::where('event', 'accessed')->where('auditable_id', $employee->id)->latest('id')->firstOrFail();
        $this->assertSame('print', $audit->new_values['how']);
        $this->assertFalse($audit->new_values['full_numbers']);
    }

    public function test_full_numbers_print_only_when_asked_and_are_logged_as_such(): void
    {
        $employee = Employee::factory()->create(['sss_number' => '34-1234567-8']);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get("/hr/employees/{$employee->id}/print?full=1")
            ->assertInertia(fn (Assert $page) => $page
                ->where('full', true)
                ->where('employee.data.sss_number', '34-1234567-8'));

        $audit = AuditLog::where('event', 'accessed')->where('auditable_id', $employee->id)->latest('id')->firstOrFail();
        $this->assertTrue($audit->new_values['full_numbers']);
    }

    public function test_a_supervisor_cannot_ask_for_numbers_they_may_not_see(): void
    {
        $supervisorUser = User::factory()->supervisor()->create();
        $supervisor = Employee::factory()->create(['user_id' => $supervisorUser->id]);
        $report = Employee::factory()->create(['supervisor_id' => $supervisor->id]);

        $this->actingAs($supervisorUser)
            ->get("/hr/employees/{$report->id}/print?full=1")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('full', false)
                ->where('can.showFull', false)
                ->missing('employee.data.sss_number')
                ->missing('employee.data.basic_salary'));
    }

    public function test_an_employee_prints_their_own_sheet_and_nobody_elses(): void
    {
        $user = User::factory()->create();
        $own = Employee::factory()->create(['user_id' => $user->id]);
        $other = Employee::factory()->create();

        $this->actingAs($user)->get("/hr/employees/{$own->id}/print")->assertOk();
        $this->actingAs($user)->get("/hr/employees/{$other->id}/print")->assertForbidden();
    }
}
