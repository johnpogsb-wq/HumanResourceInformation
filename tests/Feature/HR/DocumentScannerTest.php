<?php

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\DocumentScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The AI document scanner reads a 201-file upload and proposes the fields.
 *
 * Everything it returns is treated as untrusted: the document type is checked
 * against EmployeeDocument::TYPES, dates are re-parsed, and the name check is
 * done in PHP rather than asked of the model. These tests cover that layer —
 * `read()` is stubbed, so nothing here needs an API key or a network call.
 */
class DocumentScannerTest extends TestCase
{
    use RefreshDatabase;

    // --- Gating -----------------------------------------------------------

    public function test_the_scanner_is_off_without_an_api_key(): void
    {
        config(['scanner.api_key' => null]);

        $this->assertFalse(app(DocumentScanner::class)->isEnabled());
    }

    /** A PDF or DOCX upload skips the scanner rather than failing. */
    public function test_a_non_image_is_not_scanned(): void
    {
        $scanner = app(DocumentScanner::class);

        $this->assertFalse($scanner->canScan(UploadedFile::fake()->create('contract.pdf', 200)));
        $this->assertTrue($scanner->canScan(UploadedFile::fake()->image('licence.jpg')));
    }

    public function test_an_oversized_image_is_refused_before_it_costs_a_request(): void
    {
        config(['scanner.max_bytes' => 1024]);

        $this->assertFalse(
            app(DocumentScanner::class)->canScan(UploadedFile::fake()->image('huge.jpg')->size(50)),
        );
    }

    // --- Reading a licence ------------------------------------------------

    public function test_it_fills_the_fields_from_a_drivers_licence(): void
    {
        $employee = $this->employee('Jane', 'Doe');

        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'title' => "Non-Professional Driver's Licence",
            'document_number' => 'A01-23-456789',
            'issued_at' => '2023-03-15',
            'expires_at' => '2027-03-15',
            'name_on_document' => 'DOE, JANE',
            'confidence' => 'high',
            'note' => null,
        ])->scan(UploadedFile::fake()->image('licence.jpg'), $employee);

        $this->assertSame('drivers_license', $fields['type']);
        $this->assertSame('A01-23-456789', $fields['document_number']);
        $this->assertSame('2027-03-15', $fields['expires_at']);
        $this->assertTrue($fields['name_matches']);
    }

    /** NBI clearances print "VALID UNTIL" rather than an expiry label. */
    public function test_it_reads_an_nbi_clearance_valid_until_date(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'clearance',
            'title' => 'NBI Clearance',
            'document_number' => 'M322CJYE79-L13108748',
            'issued_at' => '2024-07-31',
            'expires_at' => '2025-07-31',
            'name_on_document' => 'MADIGAS, JANE GAWILAN',
            'confidence' => 'high',
            'note' => null,
        ])->scan(UploadedFile::fake()->image('nbi.jpg'), $this->employee('Jane', 'Madigas'));

        $this->assertSame('clearance', $fields['type']);
        $this->assertSame('2025-07-31', $fields['expires_at']);
        $this->assertTrue($fields['name_matches']);
    }

    // --- Guarding against a bad reading -----------------------------------

    /** A type outside EmployeeDocument::TYPES never reaches the form. */
    public function test_an_unrecognised_document_type_is_dropped(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'birth_certificate',
            'title' => 'Something',
            'document_number' => null,
            'issued_at' => null,
            'expires_at' => null,
            'name_on_document' => null,
            'confidence' => 'high',
            'note' => null,
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertNull($fields['type']);
    }

    public function test_an_unparseable_date_becomes_null_rather_than_crashing(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'medical',
            'title' => 'Medical Certificate',
            'document_number' => null,
            'issued_at' => 'YYYY/MM/DD',
            'expires_at' => 'not a date',
            'name_on_document' => null,
            'confidence' => 'low',
            'note' => 'Sample document.',
        ])->scan(UploadedFile::fake()->image('sample.jpg'));

        $this->assertNull($fields['issued_at']);
        $this->assertNull($fields['expires_at']);
        $this->assertSame('low', $fields['confidence']);
    }

    /** The check that catches filing a document under the wrong person. */
    public function test_a_name_that_does_not_match_the_employee_is_flagged(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'government_id',
            'title' => 'PhilSys National ID',
            'document_number' => '1234-5678-9101-1213',
            'issued_at' => '2019-06-14',
            'expires_at' => null,
            'name_on_document' => 'DELA CRUZ, JUANA MARTINEZ',
            'confidence' => 'high',
            'note' => null,
        ])->scan(UploadedFile::fake()->image('id.jpg'), $this->employee('Pedro', 'Santos'));

        $this->assertFalse($fields['name_matches']);
        $this->assertSame('DELA CRUZ, JUANA MARTINEZ', $fields['name_on_document']);
    }

    /** Nothing to compare against is not the same as a mismatch. */
    public function test_a_missing_name_reports_null_not_false(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'certificate',
            'title' => 'Training Certificate',
            'document_number' => null,
            'issued_at' => null,
            'expires_at' => null,
            'name_on_document' => null,
            'confidence' => 'medium',
            'note' => null,
        ])->scan(UploadedFile::fake()->image('cert.jpg'), $this->employee('Jane', 'Doe'));

        $this->assertNull($fields['name_matches']);
    }

    public function test_a_failed_call_returns_null_so_the_upload_still_works(): void
    {
        $fields = $this->scannerReturning(null)->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertNull($fields);
    }

    // --- The endpoint ------------------------------------------------------

    public function test_the_endpoint_404s_when_the_scanner_is_off(): void
    {
        config(['scanner.api_key' => null]);

        $this->actingAs($this->hr())
            ->post("/hr/employees/{$this->employee()->id}/documents/scan", [
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertNotFound();
    }

    /** Same gate as the upload it precedes. */
    public function test_an_employee_cannot_scan_a_document(): void
    {
        $this->actingAs(User::factory()->create())
            ->post("/hr/employees/{$this->employee()->id}/documents/scan", [
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertForbidden();
    }

    public function test_the_show_page_hides_the_scanner_when_it_is_off(): void
    {
        config(['scanner.api_key' => null]);

        $this->actingAs($this->hr())
            ->get("/hr/employees/{$this->employee()->id}")
            ->assertInertia(fn ($page) => $page->where('can.scanDocuments', false));
    }

    public function test_the_show_page_offers_the_scanner_to_hr_when_it_is_on(): void
    {
        $this->actingAs($this->hr())
            ->get("/hr/employees/{$this->employee()->id}")
            ->assertInertia(fn ($page) => $page->where('can.scanDocuments', true));
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['scanner.api_key' => 'test-key']);
    }

    // --- Helpers -----------------------------------------------------------

    /** A scanner whose one API-touching method is replaced by a fixed answer. */
    private function scannerReturning(?array $reading): DocumentScanner
    {
        return new class($reading) extends DocumentScanner
        {
            public function __construct(private readonly ?array $reading)
            {
                parent::__construct();
            }

            protected function read(UploadedFile $file): ?array
            {
                return $this->reading;
            }
        };
    }

    private function employee(string $first = 'Juan', string $last = 'Dela Cruz'): Employee
    {
        return Employee::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'department_id' => Department::firstOrCreate(
                ['code' => 'OPS'],
                ['name' => 'Operations'],
            )->id,
        ]);
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
