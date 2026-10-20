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
            // Matches the packages table's unique(spa_business_id, code) index.
            'code' => [
                'nullable', 'string', 'max:20',
                Rule::unique('packages', 'code')
                    ->where(fn ($q) => $q->where('spa_business_id', $this->resolvedBusinessId()))
                    ->ignore($this->route('package'), 'uuid'),
            ],
            'description' => 'nullable|string',
            'duration_minutes' => 'nullable|integer|min:1',
            'default_price' => [$isCreate ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'is_active' => 'sometimes|boolean',

            // What is in it (App\Support\PackagePlan): services — not menu
            // lengths — each with the minutes it runs in this package, how
            // many, and the add-ons that come with it. duration_minutes above
            // is no longer taken from the form: it is the total of these.
            // Whose services and add-ons they are, and whether each add-on
            // goes with its service, is settled in PackageService.
            //
            // service_variant_uuid is the older shape (a pinned menu length),
            // still accepted in place of service_uuid + duration_minutes.
            'services' => 'sometimes|array',
            'services.*.service_uuid' => 'required_without:services.*.service_variant_uuid|nullable|uuid|distinct',
            'services.*.service_variant_uuid' => 'nullable|uuid',
            'services.*.duration_minutes' => 'nullable|integer|min:1|max:600',
            'services.*.quantity' => 'nullable|integer|min:1|max:20',
            // What this service is worth in the package, per one (blank: its menu price).
            'services.*.price' => 'nullable|numeric|min:0|max:10000000',
            'services.*.addon_uuids' => 'sometimes|array',
            'services.*.addon_uuids.*' => 'uuid|distinct',
        ];
    }

    public function messages(): array
    {
        return [
            'services.*.service_uuid.distinct' => 'A package can hold a service once — use the quantity for more of the same.',
            'services.*.service_uuid.required_without' => 'Each line of a package needs a service.',
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

        return \App\Support\AccountScope::business($user)?->id;
    }
}
