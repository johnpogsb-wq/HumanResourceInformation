<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Files a batch of scanned 201-file documents against the people they name.
 *
 * The third way a filing cabinet gets digitised. The single-document scanner
 * answers "is this Anastasia's licence?" — the employee is already known,
 * because HR opened her record to upload it. Here nobody has been opened yet:
 * a stack of scans comes off the glass and the question is *whose* each one is.
 *
 * It reuses `DocumentScanner` for the reading and its `nameMatches()` for the
 * comparison rather than re-deriving either. A second implementation of the
 * name rules would be a second place for the married-name and truncated-card
 * cases to be decided, and the two would eventually disagree about the same
 * driver — the same reasoning that has DeploymentReadinessChecker reuse the
 * two scanners instead of judging a lapsed licence itself.
 *
 * **Nothing is filed by this class on its own.** `examine()` proposes; a person
 * confirms; `file()` writes what they confirmed. Filing a document under the
 * wrong employee is the error the single-document path refuses outright, and
 * doing it forty at a time unattended would be the same mistake at scale.
 */
class BulkDocumentFiler
{
    /**
     * How the match was made, strongest first.
     *
     * The order is the point. An ID number belongs to one person and OCR reads
     * digits well; a name is shared by thousands and arrives truncated. So a
     * number that matches the 201 file settles the question even when the name
     * reading looks wrong — the same precedence the upload form applies.
     */
    public const BY_NUMBER = 'number';

    public const BY_NAME = 'name';

    public const AMBIGUOUS = 'ambiguous';

    public const UNMATCHED = 'unmatched';

    public function __construct(
        private readonly DocumentScanner $scanner,
        private readonly EmployeeService $employees,
    ) {}

    /**
     * Reads each file and proposes who it belongs to, writing nothing.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  Collection<int, Employee>  $candidates  narrowed by the caller's own scope
     * @return array<int, array<string, mixed>>
     */
    public function examine(array $files, Collection $candidates): array
    {
        return array_values(array_map(
            fn (UploadedFile $file, int $index) => $this->examineOne($file, $index, $candidates),
            $files,
            array_keys($files),
        ));
    }

    /**
     * Writes the assignments a person confirmed.
     *
     * Keyed by the index the review screen showed, so a file whose match was
     * corrected — or cleared — is filed where the person said, not where the
     * scanner guessed. Anything without an employee is skipped rather than
     * filed somewhere plausible.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, array{employee_id: int, type: string, title: string, issued_at: ?string, expires_at: ?string}>  $assignments
     * @return array{filed: int, skipped: int}
     */
    public function file(array $files, array $assignments, Collection $allowed): array
    {
        $filed = 0;
        $skipped = 0;

        foreach ($files as $index => $file) {
            $assignment = $assignments[$index] ?? null;
            $employee = $assignment ? $allowed->firstWhere('id', (int) $assignment['employee_id']) : null;

            // The scope is re-checked here, not trusted from the form: the
            // review screen was built from what this user may see, and the
            // request that follows it must be held to the same list.
            if ($employee === null) {
                $skipped++;

                continue;
            }

            $type = in_array($assignment['type'] ?? null, EmployeeDocument::TYPES, true)
                ? $assignment['type']
                : 'other';

            $this->employees->storeDocument($employee, [
                'type' => $type,
                'title' => $assignment['title'] ?: config("scanner.labels.{$type}", 'Document'),
                'issued_at' => $assignment['issued_at'] ?: null,
                'expires_at' => $assignment['expires_at'] ?: null,
            ], $file);

            $filed++;
        }

        return compact('filed', 'skipped');
    }

    /** @param  Collection<int, Employee>  $candidates */
    private function examineOne(UploadedFile $file, int $index, Collection $candidates): array
    {
        $reading = $this->scanner->scan($file);

        $base = [
            'index' => $index,
            'file_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'scanned' => $reading !== null,
            'type' => $reading['type'] ?? null,
            'title' => $reading['title'] ?? null,
            // The line the type was decided from, for the same reason the
            // single-document panel shows it.
            'heading' => $reading['heading'] ?? null,
            'type_source' => $reading['type_source'] ?? null,
            // Whether the document has already lapsed, so a stack of forty is
            // not a stack of forty chances to file an expired licence quietly.
            'expiry' => $reading['expiry'] ?? null,
            'issued_at' => $reading['issued_at'] ?? null,
            'expires_at' => $reading['expires_at'] ?? null,
            'name_on_document' => $reading['name_on_document'] ?? null,
            'document_number' => $reading['document_number'] ?? null,
        ];

        // A file the scanner cannot read is not a failure — it is a document
        // somebody assigns by hand, exactly as before any of this existed.
        if ($reading === null) {
            return $base + ['employee_id' => null, 'matched_by' => self::UNMATCHED, 'candidates' => []];
        }

        $byNumber = $this->matchOnNumber($reading, $candidates);

        if ($byNumber !== null) {
            return $base + [
                'employee_id' => $byNumber->id,
                'matched_by' => self::BY_NUMBER,
                'candidates' => [$this->describe($byNumber)],
            ];
        }

        $byName = $this->matchOnName($reading['name_on_document'] ?? null, $candidates);

        /*
         * Two people the document could be is not a match. Picking the first
         * would file it under a coin toss, and the whole reason this is a
         * review screen rather than a background job is that the wrong answer
         * here is silent afterwards — nobody goes looking through another
         * person's 201 file for a document that should never have been there.
         */
        if ($byName->count() === 1) {
            return $base + [
                'employee_id' => $byName->first()->id,
                'matched_by' => self::BY_NAME,
                'candidates' => [$this->describe($byName->first())],
            ];
        }

        return $base + [
            'employee_id' => null,
            'matched_by' => $byName->count() > 1 ? self::AMBIGUOUS : self::UNMATCHED,
            'candidates' => $byName->take(5)->map(fn (Employee $e) => $this->describe($e))->values()->all(),
        ];
    }

    /**
     * The strongest evidence available: a number that is already on a 201 file.
     *
     * Compared by containment for the same reason the upload form does it — a
     * scan often carries the caption with the value, and "LICENSE NO.:
     * N01-23-456789" must not read as a different licence.
     *
     * @param  array<string, mixed>  $reading
     * @param  Collection<int, Employee>  $candidates
     */
    private function matchOnNumber(array $reading, Collection $candidates): ?Employee
    {
        $printed = $this->digits($reading['document_number'] ?? null);

        if ($printed === '') {
            return null;
        }

        $matches = $candidates->filter(function (Employee $employee) use ($printed) {
            foreach ([
                $employee->drivers_license_number,
                $employee->sss_number,
                $employee->philhealth_number,
                $employee->pagibig_number,
                $employee->tin,
            ] as $stored) {
                $stored = $this->digits($stored);

                if ($stored !== '' && str_contains($printed, $stored)) {
                    return true;
                }
            }

            return false;
        });

        // One number, two employees means the 201 files themselves disagree.
        // That is a data problem to show, not one to resolve by guessing.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @param  Collection<int, Employee>  $candidates */
    private function matchOnName(?string $printed, Collection $candidates): Collection
    {
        if ($printed === null) {
            return collect();
        }

        return $candidates->filter(
            fn (Employee $employee) => $this->scanner->nameMatches($printed, $employee) === true,
        )->values();
    }

    /** @return array<string, mixed> */
    private function describe(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
        ];
    }

    private function digits(?string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $value)) ?? '';
    }
}
