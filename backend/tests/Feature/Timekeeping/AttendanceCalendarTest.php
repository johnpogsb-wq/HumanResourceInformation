<?php

namespace Tests\Feature\Timekeeping;

use App\Mail\AttendanceCalendarMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AttendanceCalendar;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

class AttendanceCalendarTest extends TimekeepingTestCase
{
    public function test_hr_sees_an_employees_month_with_the_punches_on_each_day(): void
    {
        $employee = $this->worker();
        $this->record($employee, '2026-09-01', '08:25', '17:00');

        $this->actingAs($this->hr())
            ->get("/hr/timekeeping/calendar?employee={$employee->id}&month=2026-09")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Calendar')
                ->where('employee.id', $employee->id)
                ->where('calendar.month', '2026-09')
                ->has('calendar.days', 30)
                // 1 September 2026 is a Tuesday: one blank before it on a Monday-first grid.
                ->where('calendar.leading_blanks', 1)
                ->where('calendar.days.0.time_in', '08:25')
                ->where('calendar.days.0.time_out', '17:00')
                ->where('calendar.days.0.status', 'late')
                ->where('calendar.days.0.late_minutes', 25)
                ->where('calendar.days.0.shift.start', '08:00')
                ->where('calendar.summary.late', 1));
    }

    public function test_a_working_day_with_no_row_is_not_reported_as_absent(): void
    {
        $employee = $this->worker();

        $this->actingAs($this->hr())
            ->get("/hr/timekeeping/calendar?employee={$employee->id}&month=2026-09")
            ->assertInertia(fn (Assert $page) => $page
                ->where('calendar.days.1.status', null)
                ->where('calendar.days.1.rest_day', false)
                ->where('calendar.summary.absent', 0)
                // Saturday 5 September is a rest day by the schedule even with no row.
                ->where('calendar.days.4.rest_day', true));
    }

    public function test_an_employee_opens_on_their_own_calendar_and_cannot_open_anybody_elses(): void
    {
        $mine = $this->worker();
        $theirs = $this->worker();

        $this->actingAs($mine->user)
            ->get('/hr/timekeeping/calendar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('employee.id', $mine->id)
                ->where('can.email', false)
                ->where('email', null));

        $this->actingAs($mine->user)
            ->get("/hr/timekeeping/calendar?employee={$theirs->id}")
            ->assertForbidden();
    }

    public function test_hr_emails_an_employee_their_month_and_the_send_is_audited(): void
    {
        Mail::fake();
        $employee = $this->worker(['email' => 'juan.cruz@gmail.com']);
        $this->record($employee, '2026-09-01', '08:00', '17:00');

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/calendar/email', ['employee_id' => $employee->id, 'month' => '2026-09'])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(AttendanceCalendarMail::class, fn (AttendanceCalendarMail $mail) => $mail->hasTo('juan.cruz@gmail.com')
            && $mail->employee->is($employee)
            && $mail->month['month'] === '2026-09');

        $row = AuditLog::where('event', 'exported')->latest('id')->firstOrFail();
        $this->assertSame('attendance:email', $row->new_values['report']);
        $this->assertSame($employee->id, $row->new_values['employee_id']);
        // Masked on the row, never the whole address.
        $this->assertSame('ju*******@gmail.com', $row->new_values['recipient']);
    }

    public function test_the_email_carries_the_month_itself_not_only_a_link(): void
    {
        $employee = $this->worker(['email' => 'juan.cruz@gmail.com']);
        $this->record($employee, '2026-09-01', '08:25', '17:00');

        $month = app(AttendanceCalendar::class)->month($employee, now()->setDate(2026, 9, 1));
        $html = (new AttendanceCalendarMail($employee, $month, 'https://core2.test/x'))->render();

        $this->assertStringContainsString('September 2026', $html);
        $this->assertStringContainsString('08:25', $html);
        $this->assertStringContainsString('25 min late', $html);
        $this->assertStringContainsString('https://core2.test/x', $html);
    }

    public function test_an_employee_cannot_send_attendance_emails(): void
    {
        Mail::fake();
        $employee = $this->worker(['email' => 'juan.cruz@gmail.com']);

        $this->actingAs($employee->user)
            ->post('/hr/timekeeping/calendar/email', ['employee_id' => $employee->id, 'month' => '2026-09'])
            ->assertForbidden();

        $this->actingAs($employee->user)
            ->post('/hr/timekeeping/calendar/email-all', ['month' => '2026-09'])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_a_placeholder_address_is_refused_rather_than_sent_to_nowhere(): void
    {
        Mail::fake();
        $employee = $this->worker(['email' => 'someone@example.com']);

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/calendar/email', ['employee_id' => $employee->id, 'month' => '2026-09'])
            ->assertSessionHas('error');

        Mail::assertNothingSent();
        $this->assertSame(0, AuditLog::where('event', 'exported')->count());
    }

    public function test_email_everyone_sends_each_their_own_and_names_who_was_skipped(): void
    {
        Mail::fake();
        $reachable = $this->worker(['email' => 'ana@gmail.com']);
        $placeholder = $this->worker(['email' => 'ben@example.org']);
        $noRecord = $this->worker(['email' => 'carl@gmail.com']);

        $this->record($reachable, '2026-09-01', '08:00', '17:00');
        $this->record($placeholder, '2026-09-01', '08:00', '17:00');

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/calendar/email-all', ['month' => '2026-09'])
            ->assertSessionHas('success', 'Sent September 2026 attendance to 1 of 2 employee(s).')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, $placeholder->full_name));

        Mail::assertSent(AttendanceCalendarMail::class, 1);
        Mail::assertSent(AttendanceCalendarMail::class, fn ($mail) => $mail->hasTo('ana@gmail.com'));
        // Nobody is sent an empty calendar.
        Mail::assertNotSent(AttendanceCalendarMail::class, fn ($mail) => $mail->hasTo('carl@gmail.com'));
        $this->assertNotNull($noRecord);
    }

    public function test_a_supervisor_may_view_but_not_send(): void
    {
        $supervisor = $this->worker();
        $supervisor->user->update(['role' => User::ROLE_SUPERVISOR]);
        $report = $this->worker(['supervisor_id' => $supervisor->id]);

        $this->actingAs($supervisor->user->fresh())
            ->get("/hr/timekeeping/calendar?employee={$report->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.email', false));
    }
}
