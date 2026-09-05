<?php

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeEndorsement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Employee photos: uploaded on create or update, stored on the public disk
 * (unlike 201-file documents, which stay private), and shown wherever the
 * employee's record appears — directory, profile, and back in the edit form
 * as a preview of what is already on file.
 */
class PhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_photo_uploaded_on_create_is_stored_and_served(): void
    {
        Storage::fake('public');

        $this->actingAs($this->hr())->post('/hr/employees', $this->payload([
            'department_id' => $this->department()->id,
            'photo' => UploadedFile::fake()->image('photo.jpg', 300, 300)->size(500),
        ]))->assertRedirect();

        $employee = Employee::firstOrFail();

        $this->assertNotNull($employee->photo_path);
        Storage::disk('public')->assertExists($employee->photo_path);

        $this->actingAs($this->hr())
            ->get("/hr/employees/{$employee->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('employee.data.photo_url', asset('storage/'.$employee->photo_path)),
            );
    }

    public function test_a_photo_uploaded_on_update_replaces_nothing_that_was_there_before(): void
    {
        Storage::fake('public');

        $employee = Employee::factory()->create([
            'department_id' => $this->department()->id,
        ]);

        $this->actingAs($this->hr())
            ->post("/hr/employees/{$employee->id}", $this->payload([
                '_method' => 'put',
                'department_id' => $employee->department_id,
                'photo' => UploadedFile::fake()->image('photo.jpg', 300, 300)->size(500),
            ]))
            ->assertRedirect();

        $employee->refresh();

        $this->assertNotNull($employee->photo_path);
        Storage::disk('public')->assertExists($employee->photo_path);
    }

    /** The edit form needs this to show what is already on file. */
    public function test_the_edit_form_receives_the_current_photo_url(): void
    {
        Storage::fake('public');
        $photo = UploadedFile::fake()->image('photo.jpg', 300, 300)->store('employee-photos', 'public');

        $employee = Employee::factory()->create([
            'department_id' => $this->department()->id,
            'photo_path' => $photo,
        ]);

        $this->actingAs($this->hr())
            ->get("/hr/employees/{$employee->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Employees/Edit')
                ->where('employee.data.photo_url', asset('storage/'.$photo)),
            );
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $this->actingAs($this->hr())->post('/hr/employees', $this->payload([
            'department_id' => $this->department()->id,
            'photo' => UploadedFile::fake()->create('not-a-photo.pdf', 500),
        ]))->assertSessionHasErrors('photo');

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_a_photo_over_two_megabytes_is_rejected(): void
    {
        $this->actingAs($this->hr())->post('/hr/employees', $this->payload([
            'department_id' => $this->department()->id,
            'photo' => UploadedFile::fake()->image('too-big.jpg')->size(2049),
        ]))->assertSessionHasErrors('photo');
    }

    private function department(): Department
    {
        return Department::create(['code' => 'OPS', 'name' => 'Operations']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            // Employees are created by approving a Core 1 endorsement — the
            // form has no other way in. A fresh one per call, because one
            // endorsement becomes one employee and a decided one is closed.
            'endorsement_id' => EmployeeEndorsement::factory()->create()->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'nationality' => 'Filipino',
            'employment_category' => 'internal',
            'employment_status' => 'probationary',
            'employment_type' => 'full_time',
            'date_hired' => '2026-01-15',
            'basic_salary' => 25000,
            'pay_frequency' => 'semi_monthly',
            'status' => 'active',
        ], $overrides);
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
