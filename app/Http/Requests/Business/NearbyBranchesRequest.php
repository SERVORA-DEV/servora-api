<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// GET api/spas/nearby (SpaBranchController::nearby).
class NearbyBranchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radius_km' => 'nullable|numeric|min:1|max:100',
            'limit' => 'nullable|integer|min:1|max:200',
        ];
    }
}
