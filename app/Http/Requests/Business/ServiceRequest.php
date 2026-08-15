<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            // Matches the services table's unique(spa_business_id, name,
            // duration_minutes) index — checked here so a duplicate 422s
            // cleanly instead of a raw SQL error, same convention as
            // FacilityRequest's name-uniqueness rule.
            'name' => [
                $isCreate ? 'required' : 'sometimes', 'string', 'max:150',
                Rule::unique('services', 'name')
                    ->where(fn ($q) => $q
                        ->where('spa_business_id', $this->resolvedBusinessId())
                        ->where('duration_minutes', $this->resolvedDurationMinutes()))
                    ->ignore($this->route('service'), 'uuid'),
            ],
            'description' => 'nullable|string',
            'duration_minutes' => [$isCreate ? 'required' : 'sometimes', 'integer', 'min:1'],
            'default_price' => [$isCreate ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'default_commission_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
        ];
    }

    private function resolvedBusinessId(): ?int
    {
        $user = $this->user();
        if (! $user) {
            return null;
        }

        if ($user->role === 'business_owner') {
            return \App\Models\SpaBusiness::where('owner_id', $user->id)->value('id');
        }

        return $user->accountBranch?->branch?->business?->id;
    }

    // On a partial update that doesn't touch duration_minutes, fall back to
    // the service's current value rather than checking against null — same
    // reasoning as FacilityRequest::resolvedBranchId.
    private function resolvedDurationMinutes(): ?int
    {
        if ($this->has('duration_minutes')) {
            return $this->input('duration_minutes');
        }

        $uuid = $this->route('service');
        if (! $uuid) {
            return null;
        }

        return \App\Models\Service::where('uuid', $uuid)->value('duration_minutes');
    }
}
