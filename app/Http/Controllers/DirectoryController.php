<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The org directory — who works here, arranged the way the company is.
 *
 * A different screen from `/hr/employees`, for a different question and a
 * different audience. The HR directory is a *record*: it carries salary,
 * government numbers, the 201 file, and it narrows by role because of them.
 * This one answers "who is in Operations, and how do I reach them" — which
 * everybody has, and which the HR directory refuses to answer for anybody but
 * HR.
 *
 * **The widening and the narrowing are one decision.** Every signed-in user
 * may open this (see `EmployeePolicy::viewDirectory`) *because* the fields
 * stop at a name, a position, a department, a client, and a work contact.
 * Widening the audience without narrowing the fields would be a leak;
 * narrowing the fields without widening the audience would leave a directory
 * nobody can read.
 *
 * Grouped department → position → person, because that is the shape of the
 * question. Somebody looking for "a driver at Metro Fleet" is not searching a
 * flat list of 41 names; they are walking down the org chart.
 */
class DirectoryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewDirectory', Employee::class);

        $search = trim((string) $request->query('search'));

        /*
         * Not `EmployeeService::scopedQuery()`, and that is the point.
         *
         * The service narrows by role because it feeds screens carrying
         * sensitive fields — an employee sees only themselves. Applying it
         * here would give every non-HR user a directory of one person, which
         * is not a directory. The protection on this screen is the field list
         * below, not the row list.
         *
         * Separated and inactive staff are excluded: a directory is for
         * reaching people who are here, and somebody who has left is a record
         * rather than a colleague.
         */
        $employees = Employee::query()
            ->where('status', 'active')
            ->with(['position:id,title,department_id', 'client:id,name'])
            ->when($search, fn ($query, $term) => $query->search($term))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $departments = Department::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return Inertia::render('HR/Directory', [
            /*
             * Grouped here rather than in the component. The shape is the
             * answer — department, then position, then people — and building
             * it server-side keeps the page from re-deriving it on every
             * keystroke of the search box.
             */
            'departments' => $departments
                ->map(fn (Department $department) => [
                    'id' => $department->id,
                    'code' => $department->code,
                    'name' => $department->name,
                    'positions' => $this->positionsIn($employees, $department->id),
                    'headcount' => $employees->where('department_id', $department->id)->count(),
                ])
                ->filter(fn (array $row) => $row['headcount'] > 0 || ! $search)
                ->values(),

            /*
             * Everybody the org chart has not caught. A new hire filed before
             * their department was decided is still somebody a colleague may
             * need to reach, and dropping them would make the directory quietly
             * wrong rather than visibly incomplete.
             */
            'unassigned' => [
                'positions' => $this->positionsIn($employees, null),
                'headcount' => $employees->whereNull('department_id')->count(),
            ],

            'filters' => ['search' => $search],

            'total' => $employees->count(),
        ]);
    }

    /**
     * The people in one department, grouped by the job they hold.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<string, mixed>>
     */
    private function positionsIn($employees, ?int $departmentId): array
    {
        return $employees
            ->where('department_id', $departmentId)
            ->groupBy(fn (Employee $employee) => $employee->position?->title ?? 'No position on file')
            ->map(fn ($people, string $title) => [
                'title' => $title,
                'people' => $people->map(fn (Employee $employee) => $this->card($employee))->values(),
            ])
            ->sortBy('title')
            ->values()
            ->all();
    }

    /**
     * One person, as a colleague needs to see them.
     *
     * The whole safety of this screen is this method. Everything here is
     * work-facing — what somebody would read off a desk nameplate or an email
     * signature. Deliberately absent: `basic_salary`, `sss_number`,
     * `philhealth_number`, `pagibig_number`, `tin`, `birth_date`,
     * `present_address`, `bank_account_number`, and every 201-file document.
     *
     * Do not add a field here without asking whether the whole company should
     * have it. That is the question this list exists to keep asking.
     *
     * @return array<string, mixed>
     */
    private function card(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
            // Photos are on the public disk so an avatar does not cost a PHP
            // request per row — the same URL the HR directory renders.
            'photo_url' => $employee->photo_path ? asset('storage/'.$employee->photo_path) : null,

            // Where they sit in the agency: internal staff, or deployed and
            // to whom. For a manpower agency that is half of "who are you".
            'employment_category' => $employee->employment_category,
            'client' => $employee->client?->name,

            /*
             * A work contact, because a directory that cannot be used to
             * reach anybody is a list. Both are the addresses HR files for
             * work correspondence — the same ones on a payslip header.
             */
            'email' => $employee->email,
            'mobile_number' => $employee->mobile_number,
        ];
    }
}
