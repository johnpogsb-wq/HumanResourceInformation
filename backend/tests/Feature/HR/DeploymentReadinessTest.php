<?php

namespace Tests\Feature\HR;

use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\DeploymentReadinessChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Can this person be sent to a client tomorrow?" — the one question no single
 * module answers. These cover the composition: that the checker reads the
 * credential and 201-file rules rather than inventing its own, and that
 * *blocking* means unlawful to dispatch rather than merely untidy.
 */
class DeploymentReadinessTest extends TestCase
{
    use RefreshDatabase;

    // --- What blocks ------------------------------------------------------

    /**
     * The case the whole feature exists for: a driver whose licence has lapsed
     * may not lawfully drive, and dispatching them is the company's liability.
     */
    public function test_a_lapsed_licence_blocks_deployment(): void
    {
        $employee = $this->completeEmployee();

        $employee->documents()
            ->where('type', 'drivers_license')
            ->update(['expires_at' => now()->subDay()->toDateString()]);

        $row = $this->assess($employee);

        $this->assertSame(DeploymentReadinessChecker::STATUS_BLOCKED, $row['status']);
        $this->assertGreaterThan(0, $row['blocking_count']);
    }

    /** A missing contract is one of the four `config('onboarding')` blockers. */
    public function test_a_missing_blocking_document_blocks_deployment(): void
    {
        $employee = $this->completeEmployee();
        $employee->documents()->where('type', 'contract')->delete();

        $this->assertSame(
            DeploymentReadinessChecker::STATUS_BLOCKED,
            $this->assess($employee)['status'],
        );
    }

    /**
     * Checked before anything else: someone who has left cannot be deployed
     * however complete their file is.
     */
    public function test_an_inactive_employee_is_blocked_whatever_their_file_says(): void
    {
        $employee = $this->completeEmployee();
        $employee->update(['status' => 'inactive']);

        $row = $this->assess($employee);

        $this->assertSame(DeploymentReadinessChecker::STATUS_BLOCKED, $row['status']);
        $this->assertStringContainsString('inactive', $row['reasons'][0]['detail']);
    }

    // --- What only warns --------------------------------------------------

    /**
     * Inside the renewal window is a warning, not a block — the licence is
     * still valid today, and refusing the deployment would be the system
     * inventing a rule the law does not have.
     */
    public function test_a_licence_inside_its_renewal_window_only_warns(): void
    {
        $employee = $this->completeEmployee();

        $employee->documents()
            ->where('type', 'drivers_license')
            ->update(['expires_at' => now()->addDays(10)->toDateString()]);

        $row = $this->assess($employee);

        $this->assertSame(DeploymentReadinessChecker::STATUS_WARNING, $row['status']);
        $this->assertSame(0, $row['blocking_count']);
    }

    /** A missing résumé is untidy, not unlawful. */
    public function test_a_missing_non_blocking_document_only_warns(): void
    {
        $employee = $this->completeEmployee();
        $employee->documents()->where('type', 'resume')->delete();

        $this->assertSame(
            DeploymentReadinessChecker::STATUS_WARNING,
            $this->assess($employee)['status'],
        );
    }

    public function test_a_complete_file_reports_ready_with_no_reasons(): void
    {
        $row = $this->assess($this->completeEmployee());

        $this->assertSame(DeploymentReadinessChecker::STATUS_READY, $row['status']);
        $this->assertSame([], $row['reasons']);
    }

    // --- The screen is gone; the API still reads the checker ------------

    public function test_the_checker_reports_a_deployed_employee_under_their_client(): void
    {
        $client = Client::create(['code' => 'AAA', 'name' => 'Client A', 'is_active' => true]);

        $deployed = $this->completeEmployee();
        $deployed->update(['employment_category' => 'external', 'client_id' => $client->id]);

        $this->assertSame('Client A', $this->assess($deployed->fresh())['client']);
    }

    public function test_the_screen_is_gone(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/deployment')
            ->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function assess(Employee $employee): array
    {
        return app(DeploymentReadinessChecker::class)
            ->scan(Employee::whereKey($employee->id))
            ->firstOrFail();
    }

    /**
     * An employee whose 201 file leaves nothing to report — every required
     * document present and every expiry comfortably ahead.
     */
    private function completeEmployee(): Employee
    {
        $employee = Employee::factory()->create(['status' => 'active']);

        $documents = [
            'contract' => null,
            'government_id' => null,
            'clearance' => 300,
            'medical' => 300,
            'drivers_license' => 900,
            'resume' => null,
        ];

        foreach ($documents as $type => $expiresInDays) {
            EmployeeDocument::create([
                'employee_id' => $employee->id,
                'type' => $type,
                'title' => ucfirst($type),
                'file_path' => "employees/{$employee->id}/{$type}.txt",
                'file_name' => "{$type}.txt",
                'mime_type' => 'text/plain',
                'file_size' => 32,
                'expires_at' => $expiresInDays === null
                    ? null
                    : now()->addDays($expiresInDays)->toDateString(),
            ]);
        }

        return $employee->fresh();
    }
}
