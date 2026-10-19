<?php

namespace Tests\Feature\Timekeeping;

use App\Models\AttendanceLog;
use App\Models\AttendancePunch;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Models\User;
use App\Services\FaceMatcher;
use App\Services\TimeClockService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The face-verified time clock. "Now" is the base class's Wednesday,
 * 16 September 2026, and each punch travels to its own minute — the server's
 * clock is the only time a punch can have.
 */
class TimeClockTest extends TimekeepingTestCase
{
    // --- Punching ---------------------------------------------------------

    public function test_a_matching_face_times_in_at_the_servers_clock(): void
    {
        $employee = $this->enrolledWorker();

        $this->punchAt($employee, '2026-09-16 07:55', 'time_in')->assertSessionHasNoErrors();

        $log = AttendanceLog::firstOrFail();
        $this->assertSame('2026-09-16 07:55', $log->time_in->format('Y-m-d H:i'));
        $this->assertSame(AttendanceLog::STATUS_INCOMPLETE, $log->status);
        $this->assertSame(AttendanceLog::SOURCE_FACE_CLOCK, $log->source);

        $punch = AttendancePunch::firstOrFail();
        $this->assertSame($log->id, $punch->attendance_log_id);
        $this->assertLessThan(0.1, $punch->match_distance);
        Storage::disk('local')->assertExists($punch->snapshot_path);
    }

    public function test_a_full_day_with_a_long_break_is_computed_from_the_punches(): void
    {
        $employee = $this->enrolledWorker();

        $this->punchAt($employee, '2026-09-16 07:55', 'time_in');
        $this->punchAt($employee, '2026-09-16 12:00', 'break_start');
        $this->punchAt($employee, '2026-09-16 13:30', 'break_end');
        $this->punchAt($employee, '2026-09-16 18:00', 'time_out')->assertSessionHasNoErrors();

        $log = AttendanceLog::firstOrFail();

        // 10h 05m on the premises, less the 90-minute break actually taken.
        $this->assertSame(515, $log->minutes_worked);
        $this->assertSame(0, $log->late_minutes);
        $this->assertSame(60, $log->overtime_minutes);
        $this->assertSame(AttendanceLog::STATUS_PRESENT, $log->status);
        $this->assertSame(4, AttendancePunch::count());
    }

    public function test_with_no_break_punched_the_scheduled_hour_comes_off(): void
    {
        $employee = $this->enrolledWorker();

        $this->punchAt($employee, '2026-09-16 08:20', 'time_in');
        $this->punchAt($employee, '2026-09-16 17:00', 'time_out');

        $log = AttendanceLog::firstOrFail();
        $this->assertSame(460, $log->minutes_worked);
        $this->assertSame(20, $log->late_minutes);
        $this->assertSame(AttendanceLog::STATUS_LATE, $log->status);
    }

    public function test_a_night_shift_is_closed_on_the_day_it_started(): void
    {
        $employee = $this->enrolledWorker();

        $this->punchAt($employee, '2026-09-16 22:00', 'time_in');
        $this->punchAt($employee, '2026-09-17 06:00', 'time_out')->assertSessionHasNoErrors();

        $log = AttendanceLog::firstOrFail();
        $this->assertSame('2026-09-16', $log->work_date->toDateString());
        $this->assertSame('2026-09-17 06:00', $log->time_out->format('Y-m-d H:i'));
        $this->assertSame(1, AttendanceLog::count());
    }

    // --- Out of order -------------------------------------------------------

    public function test_timing_out_before_timing_in_is_refused(): void
    {
        $employee = $this->enrolledWorker();

        $this->punchAt($employee, '2026-09-16 17:00', 'time_out')
            ->assertSessionHasErrors(['action' => 'You have not timed in yet. Press Time in first.']);

        $this->assertSame(0, AttendanceLog::count());
        $this->assertSame(0, AttendancePunch::count());
    }

