<?php

namespace App\Http\Requests\Business;

use App\Models\SpaBusiness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SpaBranchCloneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'business_owner';
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('branch'))) {
            $branch = $this->input('branch');
            if (! isset($branch['code']) || is_string($branch['code'])) {
                $branch['code'] = trim($branch['code'] ?? '') ?: null;
            }
            $this->merge(['branch' => $branch]);
        }
    }

    public function rules(): array
    {
        $businessId = SpaBusiness::where('owner_id', $this->user()->id)->value('id');

        return [
            'branch' => 'required|array:branch_name,code,description',
            'branch.branch_name' => 'required|string|max:150',
            'branch.code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('spa_branches', 'code')->where('spa_business_id', $businessId)->whereNull('deleted_at'),
            ],
            'branch.description' => 'nullable|string',
            'copy' => 'required|array:facilities,offerings,hours,booking_rules,programs',
            'copy.facilities' => 'required|boolean',
            'copy.offerings' => 'required|boolean',
            'copy.hours' => 'required|boolean',
            'copy.booking_rules' => 'required|boolean',
            'copy.programs' => 'required|boolean',
            'facility_names' => 'sometimes|array',
            'facility_names.*' => 'required|string|max:150',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->has('copy') && is_array($this->input('copy'))
                && ! collect($this->input('copy'))->contains(fn ($value) => in_array($value, [true, 1, '1'], true))) {
                $validator->errors()->add('copy', 'Choose at least one part of the branch to copy.');
            }
        }];
    }
}
