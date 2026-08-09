<?php

namespace Tests\Feature\HR;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_report_aggregates_per_employee(): void
    {
        $employee = Employee::factory()->create();
        $date = now()->startOfMonth()->toDateString();

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => $date,
            'hours_worked' => 8,
            'late_minutes' => 15,
            'overtime_minutes' => 60,
        ]);
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => now()->startOfMonth()->addDay()->toDateString(),
            'hours_worked' => 7,
            'late_minutes' => 0,
            'overtime_minutes' => 30,
        ]);
        AttendanceLog::factory()->absent()->create([
            'employee_id' => $employee->id,
            'log_date' => now()->startOfMonth()->addDays(2)->toDateString(),
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Reports')
                ->has('rows', 1)
                ->where('rows.0.days_present', 2)
                ->where('rows.0.days_absent', 1)
                ->where('rows.0.late_count', 1)
                ->where('rows.0.late_minutes', 15)
                ->where('rows.0.overtime_hours', 1.5)
                ->where('rows.0.total_hours', 15),
            );
    }

    public function test_the_period_resolves_to_a_concrete_range(): void
    {
        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/reports?period=daily&anchor=2026-03-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-03-10')
                ->where('filters.to', '2026-03-10'),
            );

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/reports?period=monthly&anchor=2026-03-10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-03-01')
                ->where('filters.to', '2026-03-31'),
            );
    }

    public function test_records_outside_the_period_are_excluded(): void
    {
        $employee = Employee::factory()->create();

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-03-10',
        ]);
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-05-10',
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/reports?period=monthly&anchor=2026-03-15')
            ->assertInertia(fn (Assert $page) => $page->where('rows.0.days_present', 1));
    }

    public function test_the_report_exports_as_csv(): void
    {
        $employee = Employee::factory()->create(['first_name' => 'Elena', 'last_name' => 'Marquez']);
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-03-10',
        ]);

        $response = $this->actingAs($this->hr())
            ->get('/hr/timekeeping/reports/export?period=monthly&anchor=2026-03-10')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        // fputcsv quotes headers containing spaces — still valid CSV.
        $this->assertStringContainsString('"Employee Number",Employee,Department', $csv);
        $this->assertStringContainsString('Elena', $csv);
        $this->assertStringContainsString($employee->employee_number, $csv);
    }

    public function test_employees_only_see_themselves_in_the_report(): void
    {
        $user = User::factory()->create();
        $own = Employee::factory()->create(['user_id' => $user->id]);
        $date = now()->startOfMonth()->toDateString();

        AttendanceLog::factory()->create(['employee_id' => $own->id, 'log_date' => $date]);
        AttendanceLog::factory()->count(3)->create(['log_date' => $date]);

        $this->actingAs($user)
            ->get('/hr/timekeeping/reports')
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1));
    }

    // --- Bulk import -----------------------------------------------------

    public function test_hr_can_import_a_csv_of_time_records(): void
    {
        $employee = Employee::factory()->create();
        $date = now()->subDay()->toDateString();

        $csv = "employee_number,date,time_in,time_out\n"
            ."{$employee->employee_number},{$date},08:00,17:00\n";

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/import', [
                'file' => UploadedFile::fake()->createWithContent('dtr.csv', $csv),
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertSame('biometric', AttendanceLog::firstOrFail()->source);
    }

    public function test_bad_rows_are_reported_without_aborting_the_batch(): void
    {
        $employee = Employee::factory()->create();
        $date = now()->subDay()->toDateString();

        $csv = "employee_number,date,time_in,time_out\n"
            ."{$employee->employee_number},{$date},08:00,17:00\n"
            ."PPM-9999-9999,{$date},08:00,17:00\n"          // unknown employee
            ."{$employee->employee_number},not-a-date,08:00,17:00\n"
            ."{$employee->employee_number},{$date},99:99,17:00\n";

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/import', [
                'file' => UploadedFile::fake()->createWithContent('dtr.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHas('importErrors', fn ($errors) => count($errors) === 3);

        // The one good row still landed.
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_a_file_missing_required_columns_is_rejected(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/import', [
                'file' => UploadedFile::fake()->createWithContent('dtr.csv', "name,hours\nJuan,8\n"),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_imported_times_are_computed_like_manual_entries(): void
    {
        $employee = Employee::factory()->create();
        $date = now()->subDay()->toDateString();

        $csv = "employee_number,date,time_in,time_out\n"
            ."{$employee->employee_number},{$date},9:05,17:00\n"; // single-digit hour

        $this->actingAs($this->hr())->post('/hr/timekeeping/import', [
            'file' => UploadedFile::fake()->createWithContent('dtr.csv', $csv),
        ]);

        $log = AttendanceLog::firstOrFail();

        // No schedule assigned, so no shift and therefore no lateness — but the
        // hours still come through, which proves the time parsed.
        $this->assertEquals(7.92, (float) $log->hours_worked);
    }

    public function test_non_hr_roles_cannot_import(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post('/hr/timekeeping/import', [
                'file' => UploadedFile::fake()->createWithContent('dtr.csv', "employee_number,date\n"),
            ])
            ->assertForbidden();
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
