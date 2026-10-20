<?php

namespace App\Http\Requests\Business;

use App\Support\ServiceKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validates POST /business/catalog-requests — a manager asking the owner for
// a service, package or add-on the catalog doesn't have.
//
// The request carries the form the owner would fill in to create it
// (`payload`, camelCase as the site holds it), so the owner reads the manager's
// proposal and the Add window opens with it filled in. Only the keys listed
// here are kept (validated() drops the rest).
//
// Who may ask, and whether it already exists, is checked in
// CatalogRequestService, not here.
class CatalogRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => 'required|string|in:service,package,addon',
            'name' => 'required|string|min:2|max:120',
            // The same list a service itself is held to (ServiceRequest).
            'category' => ['nullable', Rule::in(config('service_categories'))],
            'details' => 'nullable|string|max:1000',
            'payload' => 'nullable|array',
        ] + $this->payloadRules();
    }

    private function payloadRules(): array
    {
        $uuid = 'uuid';

        return match ($this->input('kind')) {
            'service' => [
                'payload.serviceType' => ['nullable', Rule::in(ServiceKind::ALL)],
                'payload.facilityRequired' => 'nullable|boolean',
                'payload.homeServiceAvailable' => 'nullable|boolean',
                'payload.code' => 'nullable|string|max:20',
                'payload.description' => 'nullable|string|max:2000',
                'payload.variants' => 'nullable|array|min:1|max:20',
                'payload.variants.*.durationMinutes' => 'required|integer|min:1|max:1440',
                'payload.variants.*.price' => 'required|numeric|min:0|max:10000000',
            ],
            'package' => [
                'payload.code' => 'nullable|string|max:20',
                'payload.description' => 'nullable|string|max:2000',
                'payload.defaultPrice' => 'nullable|numeric|min:0|max:10000000',
                'payload.services' => 'nullable|array|min:1|max:40',
                'payload.services.*.serviceId' => "required|{$uuid}",
                'payload.services.*.durationMinutes' => 'required|integer|min:1|max:1440',
                'payload.services.*.quantity' => 'required|integer|min:1|max:99',
                'payload.services.*.price' => 'nullable|numeric|min:0|max:10000000',
                'payload.services.*.addonIds' => 'nullable|array|max:20',
                'payload.services.*.addonIds.*' => $uuid,
            ],
            'addon' => [
                'payload.description' => 'nullable|string|max:2000',
                'payload.price' => 'nullable|numeric|min:0|max:10000000',
                'payload.durationMinutes' => 'nullable|integer|min:0|max:1440',
                'payload.categories' => 'nullable|array|max:20',
                'payload.categories.*' => [Rule::in(config('service_categories'))],
                'payload.allServices' => 'nullable|boolean',
                'payload.serviceIds' => 'nullable|array|max:200',
                'payload.serviceIds.*' => $uuid,
            ],
            default => [],
        };
    }
}
