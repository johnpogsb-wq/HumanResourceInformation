<?php

namespace App\Http\Controllers\Timekeeping;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Services\AttendanceCalendar;
use App\Services\AttendanceMailer;
use App\Services\EmployeeService;
use App\Services\TimekeepingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance Calendar — one employee's month, a day at a time, and the button
 * that sends that month to their inbox.
 *
 * Reading is scoped like Daily Time Records (HR everyone, a supervisor the
 * team, an employee themselves). Sending is HR's: it is personal data leaving
 * the system, and the people who keep the time records are the ones who
 * answer for what was sent.
 */
class AttendanceCalendarController extends Controller
{
    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly AttendanceMailer $mailer,
        private readonly EmployeeService $employees,
        private readonly TimekeepingService $timekeeping,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AttendanceLog::class);

        $user = $request->user();
        $options = $this->timekeeping->employeeOptions($user);
        $month = $this->month($request->input('month'));

        // Somebody asked for by id must be inside the viewer's scope; with
        // nobody asked for, open on the viewer's own record, else the first.
        $id = $request->integer('employee')
            ?: ($user->employee?->id && collect($options)->contains('value', $user->employee->id) ? $user->employee->id : null)
            ?: ($options[0]['value'] ?? null);

        $employee = $id ? $this->scoped($request, $id) : null;
        $canEmail = $user->can('manage', AttendanceLog::class);
        $recipient = $employee && $canEmail ? $this->mailer->recipientFor($employee) : null;

        return Inertia::render('HR/Timekeeping/Calendar', [
            'employees' => $options,
            'employee' => $employee ? [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'number' => $employee->employee_number,
                'position' => $employee->position?->title,
                'department' => $employee->department?->name,
                'client' => $employee->client?->name,
            ] : null,
            'calendar' => $employee ? $this->calendar->month($employee, $month) : null,
            'month' => $month->format('Y-m'),
            'today' => now()->toDateString(),
            'email' => $canEmail ? [
                'recipient' => $recipient,
                'deliverable' => $recipient !== null && $this->mailer->isDeliverable($recipient),
            ] : null,
            'can' => [
                'email' => $canEmail,
                'fileCorrection' => $user->employee !== null,
            ],
        ]);
    }

    /** Sends one employee's month to their inbox. */
    public function email(Request $request): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $employee = $this->scoped($request, (int) $data['employee_id']);
        $month = $this->month($data['month']);
        $label = $month->format('F Y');

        return match ($this->mailer->send($employee, $month)) {
            AttendanceMailer::SENT => back()->with('success', "Sent {$employee->full_name}'s {$label} attendance to {$this->mailer->recipientFor($employee)}."),
            AttendanceMailer::NO_ADDRESS => back()->with('error', "{$employee->full_name} has no email on their 201 file — add one on their record first."),
            AttendanceMailer::UNDELIVERABLE => back()->with('error', "{$employee->full_name}'s email ({$this->mailer->recipientFor($employee)}) is a placeholder address that cannot receive mail. Put their real email on their record first."),
            default => back()->with('error', 'The email could not be sent. Check the mail settings (MAIL_* in the server configuration) and try again.'),
        };
    }

    /**
     * Sends the month to everybody in scope who has a time record in it.
     *
     * Synchronous, like every mail here — there is no queue worker on the
     * deployment. The mailer keeps one SMTP connection open across the loop,
     * so forty employees is seconds rather than a minute, and the result is
     * reported in full rather than promised.
     */
    public function emailAll(Request $request): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $month = $this->month($data['month']);
        $label = $month->format('F Y');

        // Somebody with no record in the month is not sent an empty calendar.
        $employees = $this->employees->scopedQuery($request->user())
            ->where('status', '!=', 'inactive')
            ->whereHas('attendanceLogs', fn ($query) => $query->between(
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ))
            ->with('user:id,otp_email', 'position:id,title', 'department:id,name', 'client:id,name')
            ->orderBy('last_name')
            ->get();

        if ($employees->isEmpty()) {
            return back()->with('error', "Nobody has a time record in {$label}, so there is nothing to send.");
        }

        set_time_limit(max(120, $employees->count() * 5));

        $results = $employees->groupBy(fn (Employee $employee) => $this->mailer->send($employee, $month));
        $sent = $results->get(AttendanceMailer::SENT, collect())->count();
        $unreachable = $results->get(AttendanceMailer::NO_ADDRESS, collect())
            ->merge($results->get(AttendanceMailer::UNDELIVERABLE, collect()));
        $failed = $results->get(AttendanceMailer::FAILED, collect());

        $flash = back()->with('success', "Sent {$label} attendance to {$sent} of {$employees->count()} employee(s).");

        // The ones that did not go are the ones somebody has to act on, so name them.
        $problems = array_filter([
            $unreachable->isNotEmpty() ? $unreachable->count().' skipped with no real email on file ('.$this->names($unreachable).').' : null,
            $failed->isNotEmpty() ? $failed->count().' failed to send ('.$this->names($failed).') — check the mail settings.' : null,
        ]);

        return $problems ? $flash->with('error', implode(' ', $problems)) : $flash;
    }

    /** An employee this viewer may see, or a 403 — never a stranger's record by id. */
    private function scoped(Request $request, int $id): Employee
    {
        $employee = $this->employees->scopedQuery($request->user())
            ->with('user:id,otp_email', 'position:id,title', 'department:id,name', 'client:id,name')
            ->find($id);

        abort_if($employee === null, 403);

        return $employee;
    }

    private function month(mixed $value): Carbon
    {
        try {
            return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)
                ? Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfDay()
                : now()->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    private function names($employees): string
    {
        $names = $employees->take(5)->map(fn (Employee $employee) => $employee->full_name)->implode(', ');

        return $employees->count() > 5 ? $names.' and '.($employees->count() - 5).' more' : $names;
    }
}
