<?php

namespace App\Http\Requests\Business;

use App\Rules\NoEmoji;
use App\Models\Service;
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
                new NoEmoji(),
                // A deleted service no longer holds its name or code (the indexes are over live rows only).
                Rule::unique('services', 'name')
                    ->where(fn ($q) => $q->where('spa_business_id', $this->resolvedBusinessId()))
                    ->whereNotNull('spa_business_id')
                    ->whereNull('deleted_at')
                    ->ignore($this->route('service'), 'uuid'),
            ],
            // Business-scoped like `name` above — identifies the service
            // itself (e.g. "BM"), not any one of its duration options.
            'code' => [
                'nullable', 'string', 'max:20',
                Rule::unique('services', 'code')
                    ->where(fn ($q) => $q->where('spa_business_id', $this->resolvedBusinessId()))
                    ->whereNotNull('spa_business_id')
                    ->whereNull('deleted_at')
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
            'variants.*.price' => 'required_with:variants|numeric|min:0|max:1000000|decimal:0,2',
            // Home service (App\Support\HomePrices): the length's price at the client's home
            // (empty = its price above) and whether it is offered only for home visits.
            'variants.*.home_price' => 'nullable|numeric|min:0|max:1000000|decimal:0,2',
            'variants.*.home_only' => 'sometimes|boolean',

            // Done by several staff at once: one part per person ("Upper body", "Lower body"),
            // each with its share of the commission. Empty: one person does it. Checked as a
            // whole (2-4 parts, shares total 100) in withValidator.
            'parts' => 'sometimes|array|max:4',
            'parts.*.uuid' => 'nullable|uuid',
            'parts.*.name' => ['required', 'string', 'max:60', 'distinct:ignore_case', new NoEmoji()],
            'parts.*.share_percent' => 'required|numeric|min:1|max:99|decimal:0,2',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $variants = collect($this->input('variants', []));

            $parts = collect($this->input('parts', []));
            // Not saved anywhere until the service_parts migration has run: say so rather than drop them.
            if ($parts->isNotEmpty() && ! Service::hasParts()) {
                $validator->errors()->add('parts', 'Services done by several staff at once aren\'t available yet. Save it as a one-person service for now.');
            } elseif ($parts->isNotEmpty()) {
                $serviceUuid = $this->route('service');
                $kind = ServiceKind::of($this->input('service_type')
                    ?? ($serviceUuid ? Service::where('uuid', $serviceUuid)->value('service_type') : null));
                if (! ServiceKind::needsTherapist($kind)) {
                    $validator->errors()->add('parts', 'A facility-only service has no staff, so it has no parts.');
                } elseif ($parts->count() < 2) {
                    $validator->errors()->add('parts', 'A service done by several staff needs at least 2 parts.');
                } else {
                    $total = round($parts->sum(fn ($p) => (float) ($p['share_percent'] ?? 0)), 2);
                    if (abs($total - 100) > 0.001) {
                        $validator->errors()->add('parts', 'The parts\' commission shares must add up to 100% (now ' . rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.') . '%).');
                    }
                }
                $partUuids = $parts->pluck('uuid')->filter()->unique();
                if ($partUuids->isNotEmpty() && Service::hasParts()) {
                    $owned = $serviceUuid
                        ? \App\Models\ServicePart::whereIn('uuid', $partUuids)->whereHas('service', fn ($q) => $q->where('uuid', $serviceUuid))->count()
                        : 0;
                    if ($owned !== $partUuids->count()) {
                        $validator->errors()->add('parts', 'One or more parts do not belong to this service.');
                    }
                }
            }

            // A length only for home visits needs a service that can be done at home, and the
            // spa still needs a length of its own to book.
            $homeOnly = $variants->filter(fn ($v) => ! empty($v['home_only']));
            if ($homeOnly->isNotEmpty()) {
                if (! \App\Support\HomePrices::ready()) {
                    $validator->errors()->add('variants', 'Lengths only for home visits aren\'t available yet. Save it without them for now.');
                } else {
                    $serviceUuid = $this->route('service');
                    $home = $this->has('home_service_available')
                        ? $this->boolean('home_service_available')
                        : ($serviceUuid ? (bool) Service::where('uuid', $serviceUuid)->value('home_service_available') : false);
                    if (! $home) {
                        $validator->errors()->add('variants', 'Turn on "Available for home service" to add lengths only for home visits.');
                    } elseif ($homeOnly->count() === $variants->count()) {
                        $validator->errors()->add('variants', 'Keep at least one length for the spa. Lengths only for home visits come on top.');
                    }
                }
            }

            $durations = $variants->pluck('duration_minutes')->filter(fn ($d) => $d !== null);
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
                    ? $variants->count()
                    : ($serviceUuid ? \App\Models\ServiceVariant::whereHas('service', fn ($q) => $q->where('uuid', $serviceUuid))->count() : 0);
                if (! ServiceKind::hasLengths($kind) && $count !== 1) {
                    $validator->errors()->add('variants', 'A procedure-based service has one price — keep a single option.');
                }
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

    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a service with this name.',
            'code.unique' => 'Another service already uses this code.',
            'parts.*.name.required' => 'Give each part a name, e.g. Upper body.',
            'parts.*.name.distinct' => 'Two parts cannot have the same name.',
            'parts.*.share_percent.required' => 'Give each part its share of the commission.',
            'parts.max' => 'A service can be split between at most 4 staff.',
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
