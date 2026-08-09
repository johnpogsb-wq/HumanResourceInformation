<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $canSeeSensitive = $viewer?->can('viewSensitive', $this->resource) ?? false;

        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,

            'birth_date' => $this->birth_date?->toDateString(),
            'birth_place' => $this->birth_place,
            'gender' => $this->gender,
            'civil_status' => $this->civil_status,
            'nationality' => $this->nationality,
            'religion' => $this->religion,
            'blood_type' => $this->blood_type,

            'email' => $this->email,
            'mobile_number' => $this->mobile_number,
            'phone_number' => $this->phone_number,
            'present_address' => $this->present_address,
            'permanent_address' => $this->permanent_address,

            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_relationship' => $this->emergency_contact_relationship,
            'emergency_contact_number' => $this->emergency_contact_number,

            // Government IDs and pay are restricted to HR and the employee.
            $this->mergeWhen($canSeeSensitive, fn () => [
                'sss_number' => $this->sss_number,
                'philhealth_number' => $this->philhealth_number,
                'pagibig_number' => $this->pagibig_number,
                'tin' => $this->tin,
                'basic_salary' => $this->basic_salary,
                'pay_frequency' => $this->pay_frequency,
                'bank_name' => $this->bank_name,
                'bank_account_number' => $this->bank_account_number,
            ]),

            'department_id' => $this->department_id,
            'position_id' => $this->position_id,
            'supervisor_id' => $this->supervisor_id,
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
            'position' => $this->whenLoaded('position', fn () => [
                'id' => $this->position->id,
                'title' => $this->position->title,
            ]),
            'supervisor' => $this->whenLoaded('supervisor', fn () => [
                'id' => $this->supervisor?->id,
                'full_name' => $this->supervisor?->full_name,
            ]),

            'employment_status' => $this->employment_status,
            'employment_type' => $this->employment_type,
            'date_hired' => $this->date_hired?->toDateString(),
            'date_regularized' => $this->date_regularized?->toDateString(),
            'date_separated' => $this->date_separated?->toDateString(),
            'separation_reason' => $this->separation_reason,

            'drivers_license_number' => $this->drivers_license_number,
            'license_restriction_codes' => $this->license_restriction_codes,
            'license_expiry' => $this->license_expiry?->toDateString(),

            'photo_url' => $this->photo_path ? asset('storage/'.$this->photo_path) : null,
            'status' => $this->status,
            'notes' => $this->notes,
            'has_account' => $this->user_id !== null,

            'documents' => EmployeeDocumentResource::collection($this->whenLoaded('documents')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