    public function test_timing_out_while_on_break_is_refused(): void
    {
        $employee = $this->enrolledWorker();

        $this->punchAt($employee, '2026-09-16 08:00', 'time_in');
        $this->punchAt($employee, '2026-09-16 12:00', 'break_start');

        $this->punchAt($employee, '2026-09-16 17:00', 'time_out')->assertSessionHasErrors('action');
        $this->assertNull(AttendanceLog::firstOrFail()->time_out);
    }

    public function test_a_second_time_in_on_the_same_day_is_refused(): void
    {
        $employee = $this->enrolledWorker();

        $this->punchAt($employee, '2026-09-16 08:00', 'time_in');
        $this->punchAt($employee, '2026-09-16 17:00', 'time_out');

        $this->punchAt($employee, '2026-09-16 17:30', 'time_in')
            ->assertSessionHasErrors(['action' => 'You have already timed in and out today.']);
        $this->assertSame('17:00', AttendanceLog::firstOrFail()->time_out->format('H:i'));
    }

    public function test_a_forgotten_time_out_is_not_overwritten_by_a_new_time_in(): void
    {
        $employee = $this->enrolledWorker();

        $this->punchAt($employee, '2026-09-16 00:30', 'time_in');

        // Seventeen hours later the morning's punch is past the open window.
        $this->punchAt($employee, '2026-09-16 17:30', 'time_in')->assertSessionHasErrors('action');
        $this->assertSame('00:30', AttendanceLog::firstOrFail()->time_in->format('H:i'));
    }

    // --- The face -----------------------------------------------------------

    public function test_a_different_face_is_refused_and_the_attempt_is_audited(): void
    {
        $employee = $this->enrolledWorker();

        $this->punchAt($employee, '2026-09-16 08:00', 'time_in', $this->strangerFace())
            ->assertSessionHasErrors('face');

        $this->assertSame(0, AttendanceLog::count());
        $this->assertSame(0, AttendancePunch::count());
        $this->assertSame(1, AuditLog::where('event', 'clock_face_rejected')->where('auditable_id', $employee->id)->count());
    }

