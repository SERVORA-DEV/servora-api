<?php

namespace App\Http\Requests\Business;

use App\Models\SpaBusiness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            // Matches the packages table's unique(spa_business_id, name) index.
            'name' => [
                $isCreate ? 'required' : 'sometimes', 'string', 'max:150',
                Rule::unique('packages', 'name')
                    ->where(fn ($q) => $q->where('spa_business_id', $this->resolvedBusinessId()))
                    ->ignore($this->route('package'), 'uuid'),
            ],
            'description' => 'nullable|string',
            'duration_minutes' => 'nullable|integer|min:1',
            'default_price' => [$isCreate ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'is_active' => 'sometimes|boolean',

            // Line items — which services (and how many of each) make up
            // this package. service_uuid must belong to this same business;
            // PackageService::syncPackageServices silently skips one that
            // doesn't rather than failing the whole save, so this only
            // checks shape, not ownership (the service layer re-checks that).
            'services' => 'sometimes|array',
            'services.*.service_uuid' => 'required_with:services|uuid',
            'services.*.quantity' => 'nullable|integer|min:1',
        ];
    }

    private function resolvedBusinessId(): ?int
    {
        $user = $this->user();
        if (! $user) {
            return null;
        }

        if ($user->role === 'business_owner') {
            return SpaBusiness::where('owner_id', $user->id)->value('id');
        }

        return $user->accountBranch?->branch?->business?->id;
    }
}
