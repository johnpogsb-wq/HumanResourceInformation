<?php

namespace App\Http\Controllers;

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
 * Module 1 master data — positions.
 *
 * A job title inside a department, carrying the salary band Salaries &
 * Adjustments checks a new rate against. The band is advisory there, never
 * enforced — so a position with no band is not an error, but it is worth
 * seeing, because a rate keyed against it has nothing to be compared to.
 */
class PositionController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manageOrganization', Setting::class);

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'department_id' => $request->integer('department_id') ?: null,
        ];

        $positions = Position::with('department:id,name')
            ->withCount('employees')
            ->search($filters['search'])
            ->when(
                $filters['department_id'],
                fn ($query, $value) => $query->where('department_id', $value),
            )
            ->orderBy('title')
            ->get();

        return Inertia::render('HR/MasterData/Positions', [
            'positions' => $positions->map(fn (Position $position) => [
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
            'filters' => $filters,
            'departments' => Department::orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $department) => [
                    'value' => $department->id,
                    'label' => $department->name,
                ]),
            // Whole-table counts, so the summary does not move when filtering.
            'summary' => [
                'total' => Position::count(),
                'active' => Position::where('is_active', true)->count(),
                'without_band' => Position::whereNull('min_salary')
                    ->orWhereNull('max_salary')
                    ->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        Position::create($this->rules($request));

        return back()->with('success', 'Position created.');
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        $position->update($this->rules($request, $position));

        return back()->with('success', 'Position updated.');
    }

    public function destroy(Position $position): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        // Employees point here; deactivate so their history keeps its title.
        if ($position->employees()->exists()) {
            $position->update(['is_active' => false]);

            return back()->with('success', 'Position is in use — deactivated instead of deleted.');
        }

        $position->delete();

        return back()->with('success', 'Position deleted.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?Position $position = null): array
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