    public function test_somebody_with_no_enrolled_face_cannot_punch(): void
    {
        $employee = $this->worker();

        $this->punchAt($employee, '2026-09-16 08:00', 'time_in')
            ->assertSessionHasErrors('face');
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_a_malformed_reading_is_refused(): void
    {
        $employee = $this->enrolledWorker();

        $this->actingAs($employee->user)->post('/hr/timekeeping/clock/punch', [
            'action' => 'time_in',
            'descriptor' => json_encode([0.1, 0.2]),
            'snapshot' => UploadedFile::fake()->image('face.jpg', 320, 240),
        ])->assertSessionHasErrors('face');
    }

    public function test_the_template_is_encrypted_at_rest(): void
    {
        $employee = $this->enrolledWorker();

        $raw = DB::table('face_enrollments')->where('employee_id', $employee->id)->value('descriptors');

        $this->assertStringNotContainsString((string) $this->face()[1], $raw);
        $this->assertCount(3, $employee->faceEnrollment->descriptors);
    }

    // --- Enrolment ----------------------------------------------------------

    public function test_hr_enrolls_a_face_with_the_employees_consent(): void
    {
        $employee = $this->worker();

        $this->actingAs($this->hr())->post("/hr/timekeeping/faces/{$employee->id}", [
            'descriptors' => json_encode([$this->face(), $this->face(0.004), $this->face(-0.004)]),
            'snapshot' => UploadedFile::fake()->image('face.jpg', 320, 240),
            'consent' => '1',
        ])->assertSessionHasNoErrors();

        $enrollment = FaceEnrollment::where('employee_id', $employee->id)->firstOrFail();
        $this->assertNotNull($enrollment->consented_at);
        Storage::disk('local')->assertExists($enrollment->snapshot_path);

        // The audit row records the enrolment without the template.
        $audit = AuditLog::where('auditable_type', FaceEnrollment::class)->where('event', 'created')->firstOrFail();
        $this->assertArrayNotHasKey('descriptors', $audit->new_values ?? []);
    }

    public function test_enrolment_without_consent_is_refused(): void
    {
        $employee = $this->worker();

        $this->actingAs($this->hr())->post("/hr/timekeeping/faces/{$employee->id}", [
            'descriptors' => json_encode([$this->face(), $this->face(0.004), $this->face(-0.004)]),
            'snapshot' => UploadedFile::fake()->image('face.jpg', 320, 240),
        ])->assertSessionHasErrors('consent');

        $this->assertSame(0, FaceEnrollment::count());
    }

    public function test_hr_may_enroll_their_own_face_once_but_not_replace_it(): void
    {
        $hr = $this->hr();
        $own = Employee::factory()->create(['user_id' => $hr->id]);

        $this->actingAs($hr)->post("/hr/timekeeping/faces/{$own->id}", $this->enrolment())
            ->assertSessionHasNoErrors();

        $this->actingAs($hr)->post("/hr/timekeeping/faces/{$own->id}", $this->enrolment($this->strangerFace()))
            ->assertSessionHasErrors('employee');

        // Still the first face: a replacement is another HR staff member's.
        $this->assertLessThan(0.1, app(FaceMatcher::class)->distance($own->fresh()->faceEnrollment, $this->face()));
    }

    public function test_any_role_enrolls_their_own_face_once(): void
    {
        $employee = $this->worker();

        $this->actingAs($employee->user)
            ->get('/hr/timekeeping/faces')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mode', 'self')
                ->where('self.id', $employee->id)
                ->where('canEnroll', true));

        $this->actingAs($employee->user)->post("/hr/timekeeping/faces/{$employee->id}", $this->enrolment())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Your face is enrolled. You can use the time clock now.');

        $enrollment = $employee->fresh()->faceEnrollment;
        $this->assertSame($employee->user_id, $enrollment->enrolled_by);

        // A second go is refused: swapping a face in is how a borrowed
        // password would become a borrowed time-in.
        $this->actingAs($employee->user)->post("/hr/timekeeping/faces/{$employee->id}", $this->enrolment($this->strangerFace()))
            ->assertSessionHasErrors('employee');

        $this->actingAs($employee->user)
            ->get('/hr/timekeeping/faces')
            ->assertInertia(fn (Assert $page) => $page->where('canEnroll', false));
    }

    public function test_hr_sees_which_faces_were_self_enrolled(): void
    {
        $employee = $this->worker();
        $this->actingAs($employee->user)->post("/hr/timekeeping/faces/{$employee->id}", $this->enrolment());

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/faces')
            ->assertInertia(fn (Assert $page) => $page
                ->where('mode', 'manage')
                ->where('employees.data.0.enrollment.self_enrolled', true));
    }

    public function test_an_employee_cannot_enroll_or_remove_anybody_elses_face(): void
    {
        $employee = $this->worker();
        $other = $this->worker();

        $this->actingAs($employee->user)->post("/hr/timekeeping/faces/{$other->id}", $this->enrolment())
            ->assertForbidden();

        $enrolled = $this->enrolledWorker();
        $this->actingAs($employee->user)->delete("/hr/timekeeping/faces/{$enrolled->id}")->assertForbidden();
        $this->actingAs($employee->user)->get("/hr/timekeeping/faces/{$enrolled->id}/photo")->assertForbidden();
    }

    // --- The evidence -------------------------------------------------------

    public function test_a_punch_photo_is_the_owners_and_hrs_and_nobody_elses(): void
    {
        $employee = $this->enrolledWorker();
        $this->punchAt($employee, '2026-09-16 08:00', 'time_in');
        $punch = AttendancePunch::firstOrFail();

        $this->actingAs($employee->user)->get("/hr/timekeeping/punches/{$punch->id}/photo")->assertOk();
        $this->actingAs($this->worker()->user)->get("/hr/timekeeping/punches/{$punch->id}/photo")->assertForbidden();

        $this->actingAs($this->hr())->get("/hr/timekeeping/punches/{$punch->id}/photo")->assertOk();
        $this->assertSame(1, AuditLog::where('event', 'accessed')->where('auditable_type', AttendancePunch::class)->count());
    }

