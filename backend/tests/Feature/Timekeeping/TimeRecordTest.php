<?php

namespace Tests\Feature\Timekeeping;

use App\Models\AttendanceLog;
use App\Models\Holiday;
use App\Models\User;
use App\Services\AttendanceCutoffService;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

class TimeRecordTest extends TimekeepingTestCase
{
    public function test_hr_records_a_day_computed_against_the_employees_shift(): void
    {
        $employee = $this->worker();

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/records', [
                'employee_id' => $employee->id,
                'work_date' => '2026-08-04',
                'time_in' => '08:25',
                'time_out' => '17:00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $log = AttendanceLog::firstOrFail();
        $this->assertSame(AttendanceLog::STATUS_LATE, $log->status);
        $this->assertSame(25, $log->late_minutes);
        $this->assertSame($this->dayShift->id, $log->shift_id);
    }

    public function test_recording_the_same_day_again_updates_rather_than_duplicates(): void
    {
        $employee = $this->worker();
        $this->record($employee, '2026-08-04', '08:25', '17:00');
        $this->record($employee, '2026-08-04', '08:00', '17:00');

        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertSame(AttendanceLog::STATUS_PRESENT, AttendanceLog::first()->status);
    }

    public function test_a_blank_day_is_named_by_the_calendar(): void
    {
        $employee = $this->worker();
        Holiday::create(['date' => '2026-08-21', 'name' => 'Ninoy Aquino Day', 'type' => Holiday::TYPE_SPECIAL]);

        $this->assertSame(AttendanceLog::STATUS_ABSENT, $this->record($employee, '2026-08-04', null, null)->status);
        $this->assertSame(AttendanceLog::STATUS_REST_DAY, $this->record($employee, '2026-08-08', null, null)->status);
        $this->assertSame(AttendanceLog::STATUS_HOLIDAY, $this->record($employee, '2026-08-21', null, null)->status);
    }

    public function test_an_employee_cannot_write_their_own_record(): void
    {
        $employee = $this->worker();

        $this->actingAs($employee->user)
            ->post('/hr/timekeeping/records', [
                'employee_id' => $employee->id,
                'work_date' => '2026-08-04',
                'time_in' => '08:00',
                'time_out' => '17:00',
            ])
            ->assertForbidden();
    }

    public function test_an_employee_sees_only_their_own_days(): void
    {
        $mine = $this->worker();
        $theirs = $this->worker();
        $this->record($mine, '2026-09-01', '08:00', '17:00');
        $this->record($theirs, '2026-09-01', '08:00', '17:00');

        // With nobody else to choose, the screen opens straight on their DTR.
        $this->actingAs($mine->user)
            ->get('/hr/timekeeping')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Records')
                ->where('view', 'employee')
                ->where('sheet.employee.id', $mine->id)
                ->where('can.manage', false));

        // Asking for somebody else's is not a way round the scope.
        $this->actingAs($mine->user)
            ->get("/hr/timekeeping?employee={$theirs->id}")
            ->assertInertia(fn (Assert $page) => $page->where('sheet', null));
    }

    public function test_hr_opens_on_the_roster_with_each_employees_totals(): void
    {
        $employee = $this->worker();
        $this->record($employee, '2026-09-01', '08:20', '17:00');
        $this->record($employee, '2026-09-02', '08:00', '17:00');
        $this->record($employee, '2026-09-03', null, null);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping?from=2026-09-01&to=2026-09-15')
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'roster')
                ->has('roster.data', 1)
                ->where('roster.data.0.id', $employee->id)
                ->where('roster.data.0.worked', 2)
                ->where('roster.data.0.late', 1)
                ->where('roster.data.0.absent', 1)
                ->where('roster.data.0.late_minutes', 20));
    }

    public function test_an_employees_dtr_lists_every_date_including_the_unrecorded_ones(): void
    {
        $employee = $this->worker();
        $this->record($employee, '2026-09-01', '08:00', '17:00');

        $this->actingAs($this->hr())
            ->get("/hr/timekeeping?employee={$employee->id}&from=2026-09-01&to=2026-09-07")
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'employee')
                ->has('sheet.days', 7)
                ->where('sheet.days.0.date', '2026-09-01')
                ->where('sheet.days.0.log.time_in', '08:00')
                // The 2nd was a working day nobody recorded: a gap, not an absence.
                ->where('sheet.days.1.log', null)
                ->where('sheet.days.1.rest_day', false)
                // The 5th is a Saturday.
                ->where('sheet.days.4.rest_day', true)
                ->where('sheet.totals.worked', 1));
    }

    public function test_a_status_tile_still_opens_the_days_it_counted_across_everyone(): void
    {
        $one = $this->worker();
        $two = $this->worker();
        $this->record($one, '2026-09-01', '08:00', null);
        $this->record($two, '2026-09-02', '08:00', null);
        $this->record($two, '2026-09-03', '08:00', '17:00');

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping?status=incomplete&from=2026-09-01&to=2026-09-15')
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'days')
                ->where('summary.incomplete', 2)
                ->has('logs.data', 2));
    }

    public function test_a_closed_cutoff_refuses_changes_to_its_days(): void
    {
        $employee = $this->worker();
        $period = $this->period();
        app(AttendanceCutoffService::class)->close($period, $this->hr());

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/records', [
                'employee_id' => $employee->id,
                'work_date' => '2026-08-04',
                'time_in' => '08:00',
                'time_out' => '17:00',
            ])
            ->assertSessionHasErrors('work_date');

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_a_biometric_csv_imports_and_names_the_rows_it_skipped(): void
    {
        $employee = $this->worker();
        $csv = "employee_number,date,time_in,time_out\n"
            ."{$employee->employee_number},2026-09-01,07:58,17:02\n"
            ."NOBODY,2026-09-01,08:00,17:00\n";

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/records/import', [
                'file' => UploadedFile::fake()->createWithContent('dtr.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'NOBODY'));

        $this->assertSame(AttendanceLog::SOURCE_IMPORT, AttendanceLog::firstOrFail()->source);
    }

    public function test_links_to_the_old_screens_land_on_the_records(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/timekeeping/period')
            ->assertRedirect(route('hr.timekeeping.records'))
            ->assertSessionHas('info');
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/hr/timekeeping')->assertRedirect('/login');
    }
}
