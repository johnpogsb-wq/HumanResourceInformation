<?php

namespace App\Http\Controllers\Timekeeping;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Models\Setting;
use App\Services\DataAccessLogger;
use App\Services\FaceMatcher;
use App\Services\TimeClockService;
use App\Services\WorkCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Time Clock — the employee's own Time in, Start break, End break, Time out,
 * each one verified against their enrolled face.
 */
class TimeClockController extends Controller
{
    private const ACTION_LABELS = [
        AttendancePunch::ACTION_TIME_IN => 'Timed in',
        AttendancePunch::ACTION_BREAK_START => 'Break started',
        AttendancePunch::ACTION_BREAK_END => 'Break ended',
        AttendancePunch::ACTION_TIME_OUT => 'Timed out',
    ];

    public function __construct(
        private readonly TimeClockService $clock,
        private readonly FaceMatcher $faces,
        private readonly WorkCalendar $calendar,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        // HR's screen is the kiosk: the face says who is punching, and the
        // day's punches are listed for everybody.
        if ($user->can('kiosk', AttendanceLog::class)) {
            return $this->kiosk($request);
        }

        $employee = $user->employee;
        $mayPunch = $user->can('punch', AttendanceLog::class);

        $state = $employee && $mayPunch ? $this->clock->state($employee) : null;
        $log = $state['log'] ?? null;
        $shift = $employee ? $this->calendar->scheduleFor($employee, now())?->shift : null;

        return Inertia::render('HR/Timekeeping/Clock', [
            'mode' => 'personal',
            'history' => $employee && $mayPunch ? $this->history($employee) : [],
            'employee' => $employee ? [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'number' => $employee->employee_number,
                'photo_url' => $employee->photo_path ? asset('storage/'.$employee->photo_path) : null,
            ] : null,
            'enrolled' => $employee?->faceEnrollment()->exists() ?? false,
            'state' => $state ? [
                'state' => $state['state'],
                'next' => $state['next'],
                'time_in' => $log?->time_in?->toIso8601String(),
                'break_start' => $log?->break_start?->toIso8601String(),
                'break_end' => $log?->break_end?->toIso8601String(),
                'time_out' => $log?->time_out?->toIso8601String(),
                'status' => $log?->status,
                'minutes_worked' => $log?->minutes_worked,
                'late_minutes' => $log?->late_minutes,
                'undertime_minutes' => $log?->undertime_minutes,
                'overtime_minutes' => $log?->overtime_minutes,
            ] : null,
            'shift' => $shift ? [
                'name' => $shift->name,
                'start' => substr((string) $shift->start_time, 0, 5),
                'end' => substr((string) $shift->end_time, 0, 5),
                'break_minutes' => (int) $shift->break_minutes,
            ] : null,
            'punches' => $log ? $log->punches()->get()->map(fn (AttendancePunch $punch) => [
                'id' => $punch->id,
                'action' => $punch->action,
                'label' => self::ACTION_LABELS[$punch->action] ?? $punch->action,
                'at' => $punch->punched_at->toIso8601String(),
                'photo_url' => $punch->snapshot_path ? route('hr.timekeeping.punches.photo', $punch) : null,
            ])->values() : [],
            'serverTime' => now()->toIso8601String(),
            'requireBlink' => (bool) config('timeclock.require_blink'),
            'can' => [
                'punch' => $mayPunch,
                'enroll' => $user->can('enrollFaces', AttendanceLog::class),
            ],
        ]);
    }

