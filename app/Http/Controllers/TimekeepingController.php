<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceLogRequest;
use App\Http\Resources\AttendanceLogResource;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\AttendanceImporter;
use App\Services\EmployeeService;
use App\Services\TimekeepingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 2 — Timekeeping & Attendance (Inertia entry point).
 */
class TimekeepingController extends Controller
{
    public function __construct(
        private readonly TimekeepingService $timekeeping,
        private readonly EmployeeService $employees,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AttendanceLog::class);

        // Default to the current month so the screen is never empty on arrival.
        $filters = [
            'from' => $request->query('from', Carbon::now()->startOfMonth()->toDateString()),
            'to' => $request->query('to', Carbon::now()->endOfMonth()->toDateString()),
            'employee_id' => $request->query('employee_id'),
            'department_id' => $request->query('department_id'),
            'status' => $request->query('status'),
            // Set by the summary tiles, which count things no single status
            // names — see AttendanceLog::scopeFilter for what each one means.
            'late' => $request->query('late'),
            'attended' => $request->query('attended'),
        ];

        $query = $this->timekeeping->scopedQuery($request->user())->filter($filters);

        $logs = (clone $query)
            ->orderByDesc('log_date')
            ->orderBy('employee_id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('HR/Timekeeping/Index', [
            'logs' => AttendanceLogResource::collection($logs),
            'summary' => $this->timekeeping->summary($query),
            'filters' => $filters,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'shifts' => Shift::where('is_active', true)->orderBy('start_time')->get(['id', 'name', 'start_time', 'end_time']),
            'employees' => $this->employees->scopedQuery($request->user())
                ->orderBy('last_name')
                ->get(['employees.id', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                ]),
            'statuses' => AttendanceLog::STATUSES,
            'can' => [
                'manage' => $request->user()->can('create', AttendanceLog::class),
            ],
        ]);
    }

    public function store(StoreAttendanceLogRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));

        $log = $this->timekeeping->record($employee, $request->validated());

        return back()->with(
            'success',
            "Time record saved for {$employee->full_name} on {$log->log_date->toFormattedDateString()}.",
        );
    }

    /** Bulk DTR import — the biometric device export lands here. */
    public function import(Request $request, AttendanceImporter $importer): RedirectResponse
    {
        Gate::authorize('create', AttendanceLog::class);

        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
        ], [
            'file.mimes' => 'Upload a CSV export.',
        ]);

        $result = $importer->import($request->file('file'), 'biometric');

        if ($result['imported'] === 0 && $result['failed'] === 0 && $result['errors'] !== []) {
            return back()->with('error', $result['errors'][0]);
        }

        $message = "Imported {$result['imported']} record(s).";

        if ($result['failed'] > 0) {
            $message .= " {$result['failed']} row(s) skipped.";
        }

        return back()
            ->with($result['failed'] > 0 ? 'error' : 'success', $message)
            ->with('importErrors', $result['errors']);
    }

    public function destroy(AttendanceLog $attendanceLog): RedirectResponse
    {
        Gate::authorize('delete', $attendanceLog);

        $attendanceLog->delete();

        return back()->with('success', 'Time record deleted.');
    }
}
