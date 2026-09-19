<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            // services has unique(spa_business_id, name), but that index can't
            // constrain templates against each other: they carry a NULL
            // business id and MySQL counts every NULL as distinct. So template
            // name uniqueness is enforced here, scoped the same way the
            // repository scopes its queries.
            'name' => [
                $isCreate ? 'required' : 'sometimes', 'string', 'max:150',
                Rule::unique('services', 'name')
                    ->whereNull('spa_business_id')
                    ->whereNull('deleted_at')
                    ->ignore($this->route('uuid'), 'uuid'),
            ],

            // Same business-scoped-index caveat as `name` above.
            'code' => [
                'nullable', 'string', 'max:20',
                Rule::unique('services', 'code')
                    ->whereNull('spa_business_id')
                    ->whereNull('deleted_at')
                    ->ignore($this->route('uuid'), 'uuid'),
            ],

            // Required on a template (unlike an owner's own service, where it
            // stays optional) — the category is how owners browse the catalog,
            // so an uncategorised template would be effectively unreachable.
            'category' => [$isCreate ? 'required' : 'sometimes', Rule::in(config('service_categories'))],

            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',

            // The suggested duration/price options — same shape as
            // ServiceRequest's, since these rows become service_variants and an
            // adopted copy reuses them verbatim.
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
            // service_variants has unique(service_id, duration_minutes) —
            // surface a clash here rather than as a raw SQL error, same as
            // ServiceRequest does.
            $durations = collect($this->input('variants', []))
                ->pluck('duration_minutes')
                ->filter(fn ($d) => $d !== null);

            if ($durations->count() !== $durations->unique()->count()) {
                $validator->errors()->add('variants', 'Two options cannot share the same duration.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        // Store a blank code as NULL, not '' — two templates with '' would
        // collide on the uniqueness rule above.
        if ($this->has('code')) {
            $this->merge(['code' => trim((string) $this->input('code')) ?: null]);
        }
    }
}
