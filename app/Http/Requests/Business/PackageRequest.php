<?php

namespace App\Http\Requests\Business;

use App\Models\Package;
use App\Models\Service;
use App\Models\SpaBusiness;
use App\Rules\NoEmoji;
use App\Support\AccountScope;
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
            // Matches the packages table's unique(spa_business_id, name) index, which is over
            // live rows only: a deleted package no longer holds its name or code.
            'name' => [
                $isCreate ? 'required' : 'sometimes', 'string', 'max:150',
                new NoEmoji,
                Rule::unique('packages', 'name')
                    ->where(fn ($q) => $q->where('spa_business_id', $this->resolvedBusinessId()))
                    ->whereNull('deleted_at')
                    ->ignore($this->route('package'), 'uuid'),
            ],
            // Matches the packages table's unique(spa_business_id, code) index.
            'code' => [
                'nullable', 'string', 'max:20',
                Rule::unique('packages', 'code')
                    ->where(fn ($q) => $q->where('spa_business_id', $this->resolvedBusinessId()))
                    ->whereNull('deleted_at')
                    ->ignore($this->route('package'), 'uuid'),
            ],
            'description' => 'nullable|string',
            'duration_minutes' => 'nullable|integer|min:1|max:720',
            'default_price' => [$isCreate ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'home_service_available' => 'sometimes|boolean',
            'home_price' => 'nullable|required_if:home_service_available,true|numeric|min:0|max:1000000|decimal:0,2',
            'services.*.home_duration_minutes' => 'nullable|required_if:home_service_available,true|integer|min:1|max:600',
            'services.*.home_price' => 'nullable|numeric|min:0|max:1000000|decimal:0,2',
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
            // A package for several clients (a Couples Massage: 2), and which of them get each
            // service ([1] = Client A, [2] = Client B, [1, 2] = both; left out: every client).
            // Every client must get something (withValidator).
            'client_count' => 'sometimes|integer|min:1|max:4',
            'services.*.client_slots' => 'nullable|array',
            'services.*.client_slots.*' => 'integer|min:1|max:4',
            'services.*.addon_uuids.*' => 'uuid|distinct',
        ];
    }

    public function withValidator($validator): void
    {
        // A package for several clients: each client needs something, and only the clients it is for.
        $validator->after(function ($validator) {
            if (! \App\Models\Package::hasSharing() || ! $this->has('services')) {
                return;
            }
            $existing = $this->route('package') ? \App\Models\Package::where('uuid', $this->route('package'))->value('client_count') : null;
            $clients = max(1, (int) ($this->input('client_count') ?? $existing ?? 1));
            $services = collect($this->input('services', []));
            foreach ($services as $row) {
                foreach ((array) ($row['client_slots'] ?? []) as $slot) {
                    if ((int) $slot > $clients) {
                        $validator->errors()->add('services', \App\Models\Package::slotName((int) $slot) . " isn't in this package — it is for {$clients} " . ($clients === 1 ? 'client' : 'clients') . '.');

                        return;
                    }
                }
            }
            if ($clients < 2) {
                return;
            }
            foreach (range(1, $clients) as $slot) {
                $has = $services->contains(fn ($row) => empty($row['client_slots']) || in_array($slot, array_map('intval', (array) $row['client_slots']), true));
                if (! $has) {
                    $validator->errors()->add('services', \App\Models\Package::slotName($slot) . ' has no service in this package.');
                }
            }
        });

        // A home-service package: every service in it needs reviewed home options and a home length.
        $validator->after(function ($validator) {
            $existing = $this->route('package') ? Package::where('uuid', $this->route('package'))->first() : null;
            if (! ($this->input('home_service_available') ?? $existing?->home_service_available)) {
                return;
            }
            $items = $this->input('services');
            if ($items === null) {
                if (! $existing?->homeBookable()) {
                    $validator->errors()->add('services', 'Configure home-service lengths and prices before enabling this package.');
                }

                return;
            }
            if (! $items) {
                $validator->errors()->add('services', 'A home-service package needs services.');
            }
            foreach ($items as $item) {
                $service = Service::where('spa_business_id', $this->resolvedBusinessId())->where('uuid', $item['service_uuid'] ?? '')->with('variants')->first();
                if (! $service?->home_service_available || $service->facility_required
                    || ! $service->variants->contains(fn ($v) => $v->service_location === 'Home Service')
                    || empty($item['home_duration_minutes'])) {
                    $validator->errors()->add('services', 'Every included service needs reviewed home-service options and a home duration.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'services.*.service_uuid.distinct' => 'A package can hold a service once — use the quantity for more of the same.',
            'services.*.service_uuid.required_without' => 'Each line of a package needs a service.',
            'name.unique' => 'You already have a package with this name.',
            'code.unique' => 'Another package already uses this code.',
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

        return AccountScope::business($user)?->id;
    }
}