    public function test_old_snapshots_are_pruned_and_the_punch_stays(): void
    {
        $employee = $this->enrolledWorker();
        $this->punchAt($employee, '2026-09-16 08:00', 'time_in');
        $path = AttendancePunch::firstOrFail()->snapshot_path;

        $this->travelTo(Carbon::parse('2026-09-16 08:00')->addDays((int) config('timeclock.snapshot_retention_days') + 1));
        $this->artisan('timeclock:prune-snapshots')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        $this->assertNull(AttendancePunch::firstOrFail()->snapshot_path);
        $this->assertNotNull(AttendancePunch::firstOrFail()->punched_at);
    }

    public function test_the_clock_screen_says_what_can_be_pressed_next(): void
    {
        $employee = $this->enrolledWorker();
        $this->punchAt($employee, '2026-09-16 08:00', 'time_in');
        $this->travelTo(Carbon::parse('2026-09-16 09:00'));

        $this->actingAs($employee->user)
            ->get('/hr/timekeeping/clock')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Clock')
                ->where('enrolled', true)
                ->where('state.state', 'working')
                ->where('state.next', ['break_start', 'time_out'])
                ->has('punches', 1));
    }

    /**
     * `Permissions-Policy: camera=()` made the browser refuse the camera on
     * every device before the user was asked, which read as "camera access
     * was blocked" with nothing in the browser's settings to unblock.
     */
    public function test_the_site_lets_its_own_pages_use_the_camera(): void
    {
        $employee = $this->enrolledWorker();

        $policy = $this->actingAs($employee->user)->get('/hr/timekeeping/clock')->headers->get('Permissions-Policy');

        $this->assertStringContainsString('camera=(self)', $policy);
        $this->assertStringContainsString('microphone=()', $policy);
    }

    // --- The kiosk ----------------------------------------------------------

    public function test_on_hrs_kiosk_the_face_says_who_is_punching(): void
    {
        $employee = $this->enrolledWorker();
        $this->enrolledWorker($this->strangerFace());
        $hr = $this->hr();

        $this->travelTo(Carbon::parse('2026-09-16 07:58'));

        $this->actingAs($hr)->post('/hr/timekeeping/clock/punch', [
            'action' => 'time_in',
            'descriptor' => json_encode($this->face(0.003)),
            'snapshot' => UploadedFile::fake()->image('face.jpg', 320, 240),
        ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "{$employee->full_name} — Timed in at 7:58 AM.");

        $punch = AttendancePunch::firstOrFail();
        $this->assertSame($employee->id, $punch->employee_id);
        $this->assertSame($hr->id, $punch->operator_id);
        $this->assertSame('07:58', AttendanceLog::firstOrFail()->time_in->format('H:i'));
    }

    public function test_the_kiosk_refuses_a_face_nobody_enrolled(): void
    {
        $this->enrolledWorker();

        $this->actingAs($this->hr())->post('/hr/timekeeping/clock/punch', [
            'action' => 'time_in',
            'descriptor' => json_encode($this->strangerFace()),
            'snapshot' => UploadedFile::fake()->image('face.jpg', 320, 240),
        ])->assertSessionHasErrors('face');

        $this->assertSame(0, AttendancePunch::count());
        $this->assertSame(1, AuditLog::where('event', 'clock_face_unrecognised')->count());
    }

    public function test_two_people_too_close_to_call_is_not_a_match(): void
    {
        $this->enrolledWorker();
        $this->enrolledWorker($this->face(0.002));

        $this->actingAs($this->hr())->post('/hr/timekeeping/clock/punch', [
            'action' => 'time_in',
            'descriptor' => json_encode($this->face(0.001)),
            'snapshot' => UploadedFile::fake()->image('face.jpg', 320, 240),
        ])->assertSessionHasErrors(['face' => 'The face is too close to more than one enrolled employee. Face the camera directly, in good light, and try again.']);

        $this->assertSame(0, AttendancePunch::count());
    }

    public function test_a_kiosk_refusal_names_whose_day_it_is(): void
    {
        $employee = $this->enrolledWorker();

        $this->actingAs($this->hr())->post('/hr/timekeeping/clock/punch', [
            'action' => 'time_out',
            'descriptor' => json_encode($this->face(0.002)),
            'snapshot' => UploadedFile::fake()->image('face.jpg', 320, 240),
        ])->assertSessionHasErrors(['action' => "{$employee->full_name}: You have not timed in yet. Press Time in first."]);
    }

    public function test_the_kiosk_lists_every_punch_of_the_day(): void
    {
        $one = $this->enrolledWorker();
        $two = $this->enrolledWorker($this->strangerFace());
        $this->punchAt($one, '2026-09-16 08:00', 'time_in');
        $this->punchAt($two, '2026-09-16 08:05', 'time_in', $this->strangerFace());
        $this->punchAt($one, '2026-09-16 17:00', 'time_out');

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/clock?date=2026-09-16')
            ->assertInertia(fn (Assert $page) => $page
                ->where('mode', 'kiosk')
                ->has('punches', 3)
                ->where('punches.0.action', 'time_out')
                ->where('punches.0.employee.id', $one->id)
                ->where('summary.timed_in', 2)
                ->where('summary.timed_out', 1));
    }

    public function test_an_employee_sees_their_own_last_two_weeks(): void
    {
        $employee = $this->enrolledWorker();
        $this->record($employee, '2026-09-10', '08:00', '17:00');
        $this->record($employee, '2026-08-20', '08:00', '17:00');

        $this->actingAs($employee->user)
            ->get('/hr/timekeeping/clock')
            ->assertInertia(fn (Assert $page) => $page
                ->where('mode', 'personal')
                ->has('history', 1)
                ->where('history.0.date', '2026-09-10'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    // --- Helpers ------------------------------------------------------------

    private function enrolment(?array $face = null): array
    {
        $face ??= $this->face();
        $nudge = fn (float $by) => array_map(fn (float $value) => round($value + $by, 6), $face);

        return [
            'descriptors' => json_encode([$face, $nudge(0.004), $nudge(-0.004)]),
            'snapshot' => UploadedFile::fake()->image('face.jpg', 320, 240),
            'consent' => '1',
        ];
    }

    private function enrolledWorker(?array $face = null): Employee
    {
        $employee = $this->worker();
        $face ??= $this->face();
        $nudge = fn (float $by) => array_map(fn (float $value) => round($value + $by, 6), $face);

        app(TimeClockService::class)->enroll(
            $employee,
            [$face, $nudge(0.004), $nudge(-0.004)],
            null,
            User::factory()->hrStaff()->create(),
        );

        return $employee->fresh();
    }

    private function punchAt(Employee $employee, string $at, string $action, ?array $descriptor = null)
    {
        $this->travelTo(Carbon::parse($at));

        return $this->actingAs($employee->user)->post('/hr/timekeeping/clock/punch', [
            'action' => $action,
            'descriptor' => json_encode($descriptor ?? $this->face(0.002)),
            'snapshot' => UploadedFile::fake()->image('face.jpg', 320, 240),
        ]);
    }

    /** One person's template, nudged slightly to stand in for a new camera frame. */
    private function face(float $nudge = 0.0): array
    {
        return array_map(fn (int $i) => round(sin($i) * 0.1 + $nudge, 6), range(0, 127));
    }

    private function strangerFace(): array
    {
        return array_map(fn (int $i) => round(cos($i * 1.7) * 0.1, 6), range(0, 127));
    }
}
