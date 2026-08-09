<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OvertimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_overtime_screen_lists_requests(): void
    {
        $this->file(Employee::factory()->create(), $this->hr());

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/overtime')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Overtime')
                ->has('requests.data', 1)
                ->where('summary.pending', 1),
            );
    }

    public function test_hours_are_computed_from_the_filed_window(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->hr())->post('/hr/timekeeping/overtime', [
            'employee_id' => $employee->id,
            'date' => '2026-03-10',
            'start_time' => '17:00',
            'end_time' => '20:30',
            'reason' => 'Month-end dispatch backlog.',
        ])->assertRedirect();

        $this->assertEquals(3.5, (float) OvertimeRequest::firstOrFail()->hours);
    }

    public function test_overtime_running_past_midnight_rolls_to_the_next_day(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->hr())->post('/hr/timekeeping/overtime', [
            'employee_id' => $employee->id,
            'date' => '2026-03-10',
            'start_time' => '22:00',
            'end_time' => '02:00',
            'reason' => 'Overnight delivery run.',
        ]);

        $request = OvertimeRequest::firstOrFail();

        $this->assertEquals(4.0, (float) $request->hours);
        $this->assertSame('2026-03-11 02:00', $request->end_time->format('Y-m-d H:i'));
    }

    public function test_an_employee_can_only_file_for_themselves(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);
        $other = Employee::factory()->create();

        $this->actingAs($user)->post('/hr/timekeeping/overtime', [
            'employee_id' => $other->id,
            'date' => '2026-03-10',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'reason' => 'Filing for a colleague.',
        ])->assertSessionHasErrors('employee_id');

        $this->assertDatabaseCount('overtime_requests', 0);
    }

    public function test_a_duplicate_open_request_for_the_same_day_is_rejected(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->hr();

        $payload = [
            'employee_id' => $employee->id,
            'date' => '2026-03-10',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'reason' => 'Backlog clearing work.',
        ];

        $this->actingAs($hr)->post('/hr/timekeeping/overtime', $payload)->assertRedirect();
        $this->actingAs($hr)->post('/hr/timekeeping/overtime', $payload)
            ->assertSessionHasErrors('date');

        $this->assertDatabaseCount('overtime_requests', 1);
    }

    public function test_a_supervisor_can_approve_a_direct_report(): void
    {
        $supervisorUser = User::factory()->supervisor()->create();
        $supervisor = Employee::factory()->create(['user_id' => $supervisorUser->id]);
        $report = Employee::factory()->create(['supervisor_id' => $supervisor->id]);

        $request = $this->file($report, $this->hr());

        $this->actingAs($supervisorUser)
            ->post("/hr/timekeeping/overtime/{$request->id}/decide", [
                'status' => 'approved',
                'remarks' => 'Confirmed with dispatch.',
            ])
            ->assertRedirect();

        $request->refresh();

        $this->assertSame(OvertimeRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($supervisorUser->id, $request->approved_by);
        $this->assertNotNull($request->acted_at);
    }

    public function test_nobody_can_approve_their_own_overtime(): void
    {
        $user = User::factory()->supervisor()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $request = $this->file($employee, $this->hr());

        $this->actingAs($user)
            ->post("/hr/timekeeping/overtime/{$request->id}/decide", ['status' => 'approved'])
            ->assertForbidden();

        $this->assertSame(OvertimeRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_an_unrelated_supervisor_cannot_approve(): void
    {
        $user = User::factory()->supervisor()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $request = $this->file(Employee::factory()->create(), $this->hr());

        $this->actingAs($user)
            ->post("/hr/timekeeping/overtime/{$request->id}/decide", ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $request = $this->file(Employee::factory()->create(), $this->hr());
        $hr = $this->hr();

        $this->actingAs($hr)->post("/hr/timekeeping/overtime/{$request->id}/decide", [
            'status' => 'approved',
        ])->assertRedirect();

        $this->actingAs($hr)->post("/hr/timekeeping/overtime/{$request->id}/decide", [
            'status' => 'rejected',
        ])->assertForbidden();

        $this->assertSame(OvertimeRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    public function test_the_requester_can_cancel_while_pending(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);
        $request = $this->file($employee, $this->hr());

        $this->actingAs($user)
            ->post("/hr/timekeeping/overtime/{$request->id}/cancel")
            ->assertRedirect();

        $this->assertSame(OvertimeRequest::STATUS_CANCELLED, $request->fresh()->status);
    }

    public function test_only_approved_hours_are_summarised(): void
    {
        $hr = $this->hr();
        $approved = $this->file(Employee::factory()->create(), $hr);
        $this->file(Employee::factory()->create(), $hr);

        $this->actingAs($hr)->post("/hr/timekeeping/overtime/{$approved->id}/decide", [
            'status' => 'approved',
        ]);

        $this->actingAs($hr)
            ->get('/hr/timekeeping/overtime')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.approved', 1)
                ->where('summary.pending', 1)
                // JSON has one number type, so a whole 2.0 arrives as 2.
                ->where('summary.approved_hours', 2),
            );
    }

    public function test_a_reason_is_required(): void
    {
        $this->actingAs($this->hr())->post('/hr/timekeeping/overtime', [
            'employee_id' => Employee::factory()->create()->id,
            'date' => '2026-03-10',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'reason' => 'ot',
        ])->assertSessionHasErrors('reason');
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }

    private function file(Employee $employee, User $actor): OvertimeRequest
    {
        $this->actingAs($actor)->post('/hr/timekeeping/overtime', [
            'employee_id' => $employee->id,
            'date' => now()->subDay()->toDateString(),
            'start_time' => '17:00',
            'end_time' => '19:00',
            'reason' => 'Extra dispatch coverage required.',
        ])->assertRedirect();

        return OvertimeRequest::where('employee_id', $employee->id)->latest('id')->firstOrFail();
    }
}
