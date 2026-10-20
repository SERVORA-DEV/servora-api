<?php

namespace App\Http\Requests\Business;

use App\Models\Addon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// An add-on on the owner's menu: its name, price, the time it adds, and where
// it is available — one or more service categories, and either every service
// in them or only the ones ticked. Which ticked services really are this
// business's and in those categories is settled in AddonService.
class AddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // The tables come with a migration the owner runs: until then there is
    // nothing to validate against.
    protected function prepareForValidation(): void
    {
        if (! Addon::ready()) {
            abort(response()->json(['message' => 'Add-ons are not set up on this server yet.'], 422));
        }
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        $need = $isCreate ? 'required' : 'sometimes';

        return [
            'name' => [
                $need, 'string', 'max:150',
                Rule::unique('addons', 'name')
                    ->where(fn ($q) => $q->where('spa_business_id', $this->resolvedBusinessId())->whereNull('deleted_at'))
                    ->ignore($this->route('addon'), 'uuid'),
            ],
            'description' => 'nullable|string|max:1000',
            'price' => [$need, 'numeric', 'min:0', 'max:9999999'],
            // What it adds to the service's time — 0 for one that takes none.
            'duration_minutes' => [$need, 'integer', 'min:0', 'max:600'],
            'categories' => [$need, 'array', 'min:1'],
            'categories.*' => ['string', 'distinct', Rule::in(config('service_categories'))],
            // Every service in those categories, or only service_uuids.
            'all_services' => 'sometimes|boolean',
            'service_uuids' => 'sometimes|array',
            'service_uuids.*' => 'uuid',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'categories.required' => 'Choose at least one category this add-on is available for.',
            'categories.min' => 'Choose at least one category this add-on is available for.',
            'name.unique' => 'There is already an add-on with this name.',
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

        return \App\Support\AccountScope::business($user)?->id;
    }
}
