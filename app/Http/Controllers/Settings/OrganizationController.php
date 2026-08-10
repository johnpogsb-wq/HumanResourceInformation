<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Position;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Departments and positions — the org structure every other module references.
 */
class OrganizationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manageOrganization', Setting::class);

        return Inertia::render('Settings/Organization', [
            'departments' => Department::withCount(['employees', 'positions'])
                ->orderBy('name')
                ->get()
                ->map(fn (Department $department) => [
                    'id' => $department->id,
                    'code' => $department->code,
                    'name' => $department->name,
                    'description' => $department->description,
                    'is_active' => $department->is_active,
                    'employees_count' => $department->employees_count,
                    'positions_count' => $department->positions_count,
                ]),

            'positions' => Position::with('department:id,name')
                ->withCount('employees')
                ->orderBy('title')
                ->get()
                ->map(fn (Position $position) => [
                    'id' => $position->id,
                    'code' => $position->code,
                    'title' => $position->title,
                    'department_id' => $position->department_id,
                    'department' => $position->department?->name,
                    'salary_grade' => $position->salary_grade,
                    'min_salary' => $position->min_salary ? (float) $position->min_salary : null,
                    'max_salary' => $position->max_salary ? (float) $position->max_salary : null,
                    'is_active' => $position->is_active,
                    'employees_count' => $position->employees_count,
                ]),
        ]);
    }

    // --- Departments ------------------------------------------------------

    public function storeDepartment(Request $request): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        Department::create($this->departmentRules($request));

        return back()->with('success', 'Department created.');
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        $department->update($this->departmentRules($request, $department));

        return back()->with('success', 'Department updated.');
    }

    public function destroyDepartment(Department $department): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        // Employees and positions point here; deactivate so history survives.
        if ($department->employees()->exists() || $department->positions()->exists()) {
            $department->update(['is_active' => false]);

            return back()->with('success', 'Department is in use — deactivated instead of deleted.');
        }

        $department->delete();

        return back()->with('success', 'Department deleted.');
    }

    // --- Positions --------------------------------------------------------

    public function storePosition(Request $request): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        Position::create($this->positionRules($request));

        return back()->with('success', 'Position created.');
    }

    public function updatePosition(Request $request, Position $position): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        $position->update($this->positionRules($request, $position));

        return back()->with('success', 'Position updated.');
    }

    public function destroyPosition(Position $position): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        if ($position->employees()->exists()) {
            $position->update(['is_active' => false]);

            return back()->with('success', 'Position is in use — deactivated instead of deleted.');
        }

        $position->delete();

        return back()->with('success', 'Position deleted.');
    }

    /** @return array<string, mixed> */
    private function departmentRules(Request $request, ?Department $department = null): array
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:24',
                Rule::unique('departments', 'code')->ignore($department?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        return [
            ...$validated,
            'code' => strtoupper($validated['code']),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /** @return array<string, mixed> */
    private function positionRules(Request $request, ?Position $position = null): array
    {
        $validated = $request->validate([
            'department_id' => ['required', 'exists:departments,id'],
            'code' => [
                'required', 'string', 'max:24',
                Rule::unique('positions', 'code')->ignore($position?->id),
            ],
            'title' => ['required', 'string', 'max:255'],
            'salary_grade' => ['nullable', 'string', 'max:16'],
            'min_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'max_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'gte:min_salary'],
            'is_active' => ['boolean'],
        ], [
            'max_salary.gte' => 'The maximum salary cannot be below the minimum.',
        ]);

        return [
            ...$validated,
            'code' => strtoupper($validated['code']),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
