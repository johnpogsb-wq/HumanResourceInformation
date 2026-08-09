<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Services\EmployeeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function __invoke(Request $request): Response
    {
        $scoped = $this->employees->scopedQuery($request->user());

        return Inertia::render('Dashboard', [
            'statistics' => $this->employees->statistics($scoped),
            'headcountByDepartment' => Department::query()
                ->withCount(['employees' => fn ($query) => $query->where('status', 'active')])
                ->orderByDesc('employees_count')
                ->get(['id', 'name'])
                ->map(fn (Department $department) => [
                    'name' => $department->name,
                    'count' => $department->employees_count,
                ]),
            'recentHires' => (clone $scoped)
                ->whereNotNull('date_hired')
                ->orderByDesc('date_hired')
                ->limit(5)
                ->get()
                ->map(fn ($employee) => [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                    'position' => $employee->position?->title,
                    'date_hired' => $employee->date_hired?->toDateString(),
                ]),
        ]);
    }
}
