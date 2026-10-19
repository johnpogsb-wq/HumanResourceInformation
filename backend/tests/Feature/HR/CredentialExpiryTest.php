<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\CredentialExpiryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The scanner has no screen of its own any more; it still feeds Deployment
 * Readiness, the dashboard, the topbar indicator, the bell and the
 * integration API, so its rules are tested directly.
 */
class CredentialExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_licence_inside_its_window_is_reported(): void
    {
        // Driver's licences warn 60 days out.
        $this->document(expiresInDays: 30, type: 'drivers_license');

        $rows = $this->scan();

        $this->assertCount(1, $rows);
        $this->assertSame(CredentialExpiryScanner::STATUS_EXPIRING, $rows[0]['status']);
        $this->assertSame(30, $rows[0]['days_remaining']);
        $this->assertTrue($rows[0]['blocking']);
    }

    public function test_a_licence_beyond_its_window_is_left_alone(): void
    {
        $this->document(expiresInDays: 120, type: 'drivers_license');

        $this->assertCount(0, $this->scan());
    }

    public function test_each_type_gets_its_own_lead_time(): void
    {
        // 45 days out: inside the licence window (60), outside a certificate's (30).
        $this->document(expiresInDays: 45, type: 'drivers_license');
        $this->document(expiresInDays: 45, type: 'certificate');

        $rows = $this->scan();

        $this->assertCount(1, $rows);
        $this->assertSame('drivers_license', $rows[0]['type']);
    }

    public function test_an_expired_document_is_reported_as_overdue(): void
    {
        $this->document(expiresInDays: -10, type: 'medical');

        $rows = $this->scan();

        $this->assertSame(CredentialExpiryScanner::STATUS_EXPIRED, $rows[0]['status']);
        $this->assertSame(-10, $rows[0]['days_remaining']);
    }

    public function test_expired_documents_sort_above_merely_expiring_ones(): void
    {
        $this->document(expiresInDays: 5, type: 'medical');
        $this->document(expiresInDays: -1, type: 'medical');

        $rows = $this->scan();

        $this->assertSame(CredentialExpiryScanner::STATUS_EXPIRED, $rows[0]['status']);
        $this->assertSame(CredentialExpiryScanner::STATUS_EXPIRING, $rows[1]['status']);
    }

    public function test_a_document_with_no_expiry_is_never_reported(): void
    {
        // A resume does not lapse.
        $this->document(expiresInDays: null, type: 'resume');

        $this->assertCount(0, $this->scan());
    }

    public function test_a_non_blocking_document_is_not_flagged_as_stopping_work(): void
    {
        $this->document(expiresInDays: 10, type: 'certificate');

        $this->assertFalse($this->scan()[0]['blocking']);
    }

    public function test_the_topbar_count_matches_what_the_user_may_see(): void
    {
        $user = User::factory()->create();
        $own = Employee::factory()->create(['user_id' => $user->id]);

        $this->document(expiresInDays: 10, type: 'medical', employee: $own);
        $this->document(expiresInDays: 10, type: 'medical');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('expiringCredentials', 1));

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('expiringCredentials', 2));
    }

    public function test_the_screen_is_gone(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/credentials')
            ->assertNotFound();
    }

    private function scan(): Collection
    {
        return app(CredentialExpiryScanner::class)->scan(EmployeeDocument::query());
    }

    private function document(
        ?int $expiresInDays,
        string $type,
        ?Employee $employee = null,
    ): EmployeeDocument {
        return EmployeeDocument::create([
            'employee_id' => ($employee ?? Employee::factory()->create())->id,
            'type' => $type,
            'title' => ucfirst(str_replace('_', ' ', $type)),
            'file_path' => "documents/{$type}.pdf",
            'file_name' => "{$type}.pdf",
            'expires_at' => $expiresInDays === null
                ? null
                : now()->addDays($expiresInDays)->toDateString(),
        ]);
    }
}
