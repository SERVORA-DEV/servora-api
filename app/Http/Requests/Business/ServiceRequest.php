<?php

namespace App\Http\Requests\Business;

use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBusiness;
use App\Rules\NoEmoji;
use App\Support\AccountScope;
use App\Support\ServiceKind;
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
            // whereNotNull('spa_business_id') keeps this off the admin service
            // templates, which are rows in this same table with a NULL business
            // id — without it, a resolvedBusinessId() of null would make an
            // owner's name collide with the global catalog.
            'name' => [
                $isCreate ? 'required' : 'sometimes', 'string', 'max:150',
                new NoEmoji,
                Rule::unique('services', 'name')
                    ->where(fn ($q) => $q->where('spa_business_id', $this->resolvedBusinessId()))
                    ->whereNotNull('spa_business_id')
                    ->ignore($this->route('service'), 'uuid'),
            ],
            // Business-scoped like `name` above — identifies the service
            // itself (e.g. "BM"), not any one of its duration options.
            'code' => [
                'nullable', 'string', 'max:20',
                Rule::unique('services', 'code')
                    ->where(fn ($q) => $q->where('spa_business_id', $this->resolvedBusinessId()))
                    ->whereNotNull('spa_business_id')
                    ->ignore($this->route('service'), 'uuid'),
            ],
            // Optional here, unlike on a template (see ServiceTemplateRequest,
            // where it's required because it's how owners browse the catalog).
            'category' => ['nullable', Rule::in(config('service_categories'))],
            // Time-based, procedure-based or facility-based (ServiceKind).
            'service_type' => ['sometimes', Rule::in(ServiceKind::ALL)],
            // A facility has to be chosen before the front desk may start it
            // (always true for a facility-based service — ServiceService).
            'facility_required' => ['sometimes', 'boolean'],

            // Provenance for a service started from an admin template.
            // Constrained to actual template rows so it can't be pointed at
            // another business's service; resolved to source_template_id in
            // ServiceService::createService. Never a sync link — the copy is
            // independent from the moment it's saved.
            'source_template_uuid' => [
                'nullable', 'uuid',
                Rule::exists('services', 'uuid')
                    ->where('is_template', true)
                    ->whereNull('deleted_at'),
            ],

            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'home_service_available' => 'sometimes|boolean',

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

            // Every bookable duration/price/points option for
            // this service. `uuid` addresses an existing variant on update
            // (validated for ownership in withValidator below); omitted
            // means "create a new one." Anything not present in the payload
            // on update gets soft-deleted — see ServiceVariantRepository.
            'variants' => [$isCreate ? 'required' : 'sometimes', 'array', 'min:1'],
            'variants.*.uuid' => 'nullable|uuid',
            'variants.*.duration_minutes' => 'required_with:variants|integer|min:1|max:720',
            'variants.*.service_location' => ['sometimes', Rule::in(['In Spa', 'Home Service'])],
            'variants.*.price' => 'required_with:variants|numeric|min:0|max:1000000|decimal:0,2',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $variants = collect($this->input('variants', []));

            $durations = $variants->map(fn ($v) => ($v['service_location'] ?? 'In Spa').':'.($v['duration_minutes'] ?? ''));
            if ($durations->count() !== $durations->unique()->count()) {
                $validator->errors()->add('variants', 'Two options cannot share the same duration.');
            }

            // A procedure-based service has one price: exactly one option,
            // whose "duration" is the estimated time.
            if (Service::hasTypes()) {
                $serviceUuid = $this->route('service');
                $kind = ServiceKind::of($this->input('service_type')
                    ?? ($serviceUuid ? Service::where('uuid', $serviceUuid)->value('service_type') : null));
                $count = $this->has('variants')
                    ? $variants->where('service_location', '!=', 'Home Service')->count()
                    : ($serviceUuid ? ServiceVariant::whereHas('service', fn ($q) => $q->where('uuid', $serviceUuid))->where('service_location', 'In Spa')->count() : 0);
                $homeCount = $this->has('variants') ? $variants->where('service_location', 'Home Service')->count()
                    : ($serviceUuid ? ServiceVariant::whereHas('service', fn ($q) => $q->where('uuid', $serviceUuid))->where('service_location', 'Home Service')->count() : 0);
                if (! ServiceKind::hasLengths($kind) && $homeCount > 1) {
                    $validator->errors()->add('variants', 'A procedure-based service has one home-service price — keep a single home option.');
                }
                if (! ServiceKind::hasLengths($kind) && $count !== 1) {
                    $validator->errors()->add('variants', 'A procedure-based service has one price — keep a single option.');
                }
            }

            if ($this->hasAny(['variants', 'home_service_available', 'service_type', 'facility_required'])) {
                $existing = $this->route('service') ? Service::where('uuid', $this->route('service'))->first() : null;
                if (($this->input('home_service_available') ?? $existing?->home_service_available)
                    && ServiceKind::needsFacility($this->input('service_type') ?? $existing?->service_type, (bool) ($this->input('facility_required') ?? $existing?->facility_required))) {
                    $validator->errors()->add('home_service_available', 'A service requiring a facility cannot be offered at home.');
                }
                if (($this->input('home_service_available') ?? $existing?->home_service_available)
                    && ($this->has('variants') ? $variants->where('service_location', 'Home Service')->isEmpty()
                        : ! $existing?->variants()->where('service_location', 'Home Service')->exists())) {
                    $validator->errors()->add('variants', 'Add home-service lengths and prices before enabling home service.');
                }
            }
            if ($this->has('variants')) {
                if ($variants->where('service_location', '!=', 'Home Service')->isEmpty()) {
                    $validator->errors()->add('variants', 'Keep at least one in-spa option.');
                }
                foreach ($variants as $row) {
                    if (! empty($row['uuid']) && ServiceVariant::where('uuid', $row['uuid'])
                        ->where('service_location', '!=', $row['service_location'] ?? 'In Spa')->exists()) {
                        $validator->errors()->add('variants', 'An existing option cannot change booking location.');
                    }
                }
            }

            // On update, any variant uuid submitted must already belong to
            // this service — 422s instead of the service layer silently
            // ignoring a uuid it doesn't recognize.
            if ($serviceUuid = $this->route('service')) {
                $variantUuids = $variants->pluck('uuid')->filter()->unique();
                if ($variantUuids->isNotEmpty()) {
                    $validCount = ServiceVariant::whereIn('uuid', $variantUuids)
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
            return SpaBusiness::where('owner_id', $user->id)->value('id');
        }

        return AccountScope::business($user)?->id;
    }
}
