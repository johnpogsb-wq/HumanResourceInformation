<?php

namespace Tests\Feature\HR;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TimekeepingTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_can_view_the_dtr_screen(): void
    {
        AttendanceLog::factory()->count(3)->on(now()->startOfMonth()->toDateString())->create();

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Index')
                ->has('logs.data', 3)
                ->has('logs.meta.links')
                ->where('summary.records', 3)
                ->where('can.manage', true),
            );
    }

    public function test_the_range_defaults_to_the_current_month(): void
    {
        AttendanceLog::factory()->on(now()->startOfMonth()->toDateString())->create();
        AttendanceLog::factory()->on(now()->subMonths(2)->toDateString())->create();

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping')
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
    }

    public function test_hr_can_record_a_time_entry_and_figures_are_derived(): void
    {
        $employee = Employee::factory()->create();
        $shift = Shift::factory()->create();
        $date = now()->subDay()->toDateString();

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping', [
                'employee_id' => $employee->id,
                'log_date' => $date,
                'shift_id' => $shift->id,
                'time_in' => '08:35',
                'time_out' => '17:00',
            ])
            ->assertRedirect();

        $log = AttendanceLog::firstOrFail();

        $this->assertSame(35, $log->late_minutes);
        $this->assertSame(AttendanceLog::STATUS_LATE, $log->status);
        $this->assertEquals(7.42, (float) $log->hours_worked);
    }

    public function test_recording_the_same_day_twice_updates_rather_than_duplicates(): void
    {
        $employee = Employee::factory()->create();
        $shift = Shift::factory()->create();
        $date = now()->subDay()->toDateString();
        $hr = $this->hr();

        $payload = [
            'employee_id' => $employee->id,
            'log_date' => $date,
            'shift_id' => $shift->id,
            'time_in' => '08:00',
            'time_out' => '17:00',
        ];

        $this->actingAs($hr)->post('/hr/timekeeping', $payload);
        $this->actingAs($hr)->post('/hr/timekeeping', [...$payload, 'time_out' => '19:00']);

        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertSame(120, AttendanceLog::first()->overtime_minutes);
    }

    public function test_the_shift_is_resolved_from_the_employee_schedule(): void
    {
        $employee = Employee::factory()->create();
        $shift = Shift::factory()->create();

        // 2026-03-10 is a Tuesday (ISO weekday 2).
        EmployeeSchedule::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'days_of_week' => [1, 2, 3, 4, 5],
        ]);

        $this->actingAs($this->hr())->post('/hr/timekeeping', [
            'employee_id' => $employee->id,
            'log_date' => '2026-03-10',
            'time_in' => '08:00',
            'time_out' => '17:00',
        ]);

        $this->assertSame($shift->id, AttendanceLog::firstOrFail()->shift_id);
    }

    public function test_a_scheduled_employee_off_roster_is_marked_rest_day(): void
    {
        $employee = Employee::factory()->create();
        $shift = Shift::factory()->create();

        EmployeeSchedule::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'days_of_week' => [1, 2, 3, 4, 5], // weekdays only
        ]);

        // 2026-03-15 is a Sunday.
        $this->actingAs($this->hr())->post('/hr/timekeeping', [
            'employee_id' => $employee->id,
            'log_date' => '2026-03-15',
        ]);

        $this->assertSame(AttendanceLog::STATUS_REST_DAY, AttendanceLog::firstOrFail()->status);
    }

    public function test_a_holiday_is_classified_as_such(): void
    {
        Holiday::create(['name' => 'Test Holiday', 'date' => '2026-03-12', 'type' => 'regular']);
        $employee = Employee::factory()->create();

        $this->actingAs($this->hr())->post('/hr/timekeeping', [
            'employee_id' => $employee->id,
            'log_date' => '2026-03-12',
        ]);

        $this->assertSame(AttendanceLog::STATUS_HOLIDAY, AttendanceLog::firstOrFail()->status);
    }

    public function test_future_dated_records_are_rejected(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping', [
                'employee_id' => Employee::factory()->create()->id,
                'log_date' => now()->addWeek()->toDateString(),
                'time_in' => '08:00',
            ])
            ->assertSessionHasErrors('log_date');
    }

    public function test_a_time_out_without_a_time_in_is_rejected(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping', [
                'employee_id' => Employee::factory()->create()->id,
                'log_date' => now()->subDay()->toDateString(),
                'time_out' => '17:00',
            ])
            ->assertSessionHasErrors('time_in');
    }

    public function test_malformed_times_are_rejected(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping', [
                'employee_id' => Employee::factory()->create()->id,
                'log_date' => now()->subDay()->toDateString(),
                'time_in' => '8am',
            ])
            ->assertSessionHasErrors('time_in');
    }

    public function test_employees_only_see_their_own_time_records(): void
    {
        $user = User::factory()->create();
        $own = Employee::factory()->create(['user_id' => $user->id]);

        AttendanceLog::factory()->on(now()->startOfMonth()->toDateString())->create(['employee_id' => $own->id]);
        AttendanceLog::factory()->count(4)->on(now()->startOfMonth()->toDateString())->create();

        $this->actingAs($user)
            ->get('/hr/timekeeping')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 1)
                ->where('can.manage', false),
            );
    }

    public function test_a_supervisor_sees_their_direct_reports(): void
    {
        $user = User::factory()->supervisor()->create();
        $supervisor = Employee::factory()->create(['user_id' => $user->id]);
        $report = Employee::factory()->create(['supervisor_id' => $supervisor->id]);
        $date = now()->startOfMonth()->toDateString();

        AttendanceLog::factory()->on($date)->create(['employee_id' => $report->id]);
        AttendanceLog::factory()->on($date)->create(['employee_id' => $supervisor->id]);
        AttendanceLog::factory()->count(3)->on($date)->create();

        $this->actingAs($user)
            ->get('/hr/timekeeping')
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 2));
    }

    public function test_non_hr_roles_cannot_record_time(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post('/hr/timekeeping', [
            'employee_id' => $employee->id,
            'log_date' => now()->subDay()->toDateString(),
            'time_in' => '08:00',
            'time_out' => '17:00',
        ])->assertForbidden();

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_only_hr_can_delete_a_record(): void
    {
        $log = AttendanceLog::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->delete("/hr/timekeeping/{$log->id}")->assertForbidden();
        $this->actingAs($this->hr())->delete("/hr/timekeeping/{$log->id}")->assertRedirect();

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/timekeeping')->assertRedirect('/login');
    }

    public function test_the_summary_totals_the_filtered_range(): void
    {
        $date = now()->startOfMonth()->toDateString();
        AttendanceLog::factory()->count(2)->on($date)->create();
        AttendanceLog::factory()->on($date)->absent()->create();
        AttendanceLog::factory()->on($date)->late(20)->create();

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.records', 4)
                ->where('summary.absent', 1)
                ->where('summary.late', 1),
            );
    }

    public function test_the_api_exposes_the_same_records(): void
    {
        AttendanceLog::factory()->count(3)->on(now()->toDateString())->create();
        $hr = $this->hr();

        $this->actingAs($hr)
            ->getJson('/api/v1/attendance?from='.now()->startOfMonth()->toDateString())
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'log_date', 'status', 'hours_worked']]]);

        $this->actingAs($hr)
            ->getJson('/api/v1/attendance/summary')
            ->assertOk()
            ->assertJsonPath('data.records', 3);
    }

    public function test_the_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/attendance')->assertUnauthorized();
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
