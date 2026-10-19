<?php

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Position;
use App\Models\User;
use App\Services\OnboardingChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The checker has no screen of its own any more; it still feeds Deployment
 * Readiness and the integration API, so its rules are tested directly.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    /** Everything config asks for, so nothing should be flagged. */
    private const COMPLETE = ['contract', 'government_id', 'clearance', 'medical', 'resume'];

    public function test_a_complete_file_is_not_listed(): void
    {
        $employee = $this->employee();

        foreach (self::COMPLETE as $type) {
            $this->document($employee, $type);
        }

        $this->assertCount(0, $this->scan());
    }

    public function test_a_missing_document_is_reported(): void
    {
        $employee = $this->employee();

        foreach (['government_id', 'clearance', 'medical', 'resume'] as $type) {
            $this->document($employee, $type);
        }

        $rows = $this->scan();

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['missing_count']);
        $this->assertSame('contract', $rows[0]['missing'][0]['key']);
        $this->assertTrue($rows[0]['missing'][0]['blocking']);
    }

    public function test_a_missing_resume_does_not_stop_deployment(): void
    {
        $employee = $this->employee();

        foreach (['contract', 'government_id', 'clearance', 'medical'] as $type) {
            $this->document($employee, $type);
        }

        $row = $this->scan()[0];

        $this->assertSame('resume', $row['missing'][0]['key']);
        $this->assertFalse($row['missing'][0]['blocking']);
        $this->assertSame(0, $row['blocking']);
    }

    /** A driver needs a licence; a dispatcher does not. */
    public function test_a_driver_additionally_needs_a_licence(): void
    {
        $driver = $this->employee(position: 'Fleet Driver');

        foreach (self::COMPLETE as $type) {
            $this->document($driver, $type);
        }

        $rows = $this->scan();

        $this->assertCount(1, $rows);
        $this->assertSame('drivers_license', $rows[0]['missing'][0]['key']);
        $this->assertTrue($rows[0]['missing'][0]['blocking']);
    }

    public function test_a_non_driver_is_not_asked_for_a_licence(): void
    {
        $dispatcher = $this->employee(position: 'Dispatcher');

        foreach (self::COMPLETE as $type) {
            $this->document($dispatcher, $type);
        }

        $this->assertCount(0, $this->scan());
    }

    public function test_a_missing_government_number_is_reported(): void
    {
        $employee = $this->employee(['tin' => null]);

        foreach (self::COMPLETE as $type) {
            $this->document($employee, $type);
        }

        $missing = $this->scan()[0]['missing'][0];

        $this->assertSame('government_number', $missing['kind']);
        $this->assertSame('tin', $missing['key']);
        // It doesn't stop the person working — it stops the filing.
        $this->assertFalse($missing['blocking']);
    }

    public function test_blocking_gaps_sort_above_the_rest(): void
    {
        $tidy = $this->employee();
        foreach (['contract', 'government_id', 'clearance', 'medical'] as $type) {
            $this->document($tidy, $type); // only a résumé missing
        }

        $blocked = $this->employee();
        foreach (['government_id', 'clearance', 'medical', 'resume'] as $type) {
            $this->document($blocked, $type); // no contract
        }

        $rows = $this->scan();

        $this->assertSame($blocked->id, $rows[0]['employee_id']);
        $this->assertSame($tidy->id, $rows[1]['employee_id']);
    }

    public function test_the_screen_is_gone(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/onboarding')
            ->assertNotFound();
    }

    private function scan(): Collection
    {
        return app(OnboardingChecker::class)->scan(Employee::query());
    }

    private function employee(array $attributes = [], string $position = 'Dispatcher'): Employee
    {
        $department = Department::firstOrCreate(
            ['code' => 'OPS'],
            ['name' => 'Operations'],
        );

        return Employee::factory()->create([
            'position_id' => Position::create([
                'title' => $position,
                'code' => strtoupper(substr(md5($position.uniqid()), 0, 8)),
                'department_id' => $department->id,
            ])->id,
            'department_id' => $department->id,
            'sss_number' => '34-1234567-8',
            'philhealth_number' => '12-345678901-2',
            'pagibig_number' => '1234-5678-9012',
            'tin' => '123-456-789-000',
            ...$attributes,
        ]);
    }

    private function document(Employee $employee, string $type): EmployeeDocument
    {
        return EmployeeDocument::create([
            'employee_id' => $employee->id,
            'type' => $type,
            'title' => ucfirst(str_replace('_', ' ', $type)),
            'file_path' => "documents/{$type}.pdf",
            'file_name' => "{$type}.pdf",
        ]);
    }
}
