<?php

namespace Tests\Feature\HR;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceExceptionScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_time_out_on_a_past_day_is_flagged(): void
    {
        AttendanceLog::factory()->create([
            'log_date' => now()->subDays(2)->toDateString(),
            'time_in' => now()->subDays(2)->setTime(8, 0),
            'time_out' => null,
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Exceptions')
                ->has('exceptions', 1)
                ->where('exceptions.0.type', AttendanceExceptionScanner::TYPE_MISSING_PUNCH)
                ->where('exceptions.0.severity', 'critical'),
            );
    }

    public function test_a_missing_time_out_today_is_not_flagged(): void
    {
        // Still clocked in — normal, not an exception.
        AttendanceLog::factory()->create([
            'log_date' => now()->toDateString(),
            'time_in' => now()->setTime(8, 0),
            'time_out' => null,
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertInertia(fn (Assert $page) => $page->has('exceptions', 0));
    }

    public function test_excessive_lateness_is_flagged(): void
    {
        AttendanceLog::factory()->late(90)->create(['log_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page
                ->has('exceptions', 1)
                ->where('exceptions.0.type', AttendanceExceptionScanner::TYPE_EXCESSIVE_LATE),
            );
    }

    public function test_ordinary_lateness_under_the_threshold_is_not_flagged(): void
    {
        AttendanceLog::factory()->late(15)->create(['log_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page->has('exceptions', 0));
    }

    public function test_excessive_overtime_is_flagged(): void
    {
        AttendanceLog::factory()->create([
            'log_date' => now()->subDay()->toDateString(),
            'overtime_minutes' => 300, // 5 hours, above the 4-hour config threshold
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page
                ->has('exceptions', 1)
                ->where('exceptions.0.type', AttendanceExceptionScanner::TYPE_EXCESSIVE_OVERTIME),
            );
    }

    public function test_frequent_lateness_is_flagged_as_a_pattern(): void
    {
        $employee = Employee::factory()->create();

        // Three late days, none individually over the per-day threshold —
        // all inside the current month, the default filter range.
        foreach ([1, 2, 3] as $daysAgo) {
            AttendanceLog::factory()->late(20)->create([
                'employee_id' => $employee->id,
                'log_date' => now()->subDays($daysAgo)->toDateString(),
            ]);
        }

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page
                ->has('exceptions', 1)
                ->where('exceptions.0.type', AttendanceExceptionScanner::TYPE_FREQUENT_LATE)
                ->where('exceptions.0.employee_id', $employee->id),
            );
    }

    public function test_frequent_absence_is_flagged_as_a_pattern(): void
    {
        $employee = Employee::factory()->create();

        foreach ([1, 2, 3] as $daysAgo) {
            AttendanceLog::factory()->absent()->create([
                'employee_id' => $employee->id,
                'log_date' => now()->subDays($daysAgo)->toDateString(),
            ]);
        }

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page
                ->has('exceptions', 1)
                ->where('exceptions.0.type', AttendanceExceptionScanner::TYPE_FREQUENT_ABSENCE)
                ->where('exceptions.0.severity', 'critical'),
            );
    }

    public function test_a_clean_record_produces_no_exceptions(): void
    {
        AttendanceLog::factory()->create(['log_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page->has('exceptions', 0));
    }

    public function test_the_summary_counts_by_severity(): void
    {
        AttendanceLog::factory()->create([
            'log_date' => now()->subDays(2)->toDateString(),
            'time_in' => now()->subDays(2)->setTime(8, 0),
            'time_out' => null,
        ]);
        AttendanceLog::factory()->late(90)->create(['log_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total', 2)
                ->where('summary.critical', 1)
                ->where('summary.warning', 1),
            );
    }

    public function test_filtering_by_type_narrows_the_list(): void
    {
        AttendanceLog::factory()->create([
            'log_date' => now()->subDays(2)->toDateString(),
            'time_in' => now()->subDays(2)->setTime(8, 0),
            'time_out' => null,
        ]);
        AttendanceLog::factory()->late(90)->create(['log_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions?type='.AttendanceExceptionScanner::TYPE_MISSING_PUNCH)
            ->assertInertia(fn (Assert $page) => $page
                ->has('exceptions', 1)
                ->where('exceptions.0.type', AttendanceExceptionScanner::TYPE_MISSING_PUNCH),
            );
    }

    public function test_an_employee_only_sees_exceptions_for_their_own_records(): void
    {
        $user = User::factory()->create();
        $own = Employee::factory()->create(['user_id' => $user->id]);

        AttendanceLog::factory()->late(90)->create([
            'employee_id' => $own->id,
            'log_date' => now()->subDay()->toDateString(),
        ]);
        AttendanceLog::factory()->late(90)->create(['log_date' => now()->subDay()->toDateString()]);

        $this->actingAs($user)
            ->get('/hr/timekeeping/exceptions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('exceptions', 1));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/timekeeping/exceptions')->assertRedirect('/login');
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
