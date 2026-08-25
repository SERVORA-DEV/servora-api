<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class AppointmentPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'package_uuid' => 'required|uuid|exists:packages,uuid',
            'quantity' => 'nullable|integer|min:1',
        ];
    }
}
