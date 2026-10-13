<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validates POST /business/catalog-requests — a manager asking the owner for
// a service or package the catalog doesn't have.
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
            'kind' => 'required|string|in:service,package',
            'name' => 'required|string|min:2|max:120',
            // The same list a service itself is held to (ServiceRequest).
            'category' => ['nullable', Rule::in(config('service_categories'))],
            'details' => 'nullable|string|max:1000',
        ];
    }
}