    public function punch(Request $request): RedirectResponse
    {
        $kiosk = $request->user()->can('kiosk', AttendanceLog::class);

        if (! $kiosk) {
            Gate::authorize('punch', AttendanceLog::class);
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(AttendancePunch::ACTIONS)],
            'descriptor' => ['required', 'string', 'max:8000'],
            'snapshot' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:1024'],
        ]);

        $descriptor = json_decode($data['descriptor'], true);

        if (! $this->faces->isValidDescriptor($descriptor)) {
            throw ValidationException::withMessages(['face' => 'The camera reading could not be used. Try again.']);
        }

        $punch = $kiosk
            ? $this->clock->punchAtKiosk($data['action'], $descriptor, $request->file('snapshot'), $request)
            : $this->clock->punch($request->user()->employee, $data['action'], $descriptor, $request->file('snapshot'), $request);

        $said = (self::ACTION_LABELS[$punch->action] ?? 'Punched').' at '.$punch->punched_at->format('g:i A').'.';

        // On a kiosk the name is the confirmation: it says the face was read
        // as the right person before they walk away.
        return back()->with('success', $kiosk ? "{$punch->employee->full_name} — {$said}" : $said);
    }

    public function photo(Request $request, AttendancePunch $punch, DataAccessLogger $access): StreamedResponse
    {
        // Asked of the attendance policy: a punch belongs to the day it was taken on.
        Gate::authorize('viewPunch', [AttendanceLog::class, $punch]);

        $disk = Storage::disk(config('timeclock.snapshot_disk'));
        abort_unless($punch->snapshot_path && $disk->exists($punch->snapshot_path), 404);

        // Somebody else's face is biometric data; looking at your own is not
        // a finding, and logging it would bury the reads that are.
        if ($punch->employee?->user_id !== $request->user()->id) {
            $access->accessed($punch, 'punch_photo', ['employee' => $punch->employee?->full_name]);
        }

        return $disk->response($punch->snapshot_path, "punch-{$punch->id}.jpg", [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The shared kiosk: every punch on one day, newest first. */
    private function kiosk(Request $request): Response
    {
        try {
            $date = Carbon::parse((string) $request->query('date', today()->toDateString()))->startOfDay();
        } catch (\Throwable) {
            $date = today();
        }

        $punches = AttendancePunch::query()
            ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix', 'log:id,status'])
            ->whereDate('punched_at', $date->toDateString())
            ->latest('punched_at')
            ->limit(500)
            ->get();

        $logs = AttendanceLog::query()
            ->whereDate('work_date', $date->toDateString())
            ->whereNotNull('time_in')
            ->get(['id', 'time_out', 'break_start', 'break_end']);

        return Inertia::render('HR/Timekeeping/Clock', [
            'mode' => 'kiosk',
            'date' => $date->toDateString(),
            'today' => today()->toDateString(),
            'punches' => $punches->map(fn (AttendancePunch $punch) => [
                'id' => $punch->id,
                'action' => $punch->action,
                'label' => self::ACTION_LABELS[$punch->action] ?? $punch->action,
                'at' => $punch->punched_at->toIso8601String(),
                'employee' => [
                    'id' => $punch->employee?->id,
                    'name' => $punch->employee?->full_name,
                    'number' => $punch->employee?->employee_number,
                ],
                'status' => $punch->log?->status,
                'photo_url' => $punch->snapshot_path ? route('hr.timekeeping.punches.photo', $punch) : null,
            ])->values(),
            'summary' => [
                'timed_in' => $logs->count(),
                'on_break' => $logs->filter(fn ($log) => $log->break_start && ! $log->break_end && ! $log->time_out)->count(),
                'timed_out' => $logs->whereNotNull('time_out')->count(),
                'enrolled' => FaceEnrollment::count(),
            ],
            // Whether the person at the kiosk could clock *themselves* in:
            // an administrator's login often has no 201 file at all.
            'operator' => [
                'has_file' => $request->user()->employee !== null,
                'enrolled' => $request->user()->employee?->faceEnrollment()->exists() ?? false,
            ],
            'serverTime' => now()->toIso8601String(),
            'requireBlink' => (bool) config('timeclock.require_blink'),
            'can' => [
                'punch' => true,
                'linkAccounts' => $request->user()->can('manageAccountRequests', Setting::class),
                'enroll' => $request->user()->can('enrollFaces', AttendanceLog::class),
            ],
        ]);
    }

    /**
     * The employee's own last fourteen days, from the DTR rather than the
     * punch rows — a day HR keyed or a correction changed is still their day.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(Employee $employee): array
    {
        return AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', today()->subDays(13)->toDateString())
            ->orderByDesc('work_date')
            ->get()
            ->map(fn (AttendanceLog $log) => [
                'date' => $log->work_date->toDateString(),
                'time_in' => $log->time_in?->toIso8601String(),
                'break_start' => $log->break_start?->toIso8601String(),
                'break_end' => $log->break_end?->toIso8601String(),
                'time_out' => $log->time_out?->toIso8601String(),
                'status' => $log->status,
                'minutes_worked' => $log->minutes_worked,
                'late_minutes' => $log->late_minutes,
                'source' => $log->source,
            ])
            ->all();
    }
}
