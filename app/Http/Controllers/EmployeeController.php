<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeDocumentRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Position;
use App\Services\DocumentScanner;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 1 — Employee Information Management (Inertia entry point).
 * Mirrors App\Http\Controllers\Api\EmployeeController via EmployeeService.
 */
class EmployeeController extends Controller
{
    private const SORTABLE = [
        'employee_number', 'last_name', 'first_name',
        'date_hired', 'employment_status', 'status',
    ];

    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Employee::class);

        $filters = $request->only(['search', 'department_id', 'employment_status', 'status']);

        $sort = in_array($request->query('sort'), self::SORTABLE, true)
            ? $request->query('sort')
            : 'last_name';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $query = $this->employees->scopedQuery($request->user())->filter($filters);

        $employees = (clone $query)
            ->orderBy($sort, $direction)
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('HR/Employees/Index', [
            // Infinite scroll, not page buttons: on a partial reload (the
            // WhenVisible sentinel asking for the next `page`) the new rows
            // are appended to `data` instead of replacing it. A full visit —
            // first load, or a filter/sort change — ignores this and renders
            // fresh, so switching filters correctly starts back at page 1
            // instead of showing a merged mix of two result sets.
            'employees' => Inertia::merge(EmployeeResource::collection($employees))
                ->append('data', 'id'),
            'statistics' => $this->employees->statistics($this->employees->scopedQuery($request->user())),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'can' => [
                'create' => $request->user()->can('create', Employee::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Employee::class);

        return Inertia::render('HR/Employees/Create', [
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $employee = $this->employees->create(
            $request->validated(),
            $request->file('photo'),
        );

        $message = "Employee {$employee->employee_number} created successfully.";

        if ($this->employees->generatedPassword) {
            $message .= " Temporary password: {$this->employees->generatedPassword}";
        }

        return redirect()
            ->route('hr.employees.show', $employee)
            ->with('success', $message);
    }

    public function show(Request $request, Employee $employee): Response
    {
        Gate::authorize('view', $employee);

        $employee->load([
            'department:id,name',
            'position:id,title,department_id',
            'supervisor:id,first_name,middle_name,last_name,suffix',
            'documents.uploader:id,name',
        ]);

        return Inertia::render('HR/Employees/Show', [
            'employee' => new EmployeeResource($employee),
            'subordinates' => $employee->subordinates()
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'position_id'])
                ->map(fn (Employee $sub) => [
                    'id' => $sub->id,
                    'full_name' => $sub->full_name,
                ]),
            'audits' => $request->user()->can('viewAudits', Employee::class)
                ? $employee->audits()->with('user:id,name')->limit(20)->get()->map(fn ($audit) => [
                    'id' => $audit->id,
                    'event' => $audit->event,
                    'user' => $audit->user?->name ?? 'System',
                    'changes' => array_keys($audit->new_values ?? []),
                    'created_at' => $audit->created_at->toIso8601String(),
                ])
                : [],
            'can' => [
                'update' => $request->user()->can('update', $employee),
                'delete' => $request->user()->can('delete', $employee),
                'manageDocuments' => $request->user()->can('manageDocuments', $employee),
                'viewSensitive' => $request->user()->can('viewSensitive', $employee),
                // Without a configured API key the Scan button is not drawn
                // at all, rather than offered and then failing.
                'scanDocuments' => app(DocumentScanner::class)->isEnabled()
                    && $request->user()->can('manageDocuments', $employee),
            ],
        ]);
    }

    public function edit(Employee $employee): Response
    {
        Gate::authorize('update', $employee);

        return Inertia::render('HR/Employees/Edit', [
            'employee' => new EmployeeResource($employee),
            'options' => $this->formOptions($employee->id),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->employees->update($employee, $request->validated(), $request->file('photo'));

        return redirect()
            ->route('hr.employees.show', $employee)
            ->with('success', 'Employee record updated.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        Gate::authorize('delete', $employee);

        $this->employees->delete($employee);

        return redirect()
            ->route('hr.employees.index')
            ->with('success', "Employee {$employee->employee_number} archived.");
    }

    public function storeDocument(StoreEmployeeDocumentRequest $request, Employee $employee): RedirectResponse
    {
        $this->employees->storeDocument($employee, $request->validated(), $request->file('file'));

        return back()->with('success', 'Document uploaded.');
    }

    /**
     * Reads a scanned document and proposes the fields, without saving
     * anything. Same gate as the upload it precedes — anyone who cannot file
     * a document has no business spending a request reading one.
     *
     * Returns JSON rather than an Inertia response: this fills a form the
     * user is still editing, so re-rendering the page would throw their
     * half-typed input away.
     */
    public function scanDocument(
        Request $request,
        Employee $employee,
        DocumentScanner $scanner,
    ): JsonResponse {
        Gate::authorize('manageDocuments', $employee);

        abort_unless($scanner->isEnabled(), 404);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $result = $scanner->scan($request->file('file'), $employee);

        return response()->json([
            'scanned' => $result !== null,
            'fields' => $result,
        ]);
    }

    /**
     * Streams a 201-file document from the private disk after an authorization
     * check — these are never reachable by direct URL.
     */
    public function downloadDocument(Employee $employee, EmployeeDocument $document): StreamedResponse
    {
        Gate::authorize('view', $employee);

        abort_if($document->employee_id !== $employee->id, 404);

        $disk = Storage::disk(EmployeeService::DOCUMENT_DISK);

        abort_unless($disk->exists($document->file_path), 404);

        return $disk->download($document->file_path, $document->file_name);
    }

    /**
     * The same file, served `inline` so the browser renders it instead of
     * saving it — a separate route rather than a query flag, so the download
     * link keeps forcing a download and neither can change the other by
     * accident. Behind the same `view` gate; these are still private files.
     */
    public function previewDocument(Employee $employee, EmployeeDocument $document): StreamedResponse
    {
        Gate::authorize('view', $employee);

        abort_if($document->employee_id !== $employee->id, 404);

        $disk = Storage::disk(EmployeeService::DOCUMENT_DISK);

        abort_unless($disk->exists($document->file_path), 404);

        return $disk->response($document->file_path, $document->file_name, [
            // Belt and braces: the stored mime type is what the browser is
            // told to render, and nosniff stops it guessing something else
            // out of a file a user uploaded.
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyDocument(Employee $employee, EmployeeDocument $document): RedirectResponse
    {
        Gate::authorize('manageDocuments', $employee);

        abort_if($document->employee_id !== $employee->id, 404);

        $this->employees->deleteDocument($document);

        return back()->with('success', 'Document deleted.');
    }

    /** Dropdown data shared by the create and edit forms. */
    private function formOptions(?int $excludeEmployeeId = null): array
    {
        return [
            'departments' => Department::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'positions' => Position::where('is_active', true)
                ->orderBy('title')
                ->get(['id', 'title', 'department_id']),
            'supervisors' => Employee::query()
                ->where('status', 'active')
                ->when($excludeEmployeeId, fn ($q, $id) => $q->whereKeyNot($id))
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'full_name' => $e->full_name]),
            'employmentStatuses' => Employee::EMPLOYMENT_STATUSES,
            'statuses' => Employee::STATUSES,
            'documentTypes' => EmployeeDocument::TYPES,
        ];
    }
}
