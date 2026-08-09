<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\EmployeeService;
use App\Services\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 3 — leave credit balances, one row per employee per year.
 */
class LeaveBalanceController extends Controller
{
    public function __construct(
        private readonly LeaveService $leave,
        private readonly EmployeeService $employees,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', LeaveRequest::class);

        $year = (int) $request->query('year', now()->year);
        $types = LeaveType::where('is_active', true)->orderBy('code')->get();

        $employees = $this->employees->scopedQuery($request->user())
            ->orderBy('last_name')
            ->get(['employees.id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix']);

        $balances = LeaveBalance::whereIn('employee_id', $employees->pluck('id'))
            ->where('year', $year)
            ->get()
            ->groupBy('employee_id');

        return Inertia::render('HR/Leave/Balances', [
            'year' => $year,
            'years' => range(now()->year - 3, now()->year + 1),
            'types' => $types->map(fn (LeaveType $type) => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'is_paid' => $type->is_paid,
            ]),
            'rows' => $employees->map(function (Employee $employee) use ($balances, $types) {
                $owned = $balances->get($employee->id, collect())->keyBy('leave_type_id');

                return [
                    'employee_id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name' => $employee->full_name,
                    'credits' => $types->mapWithKeys(function (LeaveType $type) use ($owned) {
                        $balance = $owned->get($type->id);

                        return [$type->id => [
                            'earned' => (float) ($balance?->credits_earned ?? 0),
                            'used' => (float) ($balance?->credits_used ?? 0),
                            'carried_over' => (float) ($balance?->credits_carried_over ?? 0),
                            'available' => $balance?->available() ?? 0.0,
                        ]];
                    }),
                ];
            })->values(),
            'can' => ['adjust' => $request->user()->can('adjustBalances', LeaveRequest::class)],
        ]);
    }

    /** HR sets the opening credits for a year — the annual leave allocation. */
    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('adjustBalances', LeaveRequest::class);

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'credits_earned' => ['required', 'numeric', 'min:0', 'max:400'],
            'credits_carried_over' => ['required', 'numeric', 'min:0', 'max:400'],
        ]);

        LeaveBalance::updateOrCreate(
            [
                'employee_id' => $validated['employee_id'],
                'leave_type_id' => $validated['leave_type_id'],
                'year' => $validated['year'],
            ],
            [
                'credits_earned' => $validated['credits_earned'],
                'credits_carried_over' => $validated['credits_carried_over'],
            ],
        );

        return back()->with('success', 'Leave credits updated.');
    }

    /**
     * Grants every active employee the default allocation for a year. Existing
     * rows keep their used credits — only the entitlement is (re)set.
     */
    public function allocate(Request $request): RedirectResponse
    {
        Gate::authorize('adjustBalances', LeaveRequest::class);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $types = LeaveType::where('is_active', true)->where('default_credits', '>', 0)->get();
        $employees = Employee::where('status', '!=', 'inactive')->get();
        $granted = 0;

        foreach ($employees as $employee) {
            foreach ($types as $type) {
                LeaveBalance::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                        'year' => $validated['year'],
                    ],
                    ['credits_earned' => $type->default_credits],
                );

                $granted++;
            }
        }

        return back()->with('success', "Allocated {$granted} credit line(s) for {$validated['year']}.");
    }
}
