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
            // Matches the services table's unique(spa_business_id, name)
            // index — checked here so a duplicate 422s cleanly instead of a
            // raw SQL error, same convention as FacilityRequest's
            // name-uniqueness rule.
            'name' => [
                $isCreate ? 'required' : 'sometimes', 'string', 'max:150',
                Rule::unique('services', 'name')
                    ->where(fn ($q) => $q->where('spa_business_id', $this->resolvedBusinessId()))
                    ->ignore($this->route('service'), 'uuid'),
            ],
            // Business-scoped like `name` above — identifies the service
            // itself (e.g. "BM"), not any one of its duration options.
            'code' => [
                'nullable', 'string', 'max:20',
                Rule::unique('services', 'code')
                    ->where(fn ($q) => $q->where('spa_business_id', $this->resolvedBusinessId()))
                    ->ignore($this->route('service'), 'uuid'),
            ],
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',

            // Extension/MIME are both checked here ('image' also runs
            // getimagesize() against the file, rejecting anything that
            // isn't actually a decodable image regardless of its
            // extension); ImageUploadService re-checks MIME + size again
            // before writing to disk as a second layer.
            'image' => [
                'nullable',
                'image',
                'mimes:'.implode(',', config('uploads.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp'])),
                'max:'.config('uploads.max_size_kb', 5120),
            ],

            // Every bookable duration/price/commission/points option for
            // this service. `uuid` addresses an existing variant on update
            // (validated for ownership in withValidator below); omitted
            // means "create a new one." Anything not present in the payload
            // on update gets soft-deleted — see ServiceVariantRepository.
            'variants' => [$isCreate ? 'required' : 'sometimes', 'array', 'min:1'],
            'variants.*.uuid' => 'nullable|uuid',
            'variants.*.duration_minutes' => 'required_with:variants|integer|min:1',
            'variants.*.price' => 'required_with:variants|numeric|min:0',
            'variants.*.commission_amount' => 'nullable|numeric|min:0',
            'variants.*.loyalty_points' => 'nullable|integer|min:0',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $variants = collect($this->input('variants', []));

            $durations = $variants->pluck('duration_minutes')->filter(fn ($d) => $d !== null);
            if ($durations->count() !== $durations->unique()->count()) {
                $validator->errors()->add('variants', 'Two options cannot share the same duration.');
            }

            // On update, any variant uuid submitted must already belong to
            // this service — 422s instead of the service layer silently
            // ignoring a uuid it doesn't recognize.
            if ($serviceUuid = $this->route('service')) {
                $variantUuids = $variants->pluck('uuid')->filter()->unique();
                if ($variantUuids->isNotEmpty()) {
                    $validCount = \App\Models\ServiceVariant::whereIn('uuid', $variantUuids)
                        ->whereHas('service', fn ($q) => $q->where('uuid', $serviceUuid))
                        ->count();
                    if ($validCount !== $variantUuids->count()) {
                        $validator->errors()->add('variants', 'One or more options do not belong to this service.');
                    }
                }
            }
        });
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

        return $user->staff?->branch?->business?->id;
    }
}
