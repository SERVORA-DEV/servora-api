<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/appointment-service/{uuid}/addons (AppointmentServiceController::addAddon).
class AddAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['addon_uuid' => 'required|uuid'];
    }
}
