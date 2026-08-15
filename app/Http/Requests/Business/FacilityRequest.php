<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validates POST/PATCH /business/facility. Branch ownership itself isn't
// checked here (spa_branch_uuid only needs to exist at all) — FacilityService
// re-resolves it against the caller's own accessible branches and 404s
// otherwise, same split of responsibility as StaffRequest/StaffService.
class FacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'name' => [
                $isCreate ? 'required' : 'sometimes', 'string', 'max:150',
                Rule::unique('facilities', 'name')
                    ->where(fn ($q) => $q->where('spa_branch_id', $this->resolvedBranchId()))
                    ->ignore($this->route('facility'), 'uuid'),
            ],
            'description' => 'nullable|string',
            'type' => ['sometimes', Rule::in(['Room', 'Suite', 'Couples Room', 'VIP Room'])],
            'capacity' => [$isCreate ? 'required' : 'sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['Available', 'Occupied', 'Under Maintenance'])],
            'is_available' => 'sometimes|boolean',

            'amenities' => 'sometimes|array',
            'amenities.*' => 'string|max:50',

            'spa_branch_uuid' => [$isCreate ? 'required' : 'sometimes', 'uuid', 'exists:spa_branches,uuid'],
        ];
    }

    // facilities.(spa_branch_id, name) is a real unique index (see the
    // "merge" migration) — the branch is only known by uuid at validation
    // time, same resolution StaffRequest does for employee_number. On update
    // without a branch change in the payload, falls back to the facility's
    // current branch rather than skipping the check entirely.
    private function resolvedBranchId(): ?int
    {
        $uuid = $this->input('spa_branch_uuid');
        if ($uuid) {
            return \App\Models\SpaBranch::where('uuid', $uuid)->value('id');
        }

        $facilityUuid = $this->route('facility');
        if ($facilityUuid) {
            return \App\Models\Facility::where('uuid', $facilityUuid)->value('spa_branch_id');
        }

        return null;
    }
}
