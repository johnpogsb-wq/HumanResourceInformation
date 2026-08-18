<?php

namespace App\Http\Middleware;

use App\Models\EmployeeDocument;
use App\Models\Setting;
use App\Services\CredentialExpiryScanner;
use App\Services\EmployeeService;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only([
                    'id', 'name', 'email', 'role', 'is_active',
                ]),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // Per-row failures from a bulk import, surfaced on the page that
            // triggered it rather than squeezed into a toast.
            'importErrors' => fn () => $request->session()->get('importErrors', []),
            // Brand text, so the logo and payslip header follow whatever
            // Settings > General holds rather than a hardcoded string.
            'brand' => fn () => [
                'name' => Setting::get('company.name'),
                'tagline' => Setting::get('company.tagline'),
            ],
            // Leave requests waiting on *this* user, for the topbar badge.
            // Lazily evaluated, so guests and API calls never run the query.
            'pendingApprovals' => fn () => $request->user()
                ? app(LeaveService::class)->pendingApprovalsFor($request->user())
                : 0,
            // Lapsed or soon-to-lapse 201 documents, scoped to what this user
            // may see — so an employee's own licence warns them directly.
            // Lazy for the same reason as the badge above.
            'expiringCredentials' => fn () => $request->user()
                ? app(CredentialExpiryScanner::class)->countFor(
                    EmployeeDocument::query()->whereIn(
                        'employee_id',
                        app(EmployeeService::class)
                            ->scopedQuery($request->user())
                            ->select('employees.id'),
                    ),
                )
                : 0,
        ];
    }
}
