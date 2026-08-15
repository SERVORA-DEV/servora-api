<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'employee_number' => $this->employee_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_name' => $this->middle_name,
            'suffix' => $this->suffix,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date,
            'hire_date' => $this->hire_date,
            'role' => $this->role,
            'employment_type' => $this->employment_type,
            'status' => $this->status,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_number' => $this->emergency_contact_number,
            'notes' => $this->notes,
            'spa_branch_uuid' => $this->branch?->uuid,
            'branch_name' => $this->branch?->branch_name,

            'created_at' => $this->created_at,
        ];
    }
}
