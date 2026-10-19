<?php

namespace App\Http\Controllers\Timekeeping;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Services\DataAccessLogger;
use App\Services\EmployeeService;
use App\Services\FaceMatcher;
use App\Services\TimeClockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Face Enrollment — the face each person punches the time clock with.
 *
 * HR sees everybody and enrolls, re-enrolls and removes; every other role
 * sees only their own record and enrolls it once. The enrolled snapshot sits
 * beside the 201-file photo so whoever looks can see the two are one person.
 */
class FaceEnrollmentController extends Controller
{
    public function __construct(
        private readonly TimeClockService $clock,
        private readonly FaceMatcher $faces,
        private readonly EmployeeService $employees,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! $user->can('enrollFaces', AttendanceLog::class)) {
            return $this->own($request);
        }

        $filters = [
            'search' => $request->query('search'),
            'enrolled' => $request->query('enrolled'),
        ];

        $base = $this->employees->scopedQuery($request->user())->where('status', '!=', 'inactive');

        $employees = (clone $base)
            ->with(['faceEnrollment:id,employee_id,consented_at,enrolled_by,snapshot_path', 'faceEnrollment.enroller:id,name', 'position:id,title'])
            ->when($filters['search'], fn ($query, $search) => $query->search($search))
            ->when($filters['enrolled'] === 'yes', fn ($query) => $query->whereHas('faceEnrollment'))
            ->when($filters['enrolled'] === 'no', fn ($query) => $query->whereDoesntHave('faceEnrollment'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'number' => $employee->employee_number,
                'position' => $employee->position?->title,
                'photo_url' => $employee->photo_path ? asset('storage/'.$employee->photo_path) : null,
                'is_self' => $employee->user_id !== null && $employee->user_id === $request->user()->id,
                'enrollment' => $this->enrollmentRow($employee),
            ]);

        $total = (clone $base)->count();
        $enrolled = (clone $base)->whereHas('faceEnrollment')->count();

        return Inertia::render('HR/Timekeeping/Faces', [
            'mode' => 'manage',
            'employees' => $employees,
            'filters' => $filters,
            'summary' => ['total' => $total, 'enrolled' => $enrolled, 'pending' => $total - $enrolled],
            'samples' => (int) config('timeclock.enroll_samples'),
        ]);
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $user = $request->user();
        $own = $employee->user_id !== null && $employee->user_id === $user->id;

        if ($own && ! $user->can('enrollFaces', AttendanceLog::class)) {
            Gate::authorize('enrollOwnFace', AttendanceLog::class);
        } else {
            Gate::authorize('enrollFaces', AttendanceLog::class);
            Gate::authorize('view', $employee);
        }

        $data = $request->validate([
            'descriptors' => ['required', 'string', 'max:40000'],
            'snapshot' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:1024'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'The employee has to consent before their face is enrolled.',
        ]);

        $descriptors = json_decode($data['descriptors'], true);
        $samples = (int) config('timeclock.enroll_samples');

        if (! is_array($descriptors)
            || count($descriptors) < $samples
            || count($descriptors) > 10
            || collect($descriptors)->contains(fn ($sample) => ! $this->faces->isValidDescriptor($sample))) {
            throw ValidationException::withMessages(['face' => "Take {$samples} clear readings of the face and try again."]);
        }

        $this->clock->enroll($employee, array_values($descriptors), $request->file('snapshot'), $user);

        return back()->with('success', $own
            ? 'Your face is enrolled. You can use the time clock now.'
            : "{$employee->full_name}'s face is enrolled. They can use the time clock now.");
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        Gate::authorize('enrollFaces', AttendanceLog::class);

        $enrollment = FaceEnrollment::where('employee_id', $employee->id)->firstOrFail();
        $this->clock->removeEnrollment($enrollment);

        return back()->with('success', "{$employee->full_name}'s face was removed. They cannot use the time clock until enrolled again.");
    }

    public function photo(Request $request, Employee $employee, DataAccessLogger $access): StreamedResponse
    {
        $own = $employee->user_id !== null && $employee->user_id === $request->user()->id;

        if (! $own) {
            Gate::authorize('enrollFaces', AttendanceLog::class);
        }

        $enrollment = FaceEnrollment::where('employee_id', $employee->id)->firstOrFail();
        $disk = Storage::disk(config('timeclock.snapshot_disk'));
        abort_unless($enrollment->snapshot_path && $disk->exists($enrollment->snapshot_path), 404);

        // One's own face is not a finding; logging it would bury the reads that are.
        if (! $own) {
            $access->accessed($enrollment, 'enrollment_photo', ['employee' => $employee->full_name]);
        }

        return $disk->response($enrollment->snapshot_path, "face-{$employee->id}.jpg", [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Every role but HR: their own record, and the one enrollment they may make. */
    private function own(Request $request): Response
    {
        $user = $request->user();
        $employee = $user->employee?->load('faceEnrollment.enroller:id,name', 'position:id,title');

        return Inertia::render('HR/Timekeeping/Faces', [
            'mode' => 'self',
            'self' => $employee ? [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'number' => $employee->employee_number,
                'position' => $employee->position?->title,
                'photo_url' => $employee->photo_path ? asset('storage/'.$employee->photo_path) : null,
                'is_self' => true,
                'enrollment' => $this->enrollmentRow($employee),
            ] : null,
            'canEnroll' => $user->can('enrollOwnFace', AttendanceLog::class) && ! $employee?->faceEnrollment,
            'samples' => (int) config('timeclock.enroll_samples'),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function enrollmentRow(Employee $employee): ?array
    {
        $enrollment = $employee->faceEnrollment;

        return $enrollment ? [
            'enrolled_at' => $enrollment->consented_at?->toIso8601String(),
            'enrolled_by' => $enrollment->enroller?->name,
            'self_enrolled' => $employee->user_id !== null && $enrollment->enrolled_by === $employee->user_id,
            'photo_url' => $enrollment->snapshot_path
                ? route('hr.timekeeping.faces.photo', $employee)
                : null,
        ] : null;
    }
}
