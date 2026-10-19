<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\EmployeeService;
use App\Services\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 3 — Leave & Absence.
 * Filing, the two-step approval workflow, and the attachment download.
 */
class LeaveController extends Controller
{
    /** The tabs this one screen holds, in the order a leave is handled. */
    public const TABS = ['requests', 'calendar', 'balances', 'types'];

    /** Medical certificates and the like are never web-served directly. */
    private const ATTACHMENT_DISK = 'local';

    public function __construct(
        private readonly LeaveService $leave,
        private readonly EmployeeService $employees,
    ) {}

    /**
     * The Leave Requests tab's props.
     *
     * Public and split out from the action so the merged module screen can
     * build the same tab without a second copy of this query. The action below
     * is now only a redirect; this is where the work lives.
     */
    public function requestsProps(Request $request): array
    {

        // `awaiting` and `filed_from` are set by summary tiles rather than by
        // any control on this screen — the first matches the two statuses the
        // tile counts, the second the month the dashboard's Leave card counts.
        // See LeaveRequest::scopeFilter, and the chips beside the filter row.
        $filters = $request->only([
            'status', 'leave_type_id', 'employee_id', 'from', 'to',
            'awaiting', 'filed_from',
        ]);

        $query = $this->leave->scopedQuery($request->user())->filter($filters);

        $requests = (clone $query)
            // Anything still awaiting a decision floats to the top.
            ->orderByRaw("case when status in ('pending','supervisor_approved') then 0 else 1 end")
            ->orderByDesc('start_date')
            ->paginate(15)
            ->withQueryString();

        return [
            'requests' => LeaveRequestResource::collection($requests),
            'summary' => $this->leave->summary($query),
            'filters' => $filters,
            'statuses' => LeaveRequest::STATUSES,
            'leaveTypes' => LeaveType::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'is_paid', 'requires_attachment', 'min_days_notice']),
            'employees' => $this->employeeOptions($request),
            'myBalances' => $this->balancesForOwnEmployee($request),
            'can' => [
                'create' => $request->user()->can('create', LeaveRequest::class),
                // Deciding is HR's alone now — the supervisor step is gone,
                // and so is filing on somebody else's behalf.
                'decide' => $request->user()->isHrAdmin(),
            ],
            'ownEmployeeId' => $request->user()->employee?->id,
        ];
    }

    /**
     * Module 3 as **one screen**, not four.
     *
     * Filing, the calendar, the credits and the types used to be four separate
     * pages reached from a sidebar dropdown, so moving between them was a
     * navigation and the reader lost their place each time. They are four tabs
     * of one screen now: this action builds all four sets of props, the page
     * keeps the active tab in React state, and **switching a tab costs no
     * request at all**.
     *
     * **The props are still built by the controller that owns each one** —
     * `calendarProps()`, `balancesProps()`, `typesProps()` — resolved as method
     * arguments rather than copied here. A fifth copy of "what the credits
     * screen shows" is exactly the drift this codebase keeps refusing
     * elsewhere: the day one of them changed, the tab and the old screen would
     * disagree and nothing would say which was right.
     *
     * **One gate for the whole module.** All four screens asked
     * `viewAny` on `LeaveRequest` and nothing else, so asking it once here is
     * the same rule rather than a relaxation of it. Anything narrower is on the
     * tab's own `can` flags, which each builder still sets — `adjust` on the
     * credits, `manage` on the types.
     *
     * The cost, stated: a visit builds all four tabs even though one is drawn.
     * That is four small queries on a roster of this size, and it is what buys
     * the instant tab switch. If a module ever outgrows it, the answer is
     * Inertia's deferred props for the tabs below the first — not a return to
     * four pages.
     */
    public function index(
        Request $request,
        LeaveCalendarController $calendar,
        LeaveBalanceController $balances,
        LeaveTypeController $types,
    ): Response {
        Gate::authorize('viewAny', LeaveRequest::class);

        return Inertia::render('HR/Leave/Hub', [
            // Which tab opens. A sidebar child or an old bookmarked link
            // arrives with `?tab=`; anything else opens on filing, which is
            // what most of the workforce comes here to do.
            'tab' => in_array($request->query('tab'), self::TABS, true)
                ? $request->query('tab')
                : 'requests',

            'requestsTab' => $this->requestsProps($request),
            'calendarTab' => $calendar->calendarProps($request),
            'balancesTab' => $balances->balancesProps($request),
            'typesTab' => $types->typesProps($request),
        ]);
    }

    public function store(StoreLeaveRequestRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));
        $type = LeaveType::findOrFail($request->validated('leave_type_id'));

        $data = $request->validated();

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')
                ->store("leave-attachments/{$employee->id}", self::ATTACHMENT_DISK);
        }

        $leaveRequest = $this->leave->file($employee, $type, $data);

        return back()->with(
            'success',
            "Leave {$leaveRequest->reference_number} filed for {$leaveRequest->days_requested} day(s).",
        );
    }

    /** Supervisor endorsement, then HR confirmation — decided by current status. */
    public function approve(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        Gate::authorize('decide', $leaveRequest);

        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        /*
         * Credits are checked again at approval, not only at filing. A balance
         * can shrink in between — corrected by HR, or spent by another request
         * approved first — and approving anyway would push it negative.
         */
        $shortfall = $this->leave->creditShortfall($leaveRequest);

        if ($shortfall !== null) {
            return back()->with('error', $shortfall);
        }

        $this->leave->approve($leaveRequest, $request->user(), $validated['remarks'] ?? null);

        return back()->with('success', 'Leave approved and credits deducted.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        Gate::authorize('reject', $leaveRequest);

        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ], [
            'remarks.required' => 'Give a reason for the rejection.',
        ]);

        $this->leave->reject($leaveRequest, $request->user(), $validated['remarks']);

        return back()->with('success', 'Leave request rejected.');
    }

    public function cancel(LeaveRequest $leaveRequest): RedirectResponse
    {
        Gate::authorize('cancel', $leaveRequest);

        $this->leave->cancel($leaveRequest);

        return back()->with('success', 'Leave request cancelled.');
    }

    public function attachment(LeaveRequest $leaveRequest): StreamedResponse
    {
        Gate::authorize('view', $leaveRequest);

        abort_if(blank($leaveRequest->attachment_path), 404);

        $disk = Storage::disk(self::ATTACHMENT_DISK);

        abort_unless($disk->exists($leaveRequest->attachment_path), 404);

        return $disk->download(
            $leaveRequest->attachment_path,
            "{$leaveRequest->reference_number}-attachment.".pathinfo($leaveRequest->attachment_path, PATHINFO_EXTENSION),
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    private function employeeOptions(Request $request)
    {
        return $this->employees->scopedQuery($request->user())
            ->orderBy('last_name')
            ->get(['employees.id', 'first_name', 'middle_name', 'last_name', 'suffix'])
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
            ]);
    }

    /** The filing form shows the requester what they have left. */
    private function balancesForOwnEmployee(Request $request): array
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return [];
        }

        return LeaveType::where('is_active', true)
            ->get()
            ->map(fn (LeaveType $type) => [
                'leave_type_id' => $type->id,
                'code' => $type->code,
                'available' => $this->leave->availableCredits($employee, $type),
            ])
            ->all();
    }
}
