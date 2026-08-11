<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\EmployeeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Gives employees the 201-file documents a transport company actually keeps,
 * with a realistic spread of expiry dates — mostly in date, a few inside their
 * renewal window, one or two already lapsed — so the Credentials screen has
 * something to show.
 */
class CredentialSeeder extends Seeder
{
    /**
     * Days from today. Negative is already expired. The spread is deliberate:
     * most people are fine, which is what makes the handful that are not worth
     * surfacing.
     */
    private const LICENCE_OFFSETS = [420, 300, 210, 180, 95, 74, 45, 28, 12, -6];

    private const MEDICAL_OFFSETS = [300, 240, 180, 150, 120, 60, 38, 20, -3];

    public function run(): void
    {
        if (EmployeeDocument::exists()) {
            return;
        }

        $employees = Employee::orderBy('id')->get();

        if ($employees->isEmpty()) {
            return;
        }

        foreach ($employees as $index => $employee) {
            $this->document(
                $employee,
                'drivers_license',
                "Professional Driver's Licence",
                self::LICENCE_OFFSETS[$index % count(self::LICENCE_OFFSETS)],
            );

            $this->document(
                $employee,
                'medical',
                'Annual Medical Certificate',
                self::MEDICAL_OFFSETS[$index % count(self::MEDICAL_OFFSETS)],
            );

            // Not everyone carries a clearance, and it never expires for some
            // roles — a document with no expiry should stay off the screen.
            if ($index % 3 === 0) {
                $this->document($employee, 'clearance', 'NBI Clearance', 90 - ($index % 5) * 30);
            }

            if ($index % 4 === 0) {
                $this->document($employee, 'resume', 'Curriculum Vitae', null);
            }
        }
    }

    private function document(
        Employee $employee,
        string $type,
        string $title,
        ?int $expiresInDays,
    ): void {
        $path = "employees/{$employee->id}/{$type}.txt";

        // A real file behind the row, so the download link works in a demo
        // instead of 404-ing on a path that was never written.
        Storage::disk(EmployeeService::DOCUMENT_DISK)->put(
            $path,
            "Placeholder for {$title} — {$employee->full_name}.",
        );

        EmployeeDocument::create([
            'employee_id' => $employee->id,
            'type' => $type,
            'title' => $title,
            'file_path' => $path,
            'file_name' => "{$type}.txt",
            'mime_type' => 'text/plain',
            'file_size' => 64,
            'issued_at' => now()->subYear()->toDateString(),
            'expires_at' => $expiresInDays === null
                ? null
                : now()->addDays($expiresInDays)->toDateString(),
        ]);
    }
}
